<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\AiDeploymentAssistJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\AiDeploymentAttempt;
use App\Models\ApplicationDeploymentQueue;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Services\Ai\DeploymentContextCollector;
use App\Services\Ai\DeploymentPlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpAiAssistantFixture($this);
    Http::preventStrayRequests();
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'failed-deploy', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);
});

function pendingAiAttempt(object $test, array $attributes = []): AiDeploymentAttempt
{
    return AiDeploymentAttempt::factory()->create(array_merge([
        'team_id' => $test->team->id,
        'application_id' => $test->application->id,
        'integration_token_id' => $test->token->id,
        'deployment_uuid' => 'failed-deploy',
    ], $attributes));
}

function mockAiCollector(): MockInterface
{
    $collector = Mockery::mock(DeploymentContextCollector::class);
    app()->instance(DeploymentContextCollector::class, $collector);

    return $collector;
}

function fakeAiContext(): void
{
    mockAiCollector()->shouldReceive('collect')->andReturn([
        'application' => ['build_pack' => 'nixpacks'],
        'repository' => ['files_listed' => ['main.py'], 'file_count' => 1, 'tree_truncated' => false, 'files' => ['main.py' => 'app'], 'all_paths' => ['main.py']],
        'deployment_log' => 'Error: connection refused on 127.0.0.1:8000',
        'environment' => ['applications' => [], 'databases' => []],
        'previous_attempts' => [],
    ]);
}

function aiAnswer(array $plan): array
{
    return [
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['content' => json_encode($plan)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200],
    ];
}

function rawAiPlan(array $component = []): array
{
    return [
        'diagnosis' => 'The app listens on localhost.',
        'summary' => 'Listen on 0.0.0.0.',
        'components' => [array_merge([
            'name' => 'web', 'build_pack' => 'nixpacks', 'base_directory' => '/', 'ports_exposes' => '8000',
            'start_command' => 'uvicorn main:app --host 0.0.0.0 --port 8000',
        ], $component)],
    ];
}

it('stores the checked plan of the AI with the token usage', function () {
    fakeAiContext();
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(aiAnswer(rawAiPlan()))]);
    $attempt = pendingAiAttempt($this);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    $attempt->refresh();
    expect($attempt->status)->toBe(AiDeploymentAttempt::STATUS_READY)
        ->and($attempt->diagnosis)->toBe('The app listens on localhost.')
        ->and($attempt->plan['components'][0]['ports_exposes'])->toBe('8000')
        ->and($attempt->plan_hash)->toBe(DeploymentPlanValidator::hash($attempt->plan))
        ->and($attempt->prompt_tokens)->toBe(1000)
        ->and($attempt->completion_tokens)->toBe(200);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test-key')
        && str_contains($request['messages'][1]['content'], 'connection refused'));
});

it('sends the validation errors back once and accepts the corrected plan', function () {
    fakeAiContext();
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
        ->push(aiAnswer(rawAiPlan(['start_command' => 'npm start; rm -rf /'])))
        ->push(aiAnswer(rawAiPlan()))]);
    $attempt = pendingAiAttempt($this);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_READY)
        ->and($attempt->fresh()->prompt_tokens)->toBe(2000);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => count($request['messages']) === 4
        && str_contains($request['messages'][3]['content'], 'start_command contains characters'));
});

it('fails the attempt when the second plan is also invalid or the provider fails', function (array $responses, string $error) {
    fakeAiContext();
    $sequence = Http::sequence();
    foreach ($responses as [$body, $status]) {
        $sequence->push($body, $status);
    }
    Http::fake(['https://api.deepseek.com/chat/completions' => $sequence]);
    $attempt = pendingAiAttempt($this);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_FAILED)
        ->and($attempt->fresh()->error)->toContain($error);
})->with([
    'invalid twice' => [[[aiAnswer(rawAiPlan(['build_pack' => 'heroku'])), 200], [aiAnswer(rawAiPlan(['build_pack' => 'heroku'])), 200]], 'build_pack must be one of'],
    'rejected key' => [[[['error' => ['message' => 'bad key']], 401]], 'rejected the API key'],
]);

it('does not store remote error messages, which can contain Git tokens', function () {
    mockAiCollector()->shouldReceive('collect')
        ->andThrow(new RuntimeException('fatal: could not read https://x-access-token:ghs_secret@github.com/acme/shop'));
    $attempt = pendingAiAttempt($this);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_FAILED)
        ->and($attempt->fresh()->error)->toContain('could not read the repository')
        ->and($attempt->fresh()->error)->not->toContain('ghs_secret');
    Http::assertNothingSent();
});

it('does not use the AI token of another team', function () {
    fakeAiContext();
    $otherToken = IntegrationToken::factory()->create(['team_id' => Team::factory()->create()->id, 'provider' => 'deepseek', 'capabilities' => ['ai']]);
    $attempt = pendingAiAttempt($this, ['integration_token_id' => $otherToken->id]);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_FAILED);
    Http::assertNothingSent();
});

it('applies the plan of an automatic attempt and deploys again', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    fakeAiContext();
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(aiAnswer(rawAiPlan()))]);
    $attempt = pendingAiAttempt($this, ['trigger' => AiDeploymentAttempt::TRIGGER_AUTOMATIC]);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_APPLIED)
        ->and($attempt->fresh()->applied_by)->toBeNull()
        ->and($this->application->fresh()->ports_exposes)->toBe('8000');
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
});

it('does not apply automatically a plan that was already applied without success', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    fakeAiContext();
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(aiAnswer(rawAiPlan()))]);
    $normalized = (new DeploymentPlanValidator)->validate(rawAiPlan());
    AiDeploymentAttempt::factory()->ready($normalized)->create([
        'team_id' => $this->team->id, 'application_id' => $this->application->id,
        'status' => AiDeploymentAttempt::STATUS_APPLIED, 'plan_hash' => DeploymentPlanValidator::hash($normalized),
    ]);
    $attempt = pendingAiAttempt($this, ['trigger' => AiDeploymentAttempt::TRIGGER_AUTOMATIC]);

    AiDeploymentAssistJob::dispatchSync($attempt->id);

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_READY)
        ->and($attempt->fresh()->error)->toContain('already applied');
    Queue::assertNothingPushed();
});
