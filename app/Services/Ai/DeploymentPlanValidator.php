<?php

namespace App\Services\Ai;

use App\Exceptions\InvalidAiPlanException;
use App\Support\ValidationPatterns;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a plan from the AI deployment assistant and returns it in a normalized form. The AI output
 * is untrusted (repository files can try to steer it), so only known fields pass, and every value
 * must pass the same checks as the application settings form.
 */
class DeploymentPlanValidator
{
    public const MAX_COMPONENTS = 5;

    public const MAX_DATABASES = 3;

    public const MAX_ENV_VARIABLES = 50;

    public const MAX_ENV_VALUE_LENGTH = 4096;

    public const MAX_FILES = 10;

    public const MAX_FILE_BYTES = 65536;

    public const MAX_TOTAL_FILE_BYTES = 262144;

    /** @var list<string> */
    public const BUILD_PACKS = ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose'];

    /** @var list<string> */
    public const DATABASE_TYPES = ['postgresql', 'mysql', 'mariadb', 'mongodb', 'redis'];

    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    private const HEALTH_CHECK_PATH_PATTERN = '#^/[a-zA-Z0-9/\-_.~%,;]*$#';

    private const PLACEHOLDER_PATTERN = '/\{\{\s*([^{}]*?)\s*\}\}/';

    /** Variables Coolify generates itself. */
    private const RESERVED_ENV_PREFIXES = ['SERVICE_FQDN_', 'SERVICE_URL_', 'COOLIFY_'];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $data  The JSON object from the AI.
     * @param  list<string>|null  $repositoryPaths  Repository file paths, or null when the list is incomplete.
     * @return array{diagnosis: string, summary: string, fixable: bool, components: list<array<string, mixed>>, databases: list<array{type: string}>}
     *
     * @throws InvalidAiPlanException
     */
    public function validate(array $data, ?array $repositoryPaths = null): array
    {
        $this->errors = [];

        $databases = $this->databases($data['databases'] ?? []);
        $rawComponents = $data['components'] ?? null;
        if (! is_array($rawComponents) || ! array_is_list($rawComponents) || $rawComponents === []) {
            $this->errors[] = '"components" must be a non-empty list.';
            $rawComponents = [];
        }
        if (count($rawComponents) > self::MAX_COMPONENTS) {
            $this->errors[] = 'A plan can have at most '.self::MAX_COMPONENTS.' components.';
            $rawComponents = array_slice($rawComponents, 0, self::MAX_COMPONENTS);
        }

        $components = [];
        foreach ($rawComponents as $index => $component) {
            if (! is_array($component)) {
                $this->errors[] = "components[{$index}] must be an object.";

                continue;
            }
            $components[] = $this->component($component, $index, $repositoryPaths);
        }

        $names = array_column($components, 'name');
        if (count($names) !== count(array_unique($names))) {
            $this->errors[] = 'Component names must be unique.';
        }

        $totalFileBytes = 0;
        foreach ($components as $index => $component) {
            $this->validatePlaceholders($component, $index, $components, $databases);
            foreach ($component['files'] as $file) {
                $totalFileBytes += strlen($file['content']);
            }
        }
        if ($totalFileBytes > self::MAX_TOTAL_FILE_BYTES) {
            $this->errors[] = 'The generated files are larger than '.(self::MAX_TOTAL_FILE_BYTES / 1024).' KiB in total.';
        }

        if ($this->errors !== []) {
            throw new InvalidAiPlanException(array_values(array_unique($this->errors)));
        }

        return [
            'diagnosis' => $this->text($data['diagnosis'] ?? ''),
            'summary' => $this->text($data['summary'] ?? ''),
            'fixable' => ! array_key_exists('fixable', $data) || filter_var($data['fixable'], FILTER_VALIDATE_BOOLEAN),
            'components' => $components,
            'databases' => $databases,
        ];
    }

