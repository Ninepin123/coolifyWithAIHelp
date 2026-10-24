<?php

namespace App\Services\Ai;

use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Collects what the AI deployment assistant needs to know about a failed deployment: the file list
 * of the repository, selected build files, the build settings, the names of the environment
 * variables, and the deployment log with secrets hidden. Environment variable values and `.env`
 * files are never collected.
 */
class DeploymentContextCollector
{
    /** Tree lines read from the server; one more than this means the tree was cut off. */
    public const MAX_TREE_ENTRIES = 5000;

    public const MAX_TREE_ENTRIES_IN_PROMPT = 400;

    public const MAX_FILES = 40;

    public const MAX_FILE_BYTES = 16384;

    public const MAX_README_BYTES = 6144;

    public const MAX_TOTAL_FILE_BYTES = 160000;

    public const MAX_LOG_LINES = 250;

    public const MAX_LOG_LINE_LENGTH = 500;

    public const MAX_LOG_BYTES = 40000;

    private const MAX_PATH_DEPTH = 5;

    private const SAFE_PATH_PATTERN = '~^[A-Za-z0-9._@+\-]+(?:/[A-Za-z0-9._@+\-]+)*$~';

    /** Folders that hold dependencies or build output, not source. */
    private const IGNORED_SEGMENTS = ['node_modules', 'vendor', '.git', '.venv', 'venv', '__pycache__', 'dist', 'build', '.next', '.nuxt', 'target', 'coverage', '.cache', '.turbo', 'bower_components'];

    private const MANIFEST_FILES = ['package.json', 'composer.json', 'requirements.txt', 'pyproject.toml', 'pipfile', 'go.mod', 'cargo.toml', 'gemfile', 'mix.exs', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'deno.json', 'deno.jsonc', 'pnpm-workspace.yaml', 'turbo.json', 'nx.json', 'lerna.json', 'setup.py', 'setup.cfg'];

    private const CONTAINER_FILES = ['.dockerignore', 'nixpacks.toml', 'nixpacks.json', 'railpack.json', 'procfile'];

    private const CONFIG_FILES = ['angular.json', 'vue.config.js', 'remix.config.js', '.nvmrc', '.node-version', '.python-version', 'runtime.txt', '.tool-versions', 'manage.py', 'artisan'];

    private const ENTRY_FILES = ['main.py', 'app.py', 'wsgi.py', 'asgi.py', 'server.py', 'server.js', 'server.ts', 'server.mjs', 'app.js', 'app.ts', 'main.go', 'main.ts', 'index.js', 'index.ts'];

    private const FILE_MARKER = '__COOLIFY_AI_FILE__';

    private const TREE_MARKER = '__COOLIFY_AI_TREE__';

    /**
     * @return array<string, mixed>
     */
    public function collect(Application $application, ApplicationDeploymentQueue $deployment, ?AiDeploymentAttempt $currentAttempt = null): array
    {
        return [
            'application' => $this->applicationConfiguration($application),
            'repository' => $this->scanRepository($application),
            'deployment_log' => $this->deploymentLog($application, $deployment),
            'environment' => $this->environmentResources($application),
            'previous_attempts' => $this->previousAttempts($application, $currentAttempt),
        ];
    }

    /**
     * Clones the repository on the server without a checkout, lists its files, and reads the
     * selected files with `git cat-file`, so no repository file is written to the disk.
     *
     * @return array{files_listed: list<string>, file_count: int, tree_truncated: bool, files: array<string, string>, all_paths: list<string>}
     */
    public function scanRepository(Application $application): array
    {
        $server = $application->destination->server;
        $uuid = new_public_id();

        try {
            $treeOutput = (string) instant_remote_process($this->treeCommands($application, $uuid), $server, timeout: 180);
            $tree = $this->parseTree($treeOutput);
            $selected = $this->selectFiles($tree['entries']);
            $files = [];
            if ($selected !== []) {
                $fileOutput = (string) instant_remote_process($this->readFileCommands($uuid, $selected), $server, timeout: 60);
                $files = $this->parseFiles($fileOutput, $selected);
            }
        } finally {
            instant_remote_process(["rm -rf /tmp/{$uuid}"], $server, false);
        }

        $paths = array_keys($tree['entries']);

        return [
            'files_listed' => $this->pathsForPrompt($paths),
            'file_count' => count($paths),
            'tree_truncated' => $tree['truncated'],
            'files' => $files,
            'all_paths' => $paths,
        ];
    }

