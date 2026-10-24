<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Services\Ai\DeploymentContextCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Runs remote commands on this machine, optionally after the non-root sudo parser (with `sudo`
 * removed, so the test does not need root).
 *
 * @param  Collection<int, string>  $commands
 */
function runAiCollectorCommands(Collection $commands, bool $throughSudoParser): Process
{
    if ($throughSudoParser) {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
        $commands = collect(parseCommandsByLineForSudo($commands, $server))
            ->map(fn (string $line) => preg_replace('/(^|\s)sudo /', '$1', $line))
            ->map(fn (string $line) => preg_replace('/ && chown .*$/', '', $line));
    }
    $process = Process::fromShellCommandline("set -e\n".$commands->implode("\n"));
    $process->run();

    return $process;
}

function aiCollectorRepositoryApplication(string $repository): Application
{
    $application = (new Application)->forceFill(['git_repository' => $repository, 'git_branch' => 'main', 'git_commit_sha' => 'HEAD']);
    $application->setRelation('settings', (new ApplicationSetting)->forceFill(['is_git_shallow_clone_enabled' => true, 'is_git_submodules_enabled' => false]));
    $application->setRelation('source', null);
    $application->setRelation('private_key', null);

    return $application;
}

it('reads the build files of a monorepo without env files, dependencies, or symlinks', function (bool $throughSudoParser) {
    $repo = sys_get_temp_dir().'/ai-scan-fixture-'.bin2hex(random_bytes(8));
    $uuid = 'ai-scan-'.bin2hex(random_bytes(8));
    foreach (['frontend/src', 'backend', 'frontend/node_modules/vite'] as $dir) {
        mkdir("{$repo}/{$dir}", recursive: true);
    }
    file_put_contents("{$repo}/frontend/package.json", '{"scripts":{"build":"vite build"}}');
    file_put_contents("{$repo}/frontend/vite.config.ts", 'export default {}');
    file_put_contents("{$repo}/frontend/node_modules/vite/package.json", '{"name":"vite"}');
    file_put_contents("{$repo}/backend/requirements.txt", "fastapi\nuvicorn\n");
    file_put_contents("{$repo}/backend/main.py", "app = FastAPI()\n");
    file_put_contents("{$repo}/backend/.env.example", "DATABASE_URL=postgres://user:real-password@db/app\nexport API_KEY=sk-real\n# comment\n");
    file_put_contents("{$repo}/.env", 'PROD_SECRET=never-read');
    file_put_contents("{$repo}/README.md", '# Shop');
    symlink("{$repo}/.env", "{$repo}/backend/Dockerfile");
    $git = 'git -C '.escapeshellarg($repo);
    Process::fromShellCommandline("{$git} init -q -b main && {$git} add -f . && {$git} -c user.name=Test -c user.email=test@example.com commit -qm fixture")->mustRun();

    try {
        $collector = new DeploymentContextCollector;
        $treeProcess = runAiCollectorCommands($collector->treeCommands(aiCollectorRepositoryApplication($repo), $uuid), $throughSudoParser);
        expect($treeProcess->isSuccessful())->toBeTrue($treeProcess->getErrorOutput());

        $tree = $collector->parseTree($treeProcess->getOutput());
        $selected = $collector->selectFiles($tree['entries']);
        $fileProcess = runAiCollectorCommands($collector->readFileCommands($uuid, $selected), $throughSudoParser);
        expect($fileProcess->isSuccessful())->toBeTrue($fileProcess->getErrorOutput());
        $files = $collector->parseFiles($fileProcess->getOutput(), $selected);

        expect($tree['truncated'])->toBeFalse()
            ->and(array_keys($tree['entries']))->not->toContain('backend/Dockerfile')
            ->and($selected)->not->toContain('.env')
            ->and($selected)->not->toContain('frontend/node_modules/vite/package.json')
            ->and($files)->toMatchArray([
                'frontend/package.json' => '{"scripts":{"build":"vite build"}}',
                'backend/requirements.txt' => "fastapi\nuvicorn\n",
                'backend/main.py' => "app = FastAPI()\n",
                'backend/.env.example' => "DATABASE_URL=\nAPI_KEY=",
                'README.md' => '# Shop',
            ])
            ->and(implode("\n", $files))->not->toContain('real-password')
            ->and(implode("\n", $files))->not->toContain('never-read');
    } finally {
        Process::fromShellCommandline('rm -rf -- '.escapeshellarg($repo).' /tmp/'.$uuid)->mustRun();
    }
})->with(['root server' => false, 'non-root server' => true]);

