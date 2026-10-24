<?php

namespace App\Services\Ai;

use App\Actions\Application\ApplyAiDeploymentPlan;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;

/**
 * Describes what applying a plan changes, for the review before the user applies it.
 */
class DeploymentPlanPreview
{
    private const FIELD_LABELS = [
        'build_pack' => 'Build pack',
        'base_directory' => 'Base directory',
        'install_command' => 'Install command',
        'build_command' => 'Build command',
        'start_command' => 'Start command',
        'ports_exposes' => 'Exposed ports',
        'is_static' => 'Static site',
        'is_spa' => 'Single-page app',
        'publish_directory' => 'Publish directory',
        'dockerfile_location' => 'Dockerfile location',
        'docker_compose_location' => 'Docker Compose location',
        'health_check_path' => 'Health check path',
    ];

    /**
     * @return list<array{name: string, application_name: string|null, is_new: bool, public: bool, changes: list<array{label: string, from: string, to: string}>, env: list<array{key: string, value: string, skipped: bool}>, files: list<array{path: string, content: string}>, files_removed: bool}>
     */
    public static function components(AiDeploymentAttempt $attempt): array
    {
        $plan = $attempt->plan ?? [];
        $root = $attempt->application;
        $created = self::previousComponentApplications($attempt);

        return collect($plan['components'] ?? [])
            ->map(function (array $component, int $index) use ($root, $created): array {
                $application = $index === 0 ? $root : ($created[$component['name']] ?? null);
                $existingKeys = $application ? $application->environment_variables()->pluck('key')->all() : [];

                return [
                    'name' => $component['name'],
                    'application_name' => $application?->name,
                    'is_new' => $application === null,
                    'public' => (bool) ($component['public'] ?? true),
                    'changes' => self::changes($component, $application),
                    'env' => collect($component['env'] ?? [])
                        ->map(fn (string $value, string $key): array => [
                            'key' => $key,
                            'value' => $value,
                            'skipped' => in_array($key, $existingKeys, true),
                        ])
                        ->values()
                        ->all(),
                    'files' => $component['files'] ?? [],
                    'files_removed' => ($component['files'] ?? []) === [] && $application !== null && $application->aiBuildFiles() !== [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, is_new: bool, name: string|null}>
     */
    public static function databases(AiDeploymentAttempt $attempt): array
    {
        $previous = AiDeploymentAttempt::query()
            ->where('application_id', $attempt->application_id)
            ->where('status', AiDeploymentAttempt::STATUS_APPLIED)
            ->whereKeyNot($attempt->id)
            ->pluck('created_resources')
            ->filter(fn (mixed $resources): bool => is_array($resources))
            ->flatten(1)
            ->where('type', 'database');

        return collect($attempt->plan['databases'] ?? [])
            ->map(function (array $database) use ($previous, $attempt): array {
                $modelClass = ApplyAiDeploymentPlan::DATABASE_MODELS[$database['type']] ?? null;
                $existing = $modelClass === null ? null : $previous
                    ->where('database_type', $database['type'])
                    ->map(fn (array $resource) => $modelClass::query()
                        ->where('uuid', $resource['uuid'])
                        ->where('environment_id', $attempt->application?->environment_id)
                        ->first())
                    ->filter()
                    ->last();

                return ['type' => $database['type'], 'is_new' => $existing === null, 'name' => $existing?->name];
            })
            ->all();
    }

    public static function databaseLabel(string $type): string
    {
        return match ($type) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'mongodb' => 'MongoDB',
            'redis' => 'Redis',
            default => $type,
        };
    }

    /**
     * @return array<string, Application>
     */
    private static function previousComponentApplications(AiDeploymentAttempt $attempt): array
    {
        $root = $attempt->application;
        if (! $root) {
            return [];
        }

        return AiDeploymentAttempt::query()
            ->where('application_id', $root->id)
            ->where('status', AiDeploymentAttempt::STATUS_APPLIED)
            ->whereKeyNot($attempt->id)
            ->orderBy('id')
            ->pluck('created_resources')
            ->filter(fn (mixed $resources): bool => is_array($resources))
            ->flatten(1)
            ->where('type', 'application')
            ->mapWithKeys(fn (array $resource): array => [
                $resource['component'] => Application::query()
                    ->where('uuid', $resource['uuid'])
                    ->where('environment_id', $root->environment_id)
                    ->first(),
            ])
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $component
     * @return list<array{label: string, from: string, to: string}>
     */
    private static function changes(array $component, ?Application $application): array
    {
        $current = [
            'build_pack' => $application?->build_pack,
            'base_directory' => $application?->base_directory,
            'install_command' => $application?->install_command,
            'build_command' => $application?->build_command,
            'start_command' => $application?->start_command,
            'ports_exposes' => $application?->ports_exposes,
            'is_static' => $application ? (bool) data_get($application, 'settings.is_static') : null,
            'is_spa' => $application ? (bool) data_get($application, 'settings.is_spa') : null,
            'publish_directory' => $application?->publish_directory,
            'dockerfile_location' => $application?->dockerfile_location,
            'docker_compose_location' => $application?->docker_compose_location,
            'health_check_path' => $application?->health_check_path,
        ];

        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $proposed = $component[$field] ?? null;
            if ($proposed === null) {
                continue;
            }
            if (in_array($field, ['is_static', 'is_spa'], true) && $application === null && $proposed === false) {
                continue;
            }
            $from = self::display($field, $current[$field]);
            $to = self::display($field, $proposed);
            if ($application !== null && $from === $to) {
                continue;
            }
            $changes[] = ['label' => $label, 'from' => $application === null ? '-' : $from, 'to' => $to];
        }

        return $changes;
    }

    private static function display(string $field, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (in_array($field, ['install_command', 'build_command', 'start_command'], true) && blank($value)) {
            return 'Automatic';
        }

        return blank($value) ? '-' : (string) $value;
    }
}
