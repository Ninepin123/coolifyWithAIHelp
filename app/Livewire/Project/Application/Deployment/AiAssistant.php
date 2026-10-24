<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Actions\Application\ApplyAiDeploymentPlan;
use App\Exceptions\AiAssistantException;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Ai\AiDeploymentAssistant;
use App\Services\Ai\DeploymentPlanPreview;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The AI deployment assistant panel on the page of a failed deployment.
 */
class AiAssistant extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Locked]
    public string $deploymentUuid;

    public function mount(Application $application, string $deploymentUuid): void
    {
        $this->authorize('view', $application);
        $this->application = $application;
        $this->deploymentUuid = $deploymentUuid;
    }

    #[Computed]
    public function attempt(): ?AiDeploymentAttempt
    {
        return $this->application->aiDeploymentAttempts()
            ->where('deployment_uuid', $this->deploymentUuid)
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function hasToken(): bool
    {
        return app(AiDeploymentAssistant::class)->tokenFor($this->application) !== null;
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->application);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function components(): array
    {
        $attempt = $this->attempt;

        return $attempt && is_array($attempt->plan) ? DeploymentPlanPreview::components($attempt) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function databases(): array
    {
        $attempt = $this->attempt;

        return $attempt && is_array($attempt->plan) ? DeploymentPlanPreview::databases($attempt) : [];
    }

    /**
     * @return list<array{name: string, status: string, url: string}>
     */
    #[Computed]
    public function deployments(): array
    {
        $uuids = $this->attempt?->deployment_uuids ?? [];
        if ($uuids === []) {
            return [];
        }

        return ApplicationDeploymentQueue::query()
            ->whereIn('deployment_uuid', $uuids)
            ->get()
            ->map(function (ApplicationDeploymentQueue $deployment): ?array {
                $application = $deployment->application;
                if (! $application || (int) $application->environment_id !== (int) $this->application->environment_id) {
                    return null;
                }

                return [
                    'name' => (string) $application->name,
                    'status' => (string) $deployment->status,
                    'url' => route('project.application.deployment.show', [
                        'project_uuid' => $application->environment->project->uuid,
                        'environment_uuid' => $application->environment->uuid,
                        'application_uuid' => $application->uuid,
                        'deployment_uuid' => $deployment->deployment_uuid,
                    ]),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function ask(AiDeploymentAssistant $assistant): void
    {
        try {
            $this->authorize('update', $this->application);
            $deployment = ApplicationDeploymentQueue::query()
                ->where('deployment_uuid', $this->deploymentUuid)
                ->where('application_id', $this->application->id)
                ->firstOrFail();
            $assistant->requestHelp($this->application, $deployment, auth()->user());
            $this->refreshAttempt();
            $this->dispatch('success', 'The AI is analyzing the failed deployment.');
        } catch (AiAssistantException $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function apply(): mixed
    {
        try {
            $this->authorize('update', $this->application);
            $this->authorize('deploy', $this->application);
            $attempt = $this->attempt;
            if (! $attempt) {
                throw new AiAssistantException('There is no plan to apply.');
            }
            $deploymentUuids = ApplyAiDeploymentPlan::run($attempt, auth()->user());
            $this->refreshAttempt();
            $this->dispatch('success', 'The plan was applied. The deployment started.');

            $application = $this->application->fresh();
            if ($deploymentUuids !== [] && $application && $application->deployment_queue()->where('deployment_uuid', $deploymentUuids[0])->exists()) {
                return $this->redirectRoute('project.application.deployment.show', [
                    'project_uuid' => $application->environment->project->uuid,
                    'environment_uuid' => $application->environment->uuid,
                    'application_uuid' => $application->uuid,
                    'deployment_uuid' => $deploymentUuids[0],
                ], navigate: true);
            }
        } catch (AiAssistantException $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function dismiss(): void
    {
        try {
            $this->authorize('update', $this->application);
            $attempt = $this->attempt;
            if ($attempt && $attempt->status === AiDeploymentAttempt::STATUS_READY) {
                $attempt->update(['status' => AiDeploymentAttempt::STATUS_DISMISSED]);
            }
            $this->refreshAttempt();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshAttempt(): void
    {
        unset($this->attempt, $this->components, $this->databases, $this->deployments);
    }

    public function render(): View
    {
        return view('livewire.project.application.deployment.ai-assistant');
    }
}
