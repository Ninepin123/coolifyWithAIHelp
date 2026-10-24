<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;

/**
 * Creates a team with an owner, a server, an environment, a Git application, and (optionally) a
 * DeepSeek token, and stores them on the test case.
 */
function setUpAiAssistantFixture(object $test, bool $withToken = true): void
{
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $test->user = User::factory()->create();
    $test->team = Team::factory()->create();
    $test->team->members()->attach($test->user->id, ['role' => 'owner']);
    $test->server = Server::factory()->create(['team_id' => $test->team->id]);
    $test->destination = StandaloneDocker::query()->where('server_id', $test->server->id)->firstOrFail();
    $test->project = Project::factory()->create(['team_id' => $test->team->id]);
    $test->environment = Environment::factory()->create(['project_id' => $test->project->id]);
    $test->application = Application::factory()->create([
        'name' => 'shop',
        'git_repository' => 'https://github.com/acme/shop',
        'git_branch' => 'main',
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $test->token = $withToken
        ? IntegrationToken::factory()->create([
            'team_id' => $test->team->id,
            'provider' => 'deepseek',
            'name' => 'DeepSeek',
            'token' => 'sk-test-key',
            'capabilities' => ['ai'],
        ])
        : null;
}

/**
 * A valid single-component plan in the normalized form of DeploymentPlanValidator.
 *
 * @param  array<string, mixed>  $component
 * @return array<string, mixed>
 */
function aiTestPlan(array $component = [], array $plan = []): array
{
    return array_merge([
        'diagnosis' => 'The app listens on localhost.',
        'summary' => 'Start the app on 0.0.0.0 and expose port 8000.',
        'fixable' => true,
        'components' => [aiTestComponent($component)],
        'databases' => [],
    ], $plan);
}

/**
 * @param  array<string, mixed>  $component
 * @return array<string, mixed>
 */
function aiTestComponent(array $component = []): array
{
    return array_merge([
        'name' => 'web',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'install_command' => null,
        'build_command' => null,
        'start_command' => 'uvicorn main:app --host 0.0.0.0 --port 8000',
        'ports_exposes' => '8000',
        'is_static' => false,
        'is_spa' => false,
        'publish_directory' => null,
        'dockerfile_location' => null,
        'docker_compose_location' => null,
        'health_check_path' => null,
        'public' => true,
        'env' => [],
        'files' => [],
    ], $component);
}
