<?php

use App\Livewire\Security\IntegrationTokenEditor;
use App\Livewire\Security\IntegrationTokenForm;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
    Http::preventStrayRequests();
});

test('a DeepSeek key is checked against the API and saved with the AI capability', function () {
    Http::fake(['https://api.deepseek.com/models' => Http::response(['data' => [['id' => 'deepseek-chat']]])]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'deepseek')
        ->assertSet('capabilities', ['ai'])
        ->set('name', 'DeepSeek')
        ->set('token', 'sk-valid')
        ->set('metadata.model', 'deepseek-reasoner')
        ->call('addToken')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $token = IntegrationToken::query()->sole();
    expect($token->only(['team_id', 'provider', 'capabilities', 'metadata']))->toBe([
        'team_id' => $this->team->id, 'provider' => 'deepseek', 'capabilities' => ['ai'], 'metadata' => ['model' => 'deepseek-reasoner'],
    ])->and($token->token)->toBe('sk-valid');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-valid'));
});

test('a rejected DeepSeek key is not saved', function () {
    Http::fake(['https://api.deepseek.com/models' => Http::response([], 401)]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'deepseek')
        ->set('name', 'DeepSeek')
        ->set('token', 'sk-rejected')
        ->call('addToken')
        ->assertDispatched('error');

    $this->assertDatabaseCount('integration_tokens', 0);
});

test('the API key is only sent to an HTTPS base URL', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'deepseek')
        ->set('name', 'DeepSeek')
        ->set('token', 'sk-valid')
        ->set('metadata.base_url', 'http://169.254.169.254/latest')
        ->call('addToken')
        ->assertHasErrors(['metadata.base_url']);

    Http::assertNothingSent();
    $this->assertDatabaseCount('integration_tokens', 0);
});

test('changing the base URL of a DeepSeek token requires the API key again', function () {
    $token = IntegrationToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'deepseek', 'capabilities' => ['ai'], 'token' => 'sk-stored']);

    Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $token->uuid])
        ->set('metadata.base_url', 'https://llm.example.com/v1')
        ->call('save')
        ->assertHasErrors(['newToken' => 'Enter the API key again when you change the base URL.']);

    Http::assertNothingSent();
    expect($token->fresh()->metadata)->toBeNull();
});
