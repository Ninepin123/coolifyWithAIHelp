<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * Records the remote commands of a deployment instead of running them.
 */
class AiBuildFilesDeploymentJob extends ApplicationDeploymentJob
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $this->commands[] = $command;
        }
    }
}

beforeEach(function () {
    setUpAiAssistantFixture($this);
});

function aiBuildFilesJob(Application $application): AiBuildFilesDeploymentJob
{
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry');
    $job = new AiBuildFilesDeploymentJob;
    foreach (['application' => $application, 'application_deployment_queue' => $queue, 'deployment_uuid' => 'deploy-uuid', 'workdir' => '/artifacts/deploy-uuid/backend'] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'write_ai_build_files'))->invoke($job);

    return $job;
}

it('writes the generated build files into the checkout before the build', function () {
    $this->application->forceFill(['ai_build_files' => [
        ['path' => '/docker/Dockerfile', 'content' => "FROM python:3.12\nCMD [\"python\", \"main.py\"]\n"],
    ]])->saveQuietly();

    $job = aiBuildFilesJob($this->application->fresh());

    expect($job->commands)->toHaveCount(2)
        ->and($job->commands[0][0])->toBe(executeInDocker('deploy-uuid', "mkdir -p '/artifacts/deploy-uuid/backend/docker'"))
        ->and($job->commands[1][0])->toBe("docker exec -i deploy-uuid tee '/artifacts/deploy-uuid/backend/docker/Dockerfile' > /dev/null")
        ->and($job->commands[1]['input'])->toBe("FROM python:3.12\nCMD [\"python\", \"main.py\"]\n");
});

it('skips stored build files whose path leaves the checkout', function () {
    $this->application->forceFill(['ai_build_files' => [
        ['path' => '/../../etc/cron.d/job', 'content' => 'x'],
        ['path' => "/Dockerfile'; id; '", 'content' => 'x'],
    ]])->saveQuietly();

    expect(aiBuildFilesJob($this->application->fresh())->commands)->toBe([]);
});

it('loads a generated Docker Compose file instead of reading it from the repository', function () {
    Process::fake();
    $this->application->forceFill([
        'build_pack' => 'dockercompose',
        'docker_compose_location' => '/docker-compose.yaml',
        'ai_build_files' => [['path' => '/docker-compose.yaml', 'content' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      - SERVICE_URL_WEB_80\n"]],
    ])->saveQuietly();

    $result = $this->application->fresh()->loadComposeFile();

    expect($this->application->fresh()->docker_compose_raw)->toContain('nginx:alpine')
        ->and(data_get($result, 'parsedServices.services'))->toHaveKey('web');
    Process::assertNothingRan();
});

it('refuses an unsafe generated Docker Compose file', function () {
    Process::fake();
    $this->application->forceFill([
        'build_pack' => 'dockercompose',
        'docker_compose_location' => '/docker-compose.yaml',
        'ai_build_files' => [['path' => '/docker-compose.yaml', 'content' => "services:\n  web:\n    image: nginx\n    volumes:\n      - '/tmp/\$(id):/data'\n"]],
    ])->saveQuietly();

    expect(fn () => $this->application->fresh()->loadComposeFile())->toThrow(DeploymentException::class, 'not safe to use');
    expect($this->application->fresh()->docker_compose_raw)->toBeNull();
});

it('rebuilds the image when the generated build files change', function () {
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'deployed', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value, 'pull_request_id' => 0,
    ]);
    $this->application->forceFill(['ai_build_files' => [['path' => '/Dockerfile', 'content' => "FROM node:20\n"]]])->saveQuietly();
    $this->application->fresh()->markDeploymentConfigurationApplied($deployment);

    $this->application->forceFill(['ai_build_files' => [['path' => '/Dockerfile', 'content' => "FROM node:22\n"]]])->saveQuietly();
    $diff = $this->application->fresh()->pendingDeploymentConfigurationDiff();

    expect($diff->requiresBuild())->toBeTrue();
});
