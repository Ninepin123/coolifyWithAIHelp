<?php

namespace App\Actions\Application;

use App\Actions\Database\StartDatabase;
use App\Exceptions\AiAssistantException;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Applies a checked plan of the AI deployment assistant: reconfigures the application, creates or
 * updates the applications of further components and the databases, adds environment variables,
 * and queues a deployment of every component.
 */
class ApplyAiDeploymentPlan
{
    use AsAction;

    /** @var array<string, class-string> */
    public const DATABASE_MODELS = [
        'postgresql' => StandalonePostgresql::class,
        'mysql' => StandaloneMysql::class,
        'mariadb' => StandaloneMariadb::class,
        'mongodb' => StandaloneMongodb::class,
        'redis' => StandaloneRedis::class,
    ];

    /**
     * @return list<string> The UUIDs of the queued deployments.
     *
     * @throws AiAssistantException
     */
    public function handle(AiDeploymentAttempt $attempt, ?User $user = null): array
    {
        [$attempt, $applications, $newDatabases] = DB::transaction(function () use ($attempt, $user): array {
            $attempt = AiDeploymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (! $attempt->canBeApplied()) {
                throw new AiAssistantException('This plan can no longer be applied.');
            }
            $root = $attempt->application;
            if (! $root || $root->trashed()) {
                throw new AiAssistantException('The application no longer exists.');
            }

            $plan = $attempt->plan;
            $previous = $this->previouslyCreatedResources($root);
            $createdResources = [];

            [$databases, $newDatabases] = $this->databases($plan['databases'] ?? [], $root, $previous, $createdResources);

            $applications = [];
            foreach ($plan['components'] as $index => $component) {
                $application = $index === 0
                    ? $root
                    : $this->componentApplication($root, $component, $previous, $createdResources);
                $this->configure($application, $component, isNew: $index !== 0 && $application->wasRecentlyCreated);
                $applications[$component['name']] = $application;
            }

            foreach ($plan['components'] as $component) {
                $this->addEnvironmentVariables($applications[$component['name']], $component['env'] ?? [], $applications, $databases);
            }

            $attempt->update([
                'status' => AiDeploymentAttempt::STATUS_APPLIED,
                'applied_by' => $user?->id,
                'applied_at' => now(),
                'created_resources' => $createdResources,
                'error' => null,
            ]);

            return [$attempt, $applications, $newDatabases];
        });

        foreach ($newDatabases as $database) {
            try {
                StartDatabase::dispatch($database);
            } catch (Throwable $e) {
                Log::warning('Could not start a database created by the AI deployment assistant', ['database' => $database->uuid, 'exception' => $e::class]);
            }
        }

        $deploymentUuids = [];
        foreach ($applications as $application) {
            $deploymentUuid = new_public_id();
            $result = queue_application_deployment(application: $application->fresh(), deployment_uuid: $deploymentUuid, force_rebuild: true);
            if (($result['status'] ?? null) === 'queued') {
                $deploymentUuids[] = $deploymentUuid;
            }
        }
        $attempt->update(['deployment_uuids' => $deploymentUuids]);

        $root = $attempt->application;
        auditLog($user ? 'ui.application.ai_plan_applied' : 'system.application.ai_plan_applied', [
            'team_id' => $attempt->team_id,
            'application_uuid' => $root?->uuid,
            'application_name' => $root?->name,
            'ai_attempt_uuid' => $attempt->uuid,
            'deployment_uuids' => $deploymentUuids,
        ]);

        return $deploymentUuids;
    }

    /**
     * Resources earlier plans of this application created, so applying a plan again reuses them.
     *
     * @return Collection<int, array<string, string>>
     */
    private function previouslyCreatedResources(Application $root): Collection
    {
        return AiDeploymentAttempt::query()
            ->where('application_id', $root->id)
            ->where('status', AiDeploymentAttempt::STATUS_APPLIED)
            ->orderBy('id')
            ->pluck('created_resources')
            ->filter(fn (mixed $resources): bool => is_array($resources))
            ->flatten(1)
            ->filter(fn (mixed $resource): bool => is_array($resource))
            ->values();
    }