    /**
     * Each command is one line because non-root servers send every line through parseCommandsByLineForSudo().
     *
     * @return Collection<int, string>
     */
    public function treeCommands(Application $application, string $uuid): Collection
    {
        $checkoutDir = "/tmp/{$uuid}/checkout";
        $limit = self::MAX_TREE_ENTRIES + 1;

        return collect([
            "rm -rf /tmp/{$uuid}",
            "mkdir -p /tmp/{$uuid}",
            "cd /tmp/{$uuid}",
            'sh -c '.escapeshellarg($application->serverCheckoutCommand($uuid, $checkoutDir)),
            "cd {$checkoutDir}",
            'echo '.self::TREE_MARKER,
            'sh -c '.escapeshellarg("git -c core.quotePath=false ls-tree -r --long HEAD | head -n {$limit}"),
        ]);
    }

    /**
     * @param  list<string>  $paths  Paths from selectFiles(), which only returns safe paths.
     * @return Collection<int, string>
     */
    public function readFileCommands(string $uuid, array $paths): Collection
    {
        foreach ($paths as $path) {
            if (! self::isSafePath($path)) {
                throw new RuntimeException('Invalid repository path.');
            }
        }
        $maxBytes = self::MAX_FILE_BYTES;
        $list = implode(' ', $paths);
        $script = "for f in {$list}; do echo \"".self::FILE_MARKER." \$f\"; git cat-file blob \"HEAD:\$f\" | head -c {$maxBytes} | base64 | tr -d '\\n'; echo; done";

        return collect([
            "cd /tmp/{$uuid}/checkout",
            'sh -c '.escapeshellarg($script),
        ]);
    }

    /**
     * @return array{entries: array<string, int>, truncated: bool}
     */
    public function parseTree(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $markerIndex = array_search(self::TREE_MARKER, array_map('trim', $lines), true);
        if ($markerIndex === false) {
            throw new RuntimeException('Could not list the files of the repository.');
        }
        $lines = array_slice($lines, $markerIndex + 1);
        $entries = [];
        $count = 0;
        foreach ($lines as $line) {
            if (! preg_match('/^(\d{6}) (\w+) [0-9a-f]+\s+(-|\d+)\t(.+)$/', $line, $matches)) {
                continue;
            }
            $count++;
            if ($count > self::MAX_TREE_ENTRIES) {
                break;
            }
            // Only regular files: symlinks (120000) and submodules (160000) are skipped.
            if ($matches[2] !== 'blob' || ! in_array($matches[1], ['100644', '100755'], true)) {
                continue;
            }
            $entries[$matches[4]] = $matches[3] === '-' ? 0 : (int) $matches[3];
        }

        return ['entries' => $entries, 'truncated' => $count > self::MAX_TREE_ENTRIES];
    }

    /**
     * Picks the files that tell how the repository is built and started, most useful first.
     *
     * @param  array<string, int>  $entries  Path => size in bytes.
     * @return list<string>
     */
    public function selectFiles(array $entries): array
    {
        $candidates = [];
        foreach ($entries as $path => $size) {
            $path = (string) $path;
            if (! self::isSafePath($path) || $this->isIgnoredPath($path) || $size > 512000) {
                continue;
            }
            $depth = substr_count($path, '/') + 1;
            if ($depth > self::MAX_PATH_DEPTH) {
                continue;
            }
            $priority = $this->filePriority($path, $depth);
            if ($priority === null) {
                continue;
            }
            $candidates[] = ['path' => $path, 'priority' => $priority, 'depth' => $depth, 'size' => $size];
        }

        usort($candidates, fn (array $a, array $b): int => [$a['priority'], $a['depth'], $a['path']] <=> [$b['priority'], $b['depth'], $b['path']]);

        $selected = [];
        $budget = self::MAX_TOTAL_FILE_BYTES;
        foreach ($candidates as $candidate) {
            if (count($selected) >= self::MAX_FILES) {
                break;
            }
            $cost = min($candidate['size'], self::MAX_FILE_BYTES);
            if ($cost > $budget) {
                continue;
            }
            $budget -= $cost;
            $selected[] = $candidate['path'];
        }

        return $selected;
    }