    /**
     * Identifies a plan by what it would change, so the same plan is not applied twice in a row.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function hash(array $plan): string
    {
        return hash('sha256', json_encode([
            'components' => $plan['components'] ?? [],
            'databases' => $plan['databases'] ?? [],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  list<string>|null  $repositoryPaths
     * @return array<string, mixed>
     */
    private function component(array $component, int $index, ?array $repositoryPaths): array
    {
        $prefix = "components[{$index}]";
        $name = strtolower(trim((string) ($component['name'] ?? '')));
        if (! preg_match(self::NAME_PATTERN, $name)) {
            $this->errors[] = "{$prefix}.name must use lowercase letters, digits and dashes (up to 40 characters).";
        }

        $buildPack = (string) ($component['build_pack'] ?? '');
        if (! in_array($buildPack, self::BUILD_PACKS, true)) {
            $this->errors[] = "{$prefix}.build_pack must be one of: ".implode(', ', self::BUILD_PACKS).'.';
        }

        $baseDirectory = $this->directory($component['base_directory'] ?? '/', "{$prefix}.base_directory") ?? '/';
        $isStatic = in_array($buildPack, ['nixpacks', 'railpack'], true) && $this->boolean($component['is_static'] ?? false);
        $servesStaticFiles = $isStatic || $buildPack === 'static';
        $isSpa = $servesStaticFiles && $this->boolean($component['is_spa'] ?? false);

        $portsExposes = null;
        if ($servesStaticFiles) {
            $portsExposes = '80';
        } elseif ($buildPack !== 'dockercompose') {
            $portsExposes = $this->ports($component['ports_exposes'] ?? null, "{$prefix}.ports_exposes");
        }

        $files = $this->files($component['files'] ?? [], $prefix);
        $filePaths = array_column($files, 'path');

        $dockerfileLocation = null;
        $dockerComposeLocation = null;
        if ($buildPack === 'dockerfile') {
            $dockerfileLocation = $this->filePath($component['dockerfile_location'] ?? '/Dockerfile', "{$prefix}.dockerfile_location");
            if ($dockerfileLocation !== null && ! in_array($dockerfileLocation, $filePaths, true) && ! $this->existsInRepository($repositoryPaths, $baseDirectory, $dockerfileLocation)) {
                $this->errors[] = "{$prefix}: the Dockerfile {$dockerfileLocation} is not in {$baseDirectory} of the repository and not in files.";
            }
        }
        if ($buildPack === 'dockercompose') {
            $dockerComposeLocation = $this->filePath($component['docker_compose_location'] ?? '/docker-compose.yaml', "{$prefix}.docker_compose_location");
            if ($dockerComposeLocation !== null && ! preg_match('/\.ya?ml$/', $dockerComposeLocation)) {
                $this->errors[] = "{$prefix}.docker_compose_location must end with .yaml or .yml.";
            }
            if ($dockerComposeLocation !== null && ! in_array($dockerComposeLocation, $filePaths, true) && ! $this->existsInRepository($repositoryPaths, $baseDirectory, $dockerComposeLocation)) {
                $this->errors[] = "{$prefix}: the Docker Compose file {$dockerComposeLocation} is not in {$baseDirectory} of the repository and not in files.";
            }
            foreach ($files as $file) {
                if ($file['path'] === $dockerComposeLocation) {
                    $this->validateComposeFile($file['content'], $prefix);
                }
            }
        }

        if ($repositoryPaths !== null && $baseDirectory !== '/' && ! $this->directoryExists($repositoryPaths, $baseDirectory)) {
            $this->errors[] = "{$prefix}.base_directory {$baseDirectory} does not exist in the repository.";
        }

        return [
            'name' => $name,
            'build_pack' => $buildPack,
            'base_directory' => $baseDirectory,
            'install_command' => $this->command($component['install_command'] ?? null, "{$prefix}.install_command"),
            'build_command' => $this->command($component['build_command'] ?? null, "{$prefix}.build_command"),
            'start_command' => $this->command($component['start_command'] ?? null, "{$prefix}.start_command"),
            'ports_exposes' => $portsExposes,
            'is_static' => $isStatic,
            'is_spa' => $isSpa,
            'publish_directory' => $servesStaticFiles ? $this->directory($component['publish_directory'] ?? null, "{$prefix}.publish_directory") : null,
            'dockerfile_location' => $dockerfileLocation,
            'docker_compose_location' => $dockerComposeLocation,
            'health_check_path' => $this->healthCheckPath($component['health_check_path'] ?? null, "{$prefix}.health_check_path"),
            'public' => $this->boolean($component['public'] ?? true),
            'env' => $this->environment($component['env'] ?? [], $prefix),
            'files' => $files,
        ];
    }

