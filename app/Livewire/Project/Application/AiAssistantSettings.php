<?php

namespace App\Livewire\Project\Application;

use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Services\Ai\AiDeploymentAssistant;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Settings of the AI deployment assistant for one application: automatic fixes, the generated build
 * files, and the recent requests.
 */
class AiAssistantSettings extends Component
{
    use AuditsApplicationSettings, AuthorizesRequests;

    public Application $application;

    public bool $isAiAutoFixEnabled = false;

    public function mount(Application $application): void
    {
        $this->authorize('view', $application);
        $this->application = $application;
        $this->isAiAutoFixEnabled = (bool) $application->settings->is_ai_auto_fix_enabled;
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
     * @return list<array{path: string, content: string}>
     */
    #[Computed]
    public function buildFiles(): array
    {
        return $this->application->aiBuildFiles();
    }

    /**
     * @return Collection<int, AiDeploymentAttempt>
     */
    #[Computed]
    public function recentAttempts(): Collection
    {
        return $this->application->aiDeploymentAttempts()->latest('id')->limit(5)->get();
    }

    public function instantSave(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate(['isAiAutoFixEnabled' => ['boolean']]);
            $this->application->settings->is_ai_auto_fix_enabled = $this->isAiAutoFixEnabled;
            $this->saveApplicationSettingsWithAudit($this->application);
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->isAiAutoFixEnabled = (bool) $this->application->settings->fresh()?->is_ai_auto_fix_enabled;
            handleError($e, $this);
        }
    }

    public function removeBuildFile(string $path): void
    {
        try {
            $this->authorize('update', $this->application);
            $files = collect($this->application->aiBuildFiles())
                ->reject(fn (array $file): bool => $file['path'] === $path)
                ->values()
                ->all();
            $this->application->ai_build_files = $files === [] ? null : $files;
            $this->application->save();
            unset($this->buildFiles);

            auditLog('ui.application.ai_build_file_removed', [
                'team_id' => $this->application->team()?->id,
                'application_uuid' => $this->application->uuid,
                'application_name' => $this->application->name,
                'path' => $path,
            ]);
            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'Build file removed. Deploy again to use the repository file.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.application.ai-assistant-settings');
    }
}
