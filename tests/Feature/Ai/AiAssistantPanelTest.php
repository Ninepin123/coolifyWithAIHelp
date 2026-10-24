<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\AiDeploymentAssistJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\Deployment\AiAssistant;
use App\Models\AiDeploymentAttempt;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpAiAssistantFixture($this);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'failed-deploy', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FAILED->value, 'pull_request_id' => 0,
    ]);
});

function aiPanel(object $test): Testable
{
    return Livewire::test(AiAssistant::class, ['application' => $test->application, 'deploymentUuid' => 'failed-deploy']);
}

function readyPanelAttempt(object $test, array $plan): AiDeploymentAttempt
{
    return AiDeploymentAttempt::factory()->ready($plan)->create([
        'team_id' => $test->team->id, 'application_id' => $test->application->id, 'deployment_uuid' => 'failed-deploy',
    ]);
}

/**
 * A user who is a member of the application's team and the owner of another team, with the
 * session switched to the other team after the component was mounted.
 */
function switchToMemberOfApplicationTeam(object $test): Testable
{
    $member = User::factory()->create();
    $test->team->members()->attach($member->id, ['role' => 'member']);
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($member->id, ['role' => 'owner']);
    $test->actingAs($member);
    session(['currentTeam' => $test->team]);
    $component = aiPanel($test);
    session(['currentTeam' => $ownTeam]);
    Cache::flush();

    return $component;
}

it('shows the assistant on the page of a failed deployment only', function () {
    $route = fn (ApplicationDeploymentQueue $deployment) => route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid, 'deployment_uuid' => $deployment->deployment_uuid,
    ]);
    $finished = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'finished-deploy', 'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    $this->get($route($this->deployment))->assertSuccessful()->assertSee('AI deployment assistant')->assertSee('Ask AI to fix');
    $this->get($route($finished))->assertSuccessful()->assertDontSee('Ask AI to fix');
});

it('asks the AI to analyze the failure', function () {
    Queue::fake([AiDeploymentAssistJob::class]);

    aiPanel($this)->call('ask')->assertDispatched('success')->assertSee('Reading the repository and the log');

    expect(AiDeploymentAttempt::query()->sole()->requested_by)->toBe($this->user->id);
    Queue::assertPushed(AiDeploymentAssistJob::class, 1);
});

it('links to the token settings when the team has no DeepSeek token', function () {
    $this->token->delete();

    aiPanel($this)->assertSee('Add a DeepSeek token')->assertDontSee('Ask AI to fix');
});

it('shows what the plan changes and applies it', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    readyPanelAttempt($this, aiTestPlan([
        'env' => ['PYTHONUNBUFFERED' => '1'],
        'files' => [['path' => '/Procfile', 'content' => 'web: uvicorn main:app']],
    ]));

    $component = aiPanel($this)
        ->assertSee('The app listens on localhost.')
        ->assertSeeInOrder(['Exposed ports', '3000', '8000'])
        ->assertSee('PYTHONUNBUFFERED=1')
        ->assertSee('/Procfile')
        ->assertSee('Apply and deploy')
        ->call('apply');

    $newDeployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', '!=', 'failed-deploy')->sole();
    $component->assertRedirect(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid, 'deployment_uuid' => $newDeployment->deployment_uuid,
    ]));
    expect($this->application->fresh()->ports_exposes)->toBe('8000')
        ->and(AiDeploymentAttempt::query()->sole()->status)->toBe(AiDeploymentAttempt::STATUS_APPLIED);
});

it('explains a failure that configuration cannot fix without offering to apply it', function () {
    readyPanelAttempt($this, aiTestPlan([], ['fixable' => false, 'diagnosis' => 'main.py has a syntax error on line 3.']));

    aiPanel($this)->assertSee('main.py has a syntax error on line 3.')
        ->assertSee('Configuration cannot fix this')
        ->assertDontSee('Apply and deploy');
});

it('dismisses a plan', function () {
    $attempt = readyPanelAttempt($this, aiTestPlan());

    aiPanel($this)->call('dismiss')->assertSee('Ask AI again');

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_DISMISSED);
});

it('does not let a team member ask, apply, or dismiss, even after switching to a team they own', function () {
    Queue::fake([AiDeploymentAssistJob::class, ApplicationDeploymentJob::class]);
    $attempt = readyPanelAttempt($this, aiTestPlan());
    $component = switchToMemberOfApplicationTeam($this);

    $component->call('apply')->assertDispatched('error');
    $component->call('dismiss')->assertDispatched('error');
    $attempt->update(['status' => AiDeploymentAttempt::STATUS_FAILED]);
    $component->call('ask')->assertDispatched('error');

    expect($attempt->fresh()->status)->toBe(AiDeploymentAttempt::STATUS_FAILED)
        ->and(AiDeploymentAttempt::query()->count())->toBe(1)
        ->and($this->application->fresh()->ports_exposes)->toBe('3000');
    Queue::assertNothingPushed();
});

it('does not show the assistant of another team', function () {
    $outsider = User::factory()->create();
    $outsiderTeam = Team::factory()->create();
    $outsiderTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $outsiderTeam]);

    aiPanel($this)->assertForbidden();
});