    /**
     * @return list<array{type: string}>
     */
    private function databases(mixed $databases): array
    {
        if ($databases === null || $databases === []) {
            return [];
        }
        if (! is_array($databases) || ! array_is_list($databases)) {
            $this->errors[] = '"databases" must be a list.';

            return [];
        }

        $types = [];
        foreach ($databases as $index => $database) {
            $type = strtolower(trim((string) (is_array($database) ? ($database['type'] ?? '') : $database)));
            $type = $type === 'postgres' ? 'postgresql' : $type;
            if (! in_array($type, self::DATABASE_TYPES, true)) {
                $this->errors[] = "databases[{$index}].type must be one of: ".implode(', ', self::DATABASE_TYPES).'.';

                continue;
            }
            $types[] = $type;
        }
        $types = array_values(array_unique($types));
        if (count($types) > self::MAX_DATABASES) {
            $this->errors[] = 'A plan can have at most '.self::MAX_DATABASES.' databases.';
        }

        return array_map(fn (string $type): array => ['type' => $type], $types);
    }

    /**
     * @return array<string, string>
     */
    private function environment(mixed $environment, string $prefix): array
    {
        if ($environment === null || $environment === []) {
            return [];
        }
        if (! is_array($environment) || array_is_list($environment)) {
            $this->errors[] = "{$prefix}.env must be an object of NAME: value pairs.";

            return [];
        }
        if (count($environment) > self::MAX_ENV_VARIABLES) {
            $this->errors[] = "{$prefix}.env can have at most ".self::MAX_ENV_VARIABLES.' variables.';
        }

        $result = [];
        foreach ($environment as $key => $value) {
            $key = (string) $key;
            if (strlen($key) > 128 || ! preg_match(ValidationPatterns::SHELL_ENVIRONMENT_VARIABLE_KEY_PATTERN, $key)) {
                $this->errors[] = "{$prefix}.env: {$key} is not a valid variable name.";

                continue;
            }
            foreach (self::RESERVED_ENV_PREFIXES as $reserved) {
                if (str_starts_with(strtoupper($key), $reserved)) {
                    $this->errors[] = "{$prefix}.env: {$key} is generated by Coolify and cannot be set.";

                    continue 2;
                }
            }
            if (! is_scalar($value) && $value !== null) {
                $this->errors[] = "{$prefix}.env.{$key} must be a text value.";

                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (strlen($value) > self::MAX_ENV_VALUE_LENGTH || str_contains($value, "\0")) {
                $this->errors[] = "{$prefix}.env.{$key} is too long or contains invalid characters.";

                continue;
            }
            $result[$key] = (string) preg_replace_callback(self::PLACEHOLDER_PATTERN, fn (array $matches): string => '{{'.$matches[1].'}}', $value);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  list<array<string, mixed>>  $components
     * @param  list<array{type: string}>  $databases
     */
    private function validatePlaceholders(array $component, int $index, array $components, array $databases): void
    {
        $byName = collect($components)->keyBy('name');
        $databaseTypes = array_column($databases, 'type');
        foreach ($component['env'] as $key => $value) {
            preg_match_all(self::PLACEHOLDER_PATTERN, $value, $matches);
            foreach ($matches[1] as $placeholder) {
                $where = "components[{$index}].env.{$key}";
                if (preg_match('/^component\.([a-z0-9-]+)\.(url|internal_url)$/', $placeholder, $parts)) {
                    $target = $byName->get($parts[1]);
                    if (! $target) {
                        $this->errors[] = "{$where} refers to the unknown component {$parts[1]}.";
                    } elseif ($target['build_pack'] === 'dockercompose') {
                        $this->errors[] = "{$where}: Docker Compose components have no single URL; use the SERVICE_URL variables inside the Compose file.";
                    } elseif ($parts[2] === 'url' && ! $target['public']) {
                        $this->errors[] = "{$where}: component {$parts[1]} is not public, so it has no public URL.";
                    }
                } elseif (preg_match('/^database\.([a-z]+)\.url$/', $placeholder, $parts)) {
                    if (! in_array($parts[1], $databaseTypes, true)) {
                        $this->errors[] = "{$where} refers to the database {$parts[1]}, which is not listed in databases.";
                    }
                } else {
                    $this->errors[] = "{$where} uses the unknown placeholder {{{$placeholder}}}.";
                }
            }
        }
    }

    /**
     * @return list<array{path: string, content: string}>
     */
    private function files(mixed $files, string $prefix): array
    {
        if ($files === null || $files === []) {
            return [];
        }
        if (! is_array($files) || ! array_is_list($files)) {
            $this->errors[] = "{$prefix}.files must be a list.";

            return [];
        }
        if (count($files) > self::MAX_FILES) {
            $this->errors[] = "{$prefix}.files can have at most ".self::MAX_FILES.' files.';
            $files = array_slice($files, 0, self::MAX_FILES);
        }

        $result = [];
        foreach ($files as $fileIndex => $file) {
            $where = "{$prefix}.files[{$fileIndex}]";
            if (! is_array($file) || ! is_string($file['content'] ?? null)) {
                $this->errors[] = "{$where} needs a path and a text content.";

                continue;
            }
            $path = $this->filePath($file['path'] ?? null, "{$where}.path");
            if ($path === null) {
                continue;
            }
            if (strlen($file['content']) > self::MAX_FILE_BYTES || str_contains($file['content'], "\0") || ! mb_check_encoding($file['content'], 'UTF-8')) {
                $this->errors[] = "{$where} is larger than ".(self::MAX_FILE_BYTES / 1024).' KiB or is not text.';

                continue;
            }
            if (in_array($path, array_column($result, 'path'), true)) {
                $this->errors[] = "{$where}: the path {$path} is listed twice.";

                continue;
            }
            $result[] = ['path' => $path, 'content' => $file['content']];
        }

        return $result;
    }

    private function validateComposeFile(string $content, string $prefix): void
    {
        try {
            $parsed = Yaml::parse($content);
        } catch (\Throwable $e) {
            $this->errors[] = "{$prefix}: the generated Docker Compose file is not valid YAML.";

            return;
        }
        if (! is_array($parsed) || ! is_array($parsed['services'] ?? null) || $parsed['services'] === []) {
            $this->errors[] = "{$prefix}: the generated Docker Compose file has no services.";

            return;
        }
        try {
            validateDockerComposeForInjection($content);
        } catch (\Throwable $e) {
            $this->errors[] = "{$prefix}: the generated Docker Compose file is not safe to use. ".strip_tags($e->getMessage());
        }
    }

    /**
     * null keeps the current command; an empty string clears it, so the build pack detects it.
     */
    private function command(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $this->errors[] = "{$field} must be a text or null.";

            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 1000 || ! preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $value)) {
            $this->errors[] = "{$field} contains characters Coolify does not allow in commands (; | > < $ ( ) backticks or line breaks).";

            return null;
        }

        return $value;
    }

    private function ports(mixed $value, string $field): ?string
    {
        $value = str_replace(' ', '', trim((string) $value));
        if ($value === '' || ! preg_match('/^\d{1,5}(,\d{1,5})*$/', $value)) {
            $this->errors[] = "{$field} must be a port number or a comma-separated list of port numbers.";

            return null;
        }
        foreach (explode(',', $value) as $port) {
            if ((int) $port < 1 || (int) $port > 65535) {
                $this->errors[] = "{$field} contains the invalid port {$port}.";

                return null;
            }
        }

        return $value;
    }

    private function directory(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            $this->errors[] = "{$field} must be a folder path.";

            return null;
        }
        $value = '/'.trim(trim($value), '/');
        if (strlen($value) > 255 || ! preg_match(ValidationPatterns::DIRECTORY_PATH_PATTERN, $value) || preg_match('~(^|/)\.\.?(/|$)~', $value)) {
            $this->errors[] = "{$field} must be a folder path like /backend.";

            return null;
        }

        return $value;
    }