    /**
     * @param  list<array{type: string}>  $requested
     * @param  Collection<int, array<string, string>>  $previous
     * @param  list<array<string, string>>  $createdResources
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     */
    private function databases(array $requested, Application $root, Collection $previous, array &$createdResources): array
    {
        $databases = [];
        $newDatabases = [];
        foreach ($requested as $database) {
            $type = $database['type'];
            $modelClass = self::DATABASE_MODELS[$type] ?? null;
            if ($modelClass === null) {
                throw new AiAssistantException("The database type {$type} is not supported.");
            }

            $existing = $previous
                ->where('type', 'database')
                ->where('database_type', $type)
                ->reverse()
                ->map(fn (array $resource) => $modelClass::query()
                    ->where('uuid', $resource['uuid'])
                    ->where('environment_id', $root->environment_id)
                    ->first())
                ->filter()
                ->first();

            if ($existing) {
                $databases[$type] = $existing;
                $createdResources[] = ['type' => 'database', 'database_type' => $type, 'uuid' => $existing->uuid];

                continue;
            }

            $name = str("{$root->name}-{$type}")->limit(240, '')->value();
            $created = match ($type) {
                'postgresql' => create_standalone_postgresql($root->environment_id, $root->destination, ['name' => $name]),
                'mysql' => create_standalone_mysql($root->environment_id, $root->destination, ['name' => $name]),
                'mariadb' => create_standalone_mariadb($root->environment_id, $root->destination, ['name' => $name]),
                'mongodb' => create_standalone_mongodb($root->environment_id, $root->destination, ['name' => $name]),
                'redis' => create_standalone_redis($root->environment_id, $root->destination, ['name' => $name]),
            };
            // Load the column defaults (user and database name), which the connection URL needs.
            $created->refresh();
            $databases[$type] = $created;
            $newDatabases[] = $created;
            $createdResources[] = ['type' => 'database', 'database_type' => $type, 'uuid' => $created->uuid];
        }

        return [$databases, $newDatabases];
    }

