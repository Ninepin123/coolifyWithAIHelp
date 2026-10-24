<?php

namespace App\Services\Ai;

use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\AiAssistantException;
use App\Jobs\AiDeploymentAssistJob;
use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts the AI deployment assistant for failed deployments, on request or automatically.
 */
class AiDeploymentAssistant
{
    /** Automatic attempts in a row, since the last successful deployment, before automatic fixing stops. */
    public const MAX_AUTOMATIC_ATTEMPTS = 3;

    /** Requests per team in 24 hours, to cap the provider costs. */
    public const DAILY_TEAM_LIMIT = 50;

    public const AUTOMATIC_START_DELAY_SECONDS = 20;

    public function tokenFor(Application $application): ?IntegrationToken
    {
        $teamId = $application->team()?->id;

        return $teamId === null ? null : IntegrationToken::aiTokenForTeam($teamId);
    }

    public function activeAttemptFor(Application $application): ?AiDeploymentAttempt
    {
        return $application->aiDeploymentAttempts()
            ->whereIn('status', AiDeploymentAttempt::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * @throws AiAssistantException
     */
    public function requestHelp(Application $application, ApplicationDeploymentQueue $deployment, ?User $user = null, string $trigger = AiDeploymentAttempt::TRIGGER_MANUAL): AiDeploymentAttempt
    {
        if ((int) $deployment->application_id !== (int) $application->id) {
            throw new AiAssistantException('The deployment does not belong to this application.');
        }
        if ($deployment->status !== ApplicationDeploymentStatus::FAILED->value) {
            throw new AiAssistantException('The AI deployment assistant only analyzes failed deployments.');
        }
        if ((int) $deployment->pull_request_id !== 0) {
            throw new AiAssistantException('The AI deployment assistant does not analyze preview deployments.');
        }
        $teamId = $application->team()?->id;
        $token = $this->tokenFor($application);
        if ($teamId === null || $token === null) {
            throw new AiAssistantException('Add a DeepSeek token in Security > Integration tokens to use the AI deployment assistant.');
        }

        $attempt = DB::transaction(function () use ($application, $deployment, $user, $trigger, $teamId, $token): AiDeploymentAttempt {
            Application::query()->whereKey($application->id)->lockForUpdate()->first();
            if ($this->activeAttemptFor($application)) {
                throw new AiAssistantException('The AI is already analyzing a deployment of this application.');
            }
            if ($this->attemptsInLastDay($teamId) >= self::DAILY_TEAM_LIMIT) {
                throw new AiAssistantException('Your team reached the limit of '.self::DAILY_TEAM_LIMIT.' AI deployment assistant requests in 24 hours.');
            }

            return AiDeploymentAttempt::query()->create([
                'team_id' => $teamId,
                'application_id' => $application->id,
                'integration_token_id' => $token->id,
                'deployment_uuid' => $deployment->deployment_uuid,
                'trigger' => $trigger,
                'status' => AiDeploymentAttempt::STATUS_PENDING,
                'requested_by' => $user?->id,
            ]);
        });

        $job = AiDeploymentAssistJob::dispatch($attempt->id);
        if ($trigger === AiDeploymentAttempt::TRIGGER_AUTOMATIC) {
            // The failed deployment writes its error summary to the log after this point.
            $job->delay(now()->addSeconds(self::AUTOMATIC_START_DELAY_SECONDS));
        }

        return $attempt;
    }

    /**
     * Starts an automatic attempt when the application has automatic fixes on. It never throws,
     * because it runs while a failed deployment is finished.
     */
    public function handleFailedDeployment(Application $application, ApplicationDeploymentQueue $deployment): ?AiDeploymentAttempt
    {
        try {
            if (! data_get($application, 'settings.is_ai_auto_fix_enabled')) {
                return null;
            }
            if ((int) $deployment->pull_request_id !== 0 || filled($deployment->parent_deployment_uuid)) {
                return null;
            }
            if ($this->tokenFor($application) === null) {
                return null;
            }
            if ($this->automaticAttemptsSinceLastSuccess($application) >= self::MAX_AUTOMATIC_ATTEMPTS) {
                $deployment->addLogEntry('The AI deployment assistant did not run: it already tried '.self::MAX_AUTOMATIC_ATTEMPTS.' automatic fixes since the last successful deployment.');

                return null;
            }

            $attempt = $this->requestHelp($application, $deployment, trigger: AiDeploymentAttempt::TRIGGER_AUTOMATIC);
            $deployment->addLogEntry('The AI deployment assistant is analyzing this failure and will apply its fix automatically.');

            return $attempt;
        } catch (AiAssistantException $e) {
            Log::info('AI deployment assistant did not start automatically: '.$e->getMessage());
        } catch (Throwable $e) {
            Log::warning('AI deployment assistant failed to start automatically', ['exception' => $e::class]);
        }

        return null;
    }

    public function automaticAttemptsSinceLastSuccess(Application $application): int
    {
        $lastSuccess = $application->deployment_queue()
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', 0)
            ->latest('id')
            ->value('created_at');

        return $application->aiDeploymentAttempts()
            ->where('trigger', AiDeploymentAttempt::TRIGGER_AUTOMATIC)
            ->when($lastSuccess, fn ($query) => $query->where('created_at', '>', $lastSuccess))
            ->count();
    }

    public function attemptsInLastDay(int $teamId): int
    {
        return AiDeploymentAttempt::query()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }
}