    private function filePath(mixed $value, string $field): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->errors[] = "{$field} must be a file path like /Dockerfile.";

            return null;
        }
        $value = '/'.ltrim(trim($value), '/');
        if (strlen($value) > 255 || ! preg_match(ValidationPatterns::FILE_PATH_PATTERN, $value) || str_contains($value, '..') || str_ends_with($value, '/')) {
            $this->errors[] = "{$field} must be a file path like /Dockerfile.";

            return null;
        }

        return $value;
    }

    private function healthCheckPath(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > 255 || ! preg_match(self::HEALTH_CHECK_PATH_PATTERN, $value)) {
            $this->errors[] = "{$field} must be a path like /health.";

            return null;
        }

        return $value;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function text(mixed $value): string
    {
        return str((string) (is_scalar($value) ? $value : ''))->trim()->limit(2000)->value();
    }

    /**
     * @param  list<string>|null  $repositoryPaths
     */
    private function existsInRepository(?array $repositoryPaths, string $baseDirectory, string $file): bool
    {
        if ($repositoryPaths === null) {
            return true;
        }
        $relative = ltrim(rtrim($baseDirectory, '/').$file, '/');

        return in_array($relative, $repositoryPaths, true);
    }

    /**
     * @param  list<string>  $repositoryPaths
     */
    private function directoryExists(array $repositoryPaths, string $baseDirectory): bool
    {
        $prefix = trim($baseDirectory, '/').'/';
        foreach ($repositoryPaths as $path) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
