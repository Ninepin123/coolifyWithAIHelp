<?php

use App\Livewire\Project\Application\AiAssistantSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpAiAssistantFixture($this);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->files = [
        ['path' => '/Dockerfile', 'content' => "FROM node:22\n"],
        ['path' => '/docker-compose.yaml', 'content' => "services:\n  web:\n    build: .\n"],
    ];
    $this->application->update(['ai_build_files' => $this->files]);
});

it('turns automatic fixes on and lists the generated build files', function () {
    Livewire::test(AiAssistantSettings::class, ['application' => $this->application])
        ->assertSee('/Dockerfile')
        ->assertSee('FROM node:22')
        ->set('isAiAutoFixEnabled', true)
        ->call('instantSave')
        ->assertDispatched('success');

    expect($this->application->settings->fresh()->is_ai_auto_fix_enabled)->toBeTrue();
});

it('removes one generated build file', function () {
    Livewire::test(AiAssistantSettings::class, ['application' => $this->application])
        ->call('removeBuildFile', '/Dockerfile')
        ->assertDispatched('success')
        ->assertDontSee('FROM node:22');

    expect($this->application->fresh()->aiBuildFiles())->toBe([$this->files[1]]);
});

it('shows how to add a token when the team has none', function () {
    $this->token->delete();

    Livewire::test(AiAssistantSettings::class, ['application' => $this->application])->assertSee('No DeepSeek token');
});

it('does not let a team member change the settings, even after switching to a team they own', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);
    $component = Livewire::test(AiAssistantSettings::class, ['application' => $this->application]);
    session(['currentTeam' => $ownTeam]);
    Cache::flush();

    $component->set('isAiAutoFixEnabled', true)->call('instantSave')->assertDispatched('error');
    $component->call('removeBuildFile', '/Dockerfile')->assertDispatched('error');

    expect($this->application->settings->fresh()->is_ai_auto_fix_enabled)->toBeFalse()
        ->and($this->application->fresh()->aiBuildFiles())->toBe($this->files);
});

it('does not show the settings of another team', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    Livewire::test(AiAssistantSettings::class, ['application' => $this->application])->assertForbidden();
});