    /**
     * The application of a further component: the one an earlier plan created with the same name,
     * or a new application from the same repository, branch, source, and destination.
     *
     * @param  array<string, mixed>  $component
     * @param  Collection<int, array<string, string>>  $previous
     * @param  list<array<string, string>>  $createdResources
     */
    private function componentApplication(Application $root, array $component, Collection $previous, array &$createdResources): Application
    {
        $existing = $previous
            ->where('type', 'application')
            ->where('component', $component['name'])
            ->reverse()
            ->map(fn (array $resource): ?Application => Application::query()
                ->where('uuid', $resource['uuid'])
                ->where('environment_id', $root->environment_id)
                ->first())
            ->filter()
            ->first();

        if (! $existing) {
            $existing = new Application([
                'name' => str("{$root->name}-{$component['name']}")->limit(250, '')->value(),
                'git_repository' => $root->git_repository,
                'git_branch' => $root->git_branch,
                'git_commit_sha' => $root->git_commit_sha ?: 'HEAD',
                'environment_id' => $root->environment_id,
                'destination_id' => $root->destination_id,
                'destination_type' => $root->destination_type,
                'source_id' => $root->source_id,
                'source_type' => $root->source_type,
                'private_key_id' => $root->private_key_id,
                'repository_project_id' => $root->repository_project_id,
                'build_pack' => $component['build_pack'],
                'base_directory' => $component['base_directory'],
                'ports_exposes' => $component['ports_exposes'] ?? '3000',
            ]);
            $existing->save();

            $rootSettings = $root->settings;
            $existing->settings->forceFill([
                'is_ai_auto_fix_enabled' => (bool) $rootSettings?->is_ai_auto_fix_enabled,
                'is_auto_deploy_enabled' => (bool) ($rootSettings?->is_auto_deploy_enabled ?? true),
                'is_git_submodules_enabled' => (bool) $rootSettings?->is_git_submodules_enabled,
                'is_git_lfs_enabled' => (bool) $rootSettings?->is_git_lfs_enabled,
                'is_git_shallow_clone_enabled' => (bool) ($rootSettings?->is_git_shallow_clone_enabled ?? true),
            ])->save();
        }

        $createdResources[] = ['type' => 'application', 'component' => $component['name'], 'uuid' => $existing->uuid];

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function configure(Application $application, array $component, bool $isNew): void
    {
        $application->loadMissing('settings');
        $previousPorts = $application->ports_exposes;
        $previousFqdn = $application->fqdn;
        $previousBuildPack = $application->build_pack;
        $wasSpa = (bool) $application->settings->is_spa;

        $application->build_pack = $component['build_pack'];
        $application->base_directory = $component['base_directory'];
        foreach (['install_command', 'build_command', 'start_command'] as $field) {
            if ($component[$field] !== null) {
                $application->{$field} = $component[$field] === '' ? null : $component[$field];
            }
        }
        if ($component['ports_exposes'] !== null) {
            $application->ports_exposes = $component['ports_exposes'];
        }
        if ($component['publish_directory'] !== null) {
            $application->publish_directory = $component['publish_directory'];
        }
        if ($component['dockerfile_location'] !== null) {
            $application->dockerfile_location = $component['dockerfile_location'];
        }
        if ($component['docker_compose_location'] !== null) {
            $application->docker_compose_location = $component['docker_compose_location'];
        }
        if ($component['health_check_path'] !== null) {
            $application->health_check_path = $component['health_check_path'];
        }
        $application->ai_build_files = $component['files'] === [] ? null : $component['files'];

        if ($component['build_pack'] !== 'dockercompose') {
            if ($component['public'] && blank($application->fqdn)) {
                $application->fqdn = generateUrl(server: $application->destination->server, random: $application->uuid);
            } elseif (! $component['public'] && $isNew) {
                $application->fqdn = null;
            }
        }
        $application->save();

        $servesStaticFiles = $component['is_static'] || $component['build_pack'] === 'static';
        $application->settings->is_static = $component['is_static'];
        $application->settings->is_spa = $component['is_spa'];
        $application->settings->save();

        if ($servesStaticFiles && (blank($application->custom_nginx_configuration) || $wasSpa !== $component['is_spa'])) {
            $application->custom_nginx_configuration = defaultNginxConfiguration($component['is_spa'] ? 'spa' : 'static');
            $application->save();
        }

        $routingChanged = $previousPorts !== $application->ports_exposes
            || $previousFqdn !== $application->fqdn
            || $previousBuildPack !== $application->build_pack;
        if ($component['build_pack'] !== 'dockercompose' && $routingChanged && $application->settings->is_container_label_readonly_enabled) {
            $application->refresh();
            $application->custom_labels = base64_encode(implode("\n", generateLabelsApplication($application)));
            $application->save();
        }
    }

    /**
     * Adds the variables that do not exist yet. Existing variables keep their values, because the AI
     * never sees them.
     *
     * @param  array<string, string>  $environment
     * @param  array<string, Application>  $applications
     * @param  array<string, mixed>  $databases
     */
    private function addEnvironmentVariables(Application $application, array $environment, array $applications, array $databases): void
    {
        if ($environment === []) {
            return;
        }
        $existingKeys = $application->environment_variables()->pluck('key')->all();
        foreach ($environment as $key => $value) {
            if (in_array($key, $existingKeys, true)) {
                continue;
            }
            EnvironmentVariable::query()->create([
                'key' => $key,
                'value' => $this->resolvePlaceholders($value, $applications, $databases),
                'is_buildtime' => true,
                'is_runtime' => true,
                'is_preview' => false,
                'resourceable_type' => $application->getMorphClass(),
                'resourceable_id' => $application->id,
            ]);
        }
    }

    /**
     * @param  array<string, Application>  $applications
     * @param  array<string, mixed>  $databases
     */
    public function resolvePlaceholders(string $value, array $applications, array $databases): string
    {
        return (string) preg_replace_callback('/\{\{(component\.([a-z0-9-]+)\.(url|internal_url)|database\.([a-z]+)\.url)\}\}/', function (array $matches) use ($applications, $databases): string {
            if (($matches[4] ?? '') !== '') {
                return (string) data_get($databases[$matches[4]] ?? null, 'internal_db_url', '');
            }
            $application = $applications[$matches[2]] ?? null;
            if (! $application) {
                return '';
            }
            if ($matches[3] === 'url') {
                return trim((string) str((string) $application->fqdn)->explode(',')->first());
            }

            return $this->internalUrl($application);
        }, $value);
    }

    /**
     * A URL inside the Docker network. The application UUID is added as a network alias, because the
     * container name changes with every deployment.
     */
    private function internalUrl(Application $application): string
    {
        $aliases = $application->custom_network_aliases_array ?? [];
        if (! in_array($application->uuid, $aliases, true)) {
            $aliases[] = $application->uuid;
            $application->custom_network_aliases = implode(',', $aliases);
            $application->save();
        }
        $port = $application->settings?->is_static ? 80 : (int) (str((string) $application->ports_exposes)->explode(',')->first() ?: 80);

        return "http://{$application->uuid}:{$port}";
    }
}