it('refuses to read repository paths with shell characters', function (string $path) {
    expect(fn () => (new DeploymentContextCollector)->readFileCommands('scan', [$path]))->toThrow(RuntimeException::class);
})->with(['a b/package.json', 'x;id/package.json', '../package.json', '$(id)/package.json']);

it('prefers manifests and build files and stays within the file budget', function () {
    $entries = ['README.md' => 100, 'src/app.py' => 100, 'Dockerfile' => 100, 'package.json' => 100, 'docs/guide.md' => 100];
    foreach (range(1, 60) as $i) {
        $entries["services/s{$i}/package.json"] = 100;
    }

    $selected = (new DeploymentContextCollector)->selectFiles($entries);

    expect($selected)->toHaveCount(DeploymentContextCollector::MAX_FILES)
        ->and(array_slice($selected, 0, 1))->toBe(['package.json'])
        ->and($selected)->not->toContain('docs/guide.md')
        ->and($selected)->not->toContain('README.md');
});

it('sends the end of the deployment log with environment variable values hidden', function () {
    setUpAiAssistantFixture($this);
    foreach (['API_TOKEN' => 'tok123', 'GREETING' => 'hello-from-a-long-value', 'PORT' => '3000'] as $key => $value) {
        EnvironmentVariable::query()->create([
            'key' => $key, 'value' => $value, 'is_preview' => false,
            'resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id,
        ]);
    }
    $lines = collect(range(1, 300))->map(fn (int $i): array => [
        'command' => null, 'output' => "line {$i}", 'type' => 'stdout', 'timestamp' => now()->toISOString(), 'hidden' => false, 'batch' => 1, 'order' => $i,
    ]);
    $lines->push(['command' => null, 'output' => "\e[31mError: token tok123 and hello-from-a-long-value on port 3000\e[0m", 'type' => 'stderr', 'timestamp' => now()->toISOString(), 'hidden' => true, 'batch' => 1, 'order' => 301]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'failed-deploy', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FAILED->value, 'logs' => json_encode($lines->all()),
    ]);

    $log = (new DeploymentContextCollector)->deploymentLog($this->application, $deployment);

    expect($log)->toContain('on port 3000')
        ->and($log)->not->toContain('tok123')
        ->and($log)->not->toContain('hello-from-a-long-value')
        ->and($log)->not->toContain("\e[")
        ->and($log)->not->toContain("line 51\n")
        ->and($log)->toContain('line 52');
});

it('describes the build settings with variable names only and no repository credentials', function () {
    setUpAiAssistantFixture($this);
    $this->application->update(['git_repository' => 'https://deploy:ghp_secret@github.com/acme/shop.git']);
    EnvironmentVariable::query()->create([
        'key' => 'STRIPE_KEY', 'value' => 'sk_live_secret', 'is_preview' => false, 'is_buildtime' => false,
        'resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id,
    ]);

    $configuration = (new DeploymentContextCollector)->applicationConfiguration($this->application->fresh());

    expect($configuration['git_repository'])->toBe('https://github.com/acme/shop.git')
        ->and(collect($configuration['environment_variables'])->firstWhere('key', 'STRIPE_KEY'))->toBe(['key' => 'STRIPE_KEY', 'build_time' => false, 'run_time' => true])
        ->and(json_encode($configuration))->not->toContain('sk_live_secret')
        ->and(json_encode($configuration))->not->toContain('ghp_secret');
});

it('tells the AI what earlier plans changed and how their deployments ended', function () {
    setUpAiAssistantFixture($this);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'after-plan', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);
    AiDeploymentAttempt::factory()->ready(aiTestPlan())->create([
        'team_id' => $this->team->id, 'application_id' => $this->application->id,
        'status' => AiDeploymentAttempt::STATUS_APPLIED, 'deployment_uuids' => ['after-plan'],
    ]);
    $current = AiDeploymentAttempt::factory()->create(['team_id' => $this->team->id, 'application_id' => $this->application->id]);

    $attempts = (new DeploymentContextCollector)->previousAttempts($this->application, $current);

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]['status'])->toBe('applied')
        ->and($attempts[0]['deployment_results'])->toBe(['failed'])
        ->and($attempts[0]['plan']['components'][0]['ports_exposes'])->toBe('8000');
});
