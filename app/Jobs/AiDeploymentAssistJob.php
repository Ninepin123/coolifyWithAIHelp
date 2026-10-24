<?php

namespace App\Jobs;

use App\Actions\Application\ApplyAiDeploymentPlan;
use App\Exceptions\AiAssistantException;
use App\Exceptions\InvalidAiPlanException;
use App\Models\AiDeploymentAttempt;
use App\Models\ApplicationDeploymentQueue;
use App\Models\IntegrationToken;
use App\Services\Ai\ChatCompletionClient;
use App\Services\Ai\DeploymentAssistantPrompt;
use App\Services\Ai\DeploymentContextCollector;
use App\Services\Ai\DeploymentPlanValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects the context of a failed deployment, asks the AI for a plan, and stores the checked plan
 * on the attempt. An automatic attempt also applies the plan.
 */
class AiDeploymentAssistJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Model calls per attempt: the first answer and one correction. */
    public const MAX_ROUNDS = 2;

    public int $tries = 1;

    public int $timeout = 900;

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    public function __construct(public int $attemptId)
    {
        $this->onQueue('high');
    }

    public function handle(DeploymentContextCollector $collector, DeploymentPlanValidator $validator): void
    {
        $attempt = AiDeploymentAttempt::query()->find($this->attemptId);
        if (! $attempt || $attempt->status !== AiDeploymentAttempt::STATUS_PENDING) {
            return;
        }
        $attempt->update(['status' => AiDeploymentAttempt::STATUS_ANALYZING]);

        try {
            $this->analyze($attempt, $collector, $validator);
        } catch (AiAssistantException $e) {
            $this->markFailed($attempt, $e->getMessage());

            return;
        } catch (Throwable $e) {
            // Remote errors can contain the clone command with a Git token, so the message is not stored.
            Log::warning('AI deployment assistant failed', ['attempt' => $attempt->uuid, 'exception' => $e::class]);
            $this->markFailed($attempt, 'The AI deployment assistant failed unexpectedly. Try again later.');

            return;
        }

        if ($attempt->trigger === AiDeploymentAttempt::TRIGGER_AUTOMATIC) {
            $this->applyAutomatically($attempt->fresh());
        }
    }

    public function failed(Throwable $exception): void
    {
        $attempt = AiDeploymentAttempt::query()->find($this->attemptId);
        if ($attempt?->isActive()) {
            $this->markFailed($attempt, 'The AI deployment assistant did not finish in time. Try again later.');
        }
    }

    /**
     * @throws AiAssistantException
     */
    private function analyze(AiDeploymentAttempt $attempt, DeploymentContextCollector $collector, DeploymentPlanValidator $validator): void
    {
        $application = $attempt->application;
        if (! $application || $application->trashed()) {
            throw new AiAssistantException('The application no longer exists.');
        }
        $deployment = ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', $attempt->deployment_uuid)
            ->where('application_id', $application->id)
            ->first();
        if (! $deployment) {
            throw new AiAssistantException('The deployment no longer exists.');
        }
        $token = $attempt->integrationToken ?? IntegrationToken::aiTokenForTeam((int) $attempt->team_id);
        if (! $token || (int) $token->team_id !== (int) $attempt->team_id || ! $token->isAiProvider()) {
            throw new AiAssistantException('Add a DeepSeek token in Security > Integration tokens to use the AI deployment assistant.');
        }
        $client = ChatCompletionClient::forToken($token);

        try {
            $context = $collector->collect($application, $deployment, $attempt);
        } catch (Throwable $e) {
            Log::debug('AI deployment assistant could not read the repository', ['exception' => $e::class]);

            throw new AiAssistantException('Coolify could not read the repository on the server. Check the repository access, the branch, and the server connection.');
        }
        $repositoryPaths = ($context['repository']['tree_truncated'] ?? true) ? null : ($context['repository']['all_paths'] ?? null);

        $messages = [
            ['role' => 'system', 'content' => DeploymentAssistantPrompt::system()],
            ['role' => 'user', 'content' => DeploymentAssistantPrompt::user($context)],
        ];

        for ($round = 1; ; $round++) {
            $result = $client->completeJson($messages);
            $this->promptTokens += $result->promptTokens;
            $this->completionTokens += $result->completionTokens;

            try {
                $plan = $validator->validate($result->data, $repositoryPaths);
            } catch (InvalidAiPlanException $e) {
                if ($round >= self::MAX_ROUNDS) {
                    throw $e;
                }
                $messages[] = ['role' => 'assistant', 'content' => $result->content];
                $messages[] = ['role' => 'user', 'content' => DeploymentAssistantPrompt::correction($e->errors)];

                continue;
            }

            $attempt->update([
                'status' => AiDeploymentAttempt::STATUS_READY,
                'plan' => $plan,
                'plan_hash' => DeploymentPlanValidator::hash($plan),
                'diagnosis' => $plan['diagnosis'],
                'summary' => $plan['summary'],
                'fixable' => $plan['fixable'],
                'model' => $result->model ?? $client->model(),
                'prompt_tokens' => $this->promptTokens,
                'completion_tokens' => $this->completionTokens,
                'error' => null,
            ]);

            return;
        }
    }

    private function applyAutomatically(AiDeploymentAttempt $attempt): void
    {
        if (! $attempt->canBeApplied()) {
            return;
        }
        $alreadyApplied = AiDeploymentAttempt::query()
            ->where('application_id', $attempt->application_id)
            ->whereKeyNot($attempt->id)
            ->where('status', AiDeploymentAttempt::STATUS_APPLIED)
            ->where('plan_hash', $attempt->plan_hash)
            ->exists();
        if ($alreadyApplied) {
            $attempt->update(['error' => 'Not applied automatically: the AI proposed a plan that was already applied before and did not fix the deployment.']);

            return;
        }

        try {
            ApplyAiDeploymentPlan::run($attempt);
        } catch (AiAssistantException $e) {
            $attempt->update(['error' => 'Not applied automatically: '.$e->getMessage()]);
        } catch (Throwable $e) {
            Log::warning('AI deployment assistant could not apply a plan automatically', ['attempt' => $attempt->uuid, 'exception' => $e::class]);
            $attempt->update(['error' => 'Not applied automatically because of an unexpected error. Review and apply the plan manually.']);
        }
    }

    private function markFailed(AiDeploymentAttempt $attempt, string $message): void
    {
        $attempt->update([
            'status' => AiDeploymentAttempt::STATUS_FAILED,
            'error' => $message,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ]);
    }
}
