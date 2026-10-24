<?php

use App\Actions\Application\ApplyAiDeploymentPlan;
use App\Actions\Database\StartDatabase;
use App\Exceptions\AiAssistantException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpAiAssistantFixture($this);
});

function readyAiAttempt(object $test, array $plan): AiDeploymentAttempt
{
    return AiDeploymentAttempt::factory()->ready($plan)->create([
        'team_id' => $test->team->id,
        'application_id' => $test->application->id,
        'deployment_uuid' => 'failed-deploy',
    ]);
}

function addAiTestVariable(Application $application, string $key, string $value): void
{
    EnvironmentVariable::query()->create([
        'key' => $key, 'value' => $value, 'is_preview' => false,
        'resourceable_type' => $application->getMorphClass(), 'resourceable_id' => $application->id,
    ]);
}

it('reconfigures the failed application, adds missing variables, and deploys it with a fresh build', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    $this->application->update(['install_command' => 'pip install -r requirements.txt', 'build_command' => 'make build']);
    addAiTestVariable($this->application, 'DATABASE_URL', 'postgres://user-set');
    $files = [['path' => '/Procfile', 'content' => "web: uvicorn main:app\n"]];
    $attempt = readyAiAttempt($this, aiTestPlan([
        'build_command' => '',
        'health_check_path' => '/health',
        'env' => ['DATABASE_URL' => 'postgres://ai-guess', 'PYTHONUNBUFFERED' => '1'],
        'files' => $files,
    ]));

    $deploymentUuids = ApplyAiDeploymentPlan::run($attempt, $this->user);

    $application = $this->application->fresh();
    expect($application->start_command)->toBe('uvicorn main:app --host 0.0.0.0 --port 8000')
        ->and($application->ports_exposes)->toBe('8000')
        ->and($application->install_command)->toBe('pip install -r requirements.txt')
        ->and($application->build_command)->toBeEmpty()
        ->and($application->health_check_path)->toBe('/health')
        ->and($application->aiBuildFiles())->toBe($files)
        ->and(base64_decode((string) $application->custom_labels))->toContain('8000')
        ->and($application->environment_variables()->where('key', 'DATABASE_URL')->first()->value)->toBe('postgres://user-set')
        ->and($application->environment_variables()->where('key', 'PYTHONUNBUFFERED')->first()->value)->toBe('1');

    $attempt->refresh();
    expect($attempt->status)->toBe(AiDeploymentAttempt::STATUS_APPLIED)
        ->and($attempt->applied_by)->toBe($this->user->id)
        ->and($attempt->deployment_uuids)->toBe($deploymentUuids)
        ->and($deploymentUuids)->toHaveCount(1);
    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuids[0])->firstOrFail();
    expect((int) $deployment->application_id)->toBe($application->id)
        ->and((bool) $deployment->force_rebuild)->toBeTrue();
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
});

