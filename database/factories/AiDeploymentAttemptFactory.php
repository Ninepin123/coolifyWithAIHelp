<?php

namespace Database\Factories;

use App\Models\AiDeploymentAttempt;
use App\Models\Application;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDeploymentAttempt>
 */
class AiDeploymentAttemptFactory extends Factory
{
    protected $model = AiDeploymentAttempt::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'application_id' => Application::factory(),
            'deployment_uuid' => fake()->uuid(),
            'trigger' => AiDeploymentAttempt::TRIGGER_MANUAL,
            'status' => AiDeploymentAttempt::STATUS_PENDING,
        ];
    }

    public function ready(array $plan): static
    {
        return $this->state(fn (): array => [
            'status' => AiDeploymentAttempt::STATUS_READY,
            'plan' => $plan,
            'plan_hash' => hash('sha256', json_encode($plan)),
            'diagnosis' => $plan['diagnosis'] ?? null,
            'summary' => $plan['summary'] ?? null,
            'fixable' => $plan['fixable'] ?? true,
        ]);
    }
}