    /**
     * @param  list<string>  $selected
     * @return array<string, string>
     */
    public function parseFiles(string $output, array $selected): array
    {
        $files = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, self::FILE_MARKER.' ')) {
                $path = substr($line, strlen(self::FILE_MARKER) + 1);
                $current = in_array($path, $selected, true) ? $path : null;

                continue;
            }
            if ($current === null) {
                continue;
            }
            $content = base64_decode(trim($line), true);
            if ($content !== false && $content !== '' && mb_check_encoding($content, 'UTF-8') && ! str_contains($content, "\0")) {
                $files[$current] = $this->prepareFileContent($current, $content);
            }
            $current = null;
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function applicationConfiguration(Application $application): array
    {
        $application->loadMissing('settings');

        return [
            'name' => $application->name,
            'git_repository' => self::withoutCredentials((string) $application->git_repository),
            'git_branch' => $application->git_branch,
            'build_pack' => $application->build_pack,
            'base_directory' => $application->base_directory,
            'install_command' => $application->install_command,
            'build_command' => $application->build_command,
            'start_command' => $application->start_command,
            'ports_exposes' => $application->ports_exposes,
            'publish_directory' => $application->publish_directory,
            'is_static' => (bool) data_get($application, 'settings.is_static'),
            'is_spa' => (bool) data_get($application, 'settings.is_spa'),
            'dockerfile_location' => $application->dockerfile_location,
            'docker_compose_location' => $application->docker_compose_location,
            'health_check' => [
                'enabled' => (bool) $application->health_check_enabled,
                'path' => $application->health_check_path,
                'port' => $application->health_check_port,
            ],
            'has_domain' => filled($application->fqdn),
            'environment_variables' => $application->environment_variables()
                ->get(['key', 'is_buildtime', 'is_runtime'])
                ->map(fn (EnvironmentVariable $variable): array => [
                    'key' => $variable->key,
                    'build_time' => (bool) $variable->is_buildtime,
                    'run_time' => (bool) $variable->is_runtime,
                ])
                ->values()
                ->all(),
            'generated_build_files' => collect($application->aiBuildFiles())
                ->map(fn (array $file): array => ['path' => $file['path'], 'content' => self::truncate($file['content'], self::MAX_FILE_BYTES)])
                ->all(),
        ];
    }

    /**
     * The end of the deployment log, also the debug lines, with every environment variable value
     * that could be a secret hidden.
     */
    public function deploymentLog(Application $application, ApplicationDeploymentQueue $deployment): string
    {
        $lines = decode_remote_command_output($deployment, includeAll: true)
            ->map(function (array $line): string {
                $text = trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', (string) data_get($line, 'line', '')));

                return self::truncate((! empty($line['command']) ? '$ ' : '').$text, self::MAX_LOG_LINE_LENGTH);
            })
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

        $text = $lines->slice(-self::MAX_LOG_LINES)->implode("\n");
        $text = sanitizeLogsForExport($text, $this->logSecrets($application, (int) $deployment->pull_request_id !== 0));
        if (strlen($text) > self::MAX_LOG_BYTES) {
            $text = '[... earlier lines omitted]'."\n".substr($text, -self::MAX_LOG_BYTES);
        }

        return $text;
    }

    /**
     * Values to hide in the log. Coolify shows unlocked values to admins, but the log goes to a third
     * party here, so every value that is long enough or has a secret-like name is hidden as well.
     *
     * @return array{known: list<string>, locked: list<string>}
     */
    public function logSecrets(Application $application, bool $includePreview): array
    {
        $secrets = EnvironmentVariable::logRedactionSecretsFor($application, includePreview: $includePreview);
        $variables = collect($application->environment_variables);
        if ($includePreview) {
            $variables = $variables->merge($application->environment_variables_preview ?? []);
        }
        foreach ($variables as $variable) {
            $value = (string) $variable->value;
            if ($value === '') {
                continue;
            }
            if (self::isSecretLikeKey((string) $variable->key)) {
                $secrets['locked'][] = $value;
            } elseif (strlen($value) >= 8) {
                $secrets['known'][] = $value;
            }
        }

        return [
            'known' => array_values(array_unique($secrets['known'] ?? [])),
            'locked' => array_values(array_unique($secrets['locked'] ?? [])),
        ];
    }

    /**
     * Other resources in the environment, so the AI can reuse a database instead of asking for a new one.
     *
     * @return array{applications: list<array<string, mixed>>, databases: list<array<string, string>>}
     */
    public function environmentResources(Application $application): array
    {
        $environment = $application->environment;
        if (! $environment) {
            return ['applications' => [], 'databases' => []];
        }

        $applications = $environment->applications()
            ->whereKeyNot($application->id)
            ->limit(30)
            ->get(['id', 'name', 'build_pack', 'base_directory', 'git_repository'])
            ->map(fn (Application $other): array => [
                'name' => $other->name,
                'build_pack' => $other->build_pack,
                'base_directory' => $other->base_directory,
                'same_repository' => $other->git_repository === $application->git_repository,
            ])
            ->all();

        $databases = $environment->databases()
            ->take(30)
            ->map(fn ($database): array => [
                'name' => (string) $database->name,
                'type' => (string) str($database->type())->after('standalone-'),
            ])
            ->values()
            ->all();

        return ['applications' => $applications, 'databases' => $databases];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function previousAttempts(Application $application, ?AiDeploymentAttempt $currentAttempt = null): array
    {
        return $application->aiDeploymentAttempts()
            ->when($currentAttempt, fn ($query) => $query->whereKeyNot($currentAttempt->id))
            ->whereIn('status', [AiDeploymentAttempt::STATUS_APPLIED, AiDeploymentAttempt::STATUS_READY, AiDeploymentAttempt::STATUS_DISMISSED])
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(function (AiDeploymentAttempt $attempt): array {
                $deploymentStatuses = collect($attempt->deployment_uuids ?? [])
                    ->map(fn (string $uuid): ?string => ApplicationDeploymentQueue::query()->where('deployment_uuid', $uuid)->value('status'))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'status' => $attempt->status,
                    'diagnosis' => $attempt->diagnosis,
                    'summary' => $attempt->summary,
                    'plan' => collect($attempt->plan ?? [])->only(['components', 'databases'])->all(),
                    'deployment_results' => $deploymentStatuses,
                ];
            })
            ->all();
    }

    public static function isSafePath(string $path): bool
    {
        return strlen($path) <= 240
            && preg_match(self::SAFE_PATH_PATTERN, $path) === 1
            && ! preg_match('~(^|/)\.\.?(/|$)~', $path);
    }

    public static function withoutCredentials(string $repository): string
    {
        return (string) preg_replace('~^([a-z][a-z0-9+.-]*://)[^@/]+@~i', '$1', $repository);
    }

    public static function isSecretLikeKey(string $key): bool
    {
        return preg_match('/(PASS|SECRET|TOKEN|KEY|PRIVATE|AUTH|CREDENTIAL|DSN|COOKIE|SALT|_URL$|_URI$)/i', $key) === 1;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function pathsForPrompt(array $paths): array
    {
        $paths = array_values(array_filter($paths, fn (string $path): bool => ! $this->isIgnoredPath($path)));
        usort($paths, fn (string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return array_slice($paths, 0, self::MAX_TREE_ENTRIES_IN_PROMPT);
    }

    private function isIgnoredPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), self::IGNORED_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function filePriority(string $path, int $depth): ?int
    {
        $name = strtolower(basename($path));

        return match (true) {
            in_array($name, self::MANIFEST_FILES, true) || preg_match('/\.(cs|fs)proj$/', $name) === 1 => 1,
            $this->isDockerFile($name) || in_array($name, self::CONTAINER_FILES, true) => 2,
            in_array($name, self::CONFIG_FILES, true) || preg_match('/^(vite|next|nuxt|svelte|astro|gatsby-config)\.config\.(js|cjs|mjs|ts)$|^gatsby-config\.(js|ts)$/', $name) === 1 => 3,
            $this->isEnvTemplate($name) => 4,
            in_array($name, self::ENTRY_FILES, true) && $depth <= 3 => 5,
            preg_match('/^readme(\.md|\.txt)?$/', $name) === 1 && $depth <= 2 => 6,
            default => null,
        };
    }

    private function isDockerFile(string $name): bool
    {
        return preg_match('/^dockerfile(\.[a-z0-9_.-]+)?$|\.dockerfile$|^(docker-)?compose(\.[a-z0-9_-]+)?\.ya?ml$/', $name) === 1;
    }

    private function isEnvTemplate(string $name): bool
    {
        return preg_match('/^\.env(\.[a-z0-9_-]+)*\.(example|sample|template|dist)$/', $name) === 1;
    }

    private function prepareFileContent(string $path, string $content): string
    {
        $name = strtolower(basename($path));
        if ($this->isEnvTemplate($name)) {
            // Only the names: templates sometimes hold real values.
            return collect(preg_split('/\r?\n/', $content) ?: [])
                ->map(fn (string $line): ?string => preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $matches) ? $matches[1].'=' : null)
                ->filter()
                ->implode("\n");
        }
        if (str_starts_with($name, 'readme')) {
            return self::truncate($content, self::MAX_README_BYTES);
        }

        return strlen($content) >= self::MAX_FILE_BYTES ? self::truncate($content, self::MAX_FILE_BYTES - 32) : $content;
    }

    private static function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes, 'UTF-8')."\n[... truncated]";
    }
}