it('splits a repository into a frontend and a backend with a database and connects them', function () {
    Queue::fake([ApplicationDeploymentJob::class, JobDecorator::class]);
    $attempt = readyAiAttempt($this, aiTestPlan(
        ['name' => 'backend', 'base_directory' => '/backend', 'env' => ['DATABASE_URL' => '{{database.postgresql.url}}']],
        [
            'components' => [
                aiTestComponent(['name' => 'backend', 'base_directory' => '/backend', 'env' => ['DATABASE_URL' => '{{database.postgresql.url}}']]),
                aiTestComponent([
                    'name' => 'frontend', 'base_directory' => '/frontend', 'build_command' => 'npm run build', 'start_command' => null,
                    'ports_exposes' => '80', 'is_static' => true, 'is_spa' => true, 'publish_directory' => '/dist',
                    'env' => ['VITE_API_URL' => '{{component.backend.url}}/api', 'SSR_API_URL' => '{{component.backend.internal_url}}'],
                ]),
            ],
            'databases' => [['type' => 'postgresql']],
        ],
    ));

    $deploymentUuids = ApplyAiDeploymentPlan::run($attempt, $this->user);

    $backend = $this->application->fresh();
    $frontend = Application::query()->where('name', 'shop-frontend')->firstOrFail();
    $database = StandalonePostgresql::query()->where('environment_id', $this->environment->id)->firstOrFail();
    expect($frontend->only(['git_repository', 'git_branch', 'environment_id', 'destination_id', 'base_directory', 'publish_directory', 'ports_exposes']))->toBe([
        'git_repository' => 'https://github.com/acme/shop',
        'git_branch' => 'main',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'base_directory' => '/frontend',
        'publish_directory' => '/dist',
        'ports_exposes' => '80',
    ])
        ->and($frontend->settings->is_static)->toBeTrue()
        ->and($frontend->settings->is_spa)->toBeTrue()
        ->and($frontend->custom_nginx_configuration)->toBe(defaultNginxConfiguration('spa'))
        ->and($frontend->fqdn)->toContain($frontend->uuid)
        ->and($backend->base_directory)->toBe('/backend')
        ->and($backend->custom_network_aliases_array)->toContain($backend->uuid)
        ->and($backend->environment_variables()->where('key', 'DATABASE_URL')->first()->value)->toBe($database->internal_db_url)
        ->and($frontend->environment_variables()->where('key', 'VITE_API_URL')->first()->value)->toBe($backend->fqdn.'/api')
        ->and($frontend->environment_variables()->where('key', 'SSR_API_URL')->first()->value)->toBe("http://{$backend->uuid}:8000")
        ->and($deploymentUuids)->toHaveCount(2)
        ->and($attempt->fresh()->created_resources)->toBe([
            ['type' => 'database', 'database_type' => 'postgresql', 'uuid' => $database->uuid],
            ['type' => 'application', 'component' => 'frontend', 'uuid' => $frontend->uuid],
        ]);
    Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job): bool => $job->getAction() instanceof StartDatabase);
});

it('reuses the applications and databases an earlier plan created', function () {
    Queue::fake([ApplicationDeploymentJob::class, JobDecorator::class]);
    $splitPlan = fn (string $buildCommand) => aiTestPlan([], [
        'components' => [
            aiTestComponent(['env' => ['DATABASE_URL' => '{{database.postgresql.url}}']]),
            aiTestComponent(['name' => 'frontend', 'base_directory' => '/frontend', 'build_command' => $buildCommand, 'start_command' => null]),
        ],
        'databases' => [['type' => 'postgresql']],
    ]);
    ApplyAiDeploymentPlan::run(readyAiAttempt($this, $splitPlan('npm run build')), $this->user);

    ApplyAiDeploymentPlan::run(readyAiAttempt($this, $splitPlan('pnpm build')), $this->user);

    expect(Application::query()->where('environment_id', $this->environment->id)->count())->toBe(2)
        ->and(StandalonePostgresql::query()->where('environment_id', $this->environment->id)->count())->toBe(1)
        ->and(Application::query()->where('name', 'shop-frontend')->value('build_command'))->toBe('pnpm build');
});

it('refuses to apply a plan twice, a dismissed plan, or a plan the AI marked as not fixable', function (array $state) {
    Queue::fake([ApplicationDeploymentJob::class]);
    $attempt = readyAiAttempt($this, aiTestPlan());
    $attempt->update($state);

    expect(fn () => ApplyAiDeploymentPlan::run($attempt, $this->user))->toThrow(AiAssistantException::class, 'can no longer be applied');
    expect($this->application->fresh()->ports_exposes)->toBe('3000');
    Queue::assertNothingPushed();
})->with([
    'applied' => [['status' => AiDeploymentAttempt::STATUS_APPLIED]],
    'dismissed' => [['status' => AiDeploymentAttempt::STATUS_DISMISSED]],
    'not fixable' => [['fixable' => false]],
]);
