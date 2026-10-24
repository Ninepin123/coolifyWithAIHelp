<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\AiAssistantException;
use App\Jobs\AiDeploymentAssistJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Ai\AiDeploymentAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpAiAssistantFixture($this);
});

function aiTestDeployment(object $test, string $status = 'failed', array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create(array_merge([
        'application_id' => $test->application->id,
        'deployment_uuid' => fake()->uuid(),
        'server_id' => $test->server->id,
        'destination_id' => $test->destination->id,
        'status' => $status,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ], $attributes));
}

function failAiTestDeployment(ApplicationDeploymentQueue $deployment): void
{
    $job = new ApplicationDeploymentJob($deployment->id);
    (fn () => $this->failDeployment())->call($job);
}

it('starts an analysis of a failed deployment', function () {
    Queue::fake([AiDeploymentAssistJob::class]);
    $deployment = aiTestDeployment($this);

    $attempt = app(AiDeploymentAssistant::class)->requestHelp($this->application, $deployment, $this->user);

    expect($attempt->only(['team_id', 'application_id', 'integration_token_id', 'deployment_uuid', 'trigger', 'status', 'requested_by']))->toBe([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'integration_token_id' => $this->token->id,
        'deployment_uuid' => $deployment->deployment_uuid,
        'trigger' => AiDeploymentAttempt::TRIGGER_MANUAL,
        'status' => AiDeploymentAttempt::STATUS_PENDING,
        'requested_by' => $this->user->id,
    ]);
    Queue::assertPushed(AiDeploymentAssistJob::class, fn (AiDeploymentAssistJob $job): bool => $job->attemptId === $attempt->id && $job->delay === null);
});

it('refuses an analysis that cannot run', function (Closure $arrange, string $error) {
    Queue::fake([AiDeploymentAssistJob::class]);
    $deployment = $arrange($this);

    expect(fn () => app(AiDeploymentAssistant::class)->requestHelp($this->application, $deployment, $this->user))
        ->toThrow(AiAssistantException::class, $error);
    Queue::assertNotPushed(AiDeploymentAssistJob::class);
})->with([
    'deployment did not fail' => [fn (object $test) => aiTestDeployment($test, 'finished'), 'only analyzes failed deployments'],
    'preview deployment' => [fn (object $test) => aiTestDeployment($test, 'failed', ['pull_request_id' => 3]), 'preview deployments'],
    'deployment of another application' => [function (object $test) {
        $other = Application::factory()->create(['environment_id' => $test->environment->id, 'destination_id' => $test->destination->id, 'destination_type' => $test->destination->getMorphClass()]);

        return aiTestDeployment($test, 'failed', ['application_id' => $other->id]);
    }, 'does not belong to this application'],
    'no DeepSeek token' => [function (object $test) {
        $test->token->delete();

        return aiTestDeployment($test);
    }, 'Add a DeepSeek token'],
    'analysis already running' => [function (object $test) {
        AiDeploymentAttempt::factory()->create(['team_id' => $test->team->id, 'application_id' => $test->application->id, 'status' => AiDeploymentAttempt::STATUS_ANALYZING]);

        return aiTestDeployment($test);
    }, 'already analyzing'],
    'daily team limit' => [function (object $test) {
        AiDeploymentAttempt::factory()->count(AiDeploymentAssistant::DAILY_TEAM_LIMIT)->create([
            'team_id' => $test->team->id, 'application_id' => $test->application->id, 'status' => AiDeploymentAttempt::STATUS_FAILED,
        ]);

        return aiTestDeployment($test);
    }, 'reached the limit'],
]);

it('starts an automatic analysis when a deployment fails and automatic fixes are on', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Notification::fake();
    Queue::fake([AiDeploymentAssistJob::class, ApplicationDeploymentJob::class]);
    $this->application->settings->update(['is_ai_auto_fix_enabled' => true]);
    $deployment = aiTestDeployment($this, ApplicationDeploymentStatus::IN_PROGRESS->value);

    failAiTestDeployment($deployment);

    $attempt = AiDeploymentAttempt::query()->sole();
    expect($attempt->trigger)->toBe(AiDeploymentAttempt::TRIGGER_AUTOMATIC)
        ->and($attempt->deployment_uuid)->toBe($deployment->deployment_uuid)
        ->and($deployment->fresh()->logs)->toContain('will apply its fix automatically');
    Queue::assertPushed(AiDeploymentAssistJob::class, fn (AiDeploymentAssistJob $job): bool => $job->attemptId === $attempt->id && $job->delay !== null);
});

it('does not start an automatic analysis when automatic fixes are off', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Notification::fake();
    Queue::fake([AiDeploymentAssistJob::class, ApplicationDeploymentJob::class]);

    failAiTestDeployment(aiTestDeployment($this, ApplicationDeploymentStatus::IN_PROGRESS->value));

    expect(AiDeploymentAttempt::query()->count())->toBe(0);
    Queue::assertNotPushed(AiDeploymentAssistJob::class);
});

it('stops automatic fixes after three attempts without a successful deployment', function () {
    Queue::fake([AiDeploymentAssistJob::class]);
    $this->application->settings->update(['is_ai_auto_fix_enabled' => true]);
    AiDeploymentAttempt::factory()->count(AiDeploymentAssistant::MAX_AUTOMATIC_ATTEMPTS)->create([
        'team_id' => $this->team->id, 'application_id' => $this->application->id,
        'trigger' => AiDeploymentAttempt::TRIGGER_AUTOMATIC, 'status' => AiDeploymentAttempt::STATUS_APPLIED,
    ]);
    $deployment = aiTestDeployment($this);

    expect(app(AiDeploymentAssistant::class)->handleFailedDeployment($this->application->fresh(), $deployment))->toBeNull()
        ->and($deployment->fresh()->logs)->toContain('already tried 3 automatic fixes');
    Queue::assertNotPushed(AiDeploymentAssistJob::class);

    $this->travel(1)->minutes();
    aiTestDeployment($this, 'finished');
    $this->travel(1)->minutes();

    expect(app(AiDeploymentAssistant::class)->handleFailedDeployment($this->application->fresh(), aiTestDeployment($this)))->toBeInstanceOf(AiDeploymentAttempt::class);
    Queue::assertPushed(AiDeploymentAssistJob::class, 1);
});
