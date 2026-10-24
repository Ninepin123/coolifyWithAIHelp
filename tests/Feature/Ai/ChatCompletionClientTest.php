<?php

use App\Exceptions\AiAssistantException;
use App\Services\Ai\ChatCompletionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function chatCompletionResponse(string $content, string $finishReason = 'stop'): array
{
    return [
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300],
    ];
}

it('sends the conversation in JSON mode and returns the JSON object with the token usage', function () {
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(chatCompletionResponse('{"summary":"ok","components":[]}'))]);

    $result = (new ChatCompletionClient('sk-test'))->completeJson([['role' => 'user', 'content' => 'Return json']]);

    expect($result->data)->toBe(['summary' => 'ok', 'components' => []])
        ->and($result->promptTokens)->toBe(1200)
        ->and($result->completionTokens)->toBe(300)
        ->and($result->model)->toBe('deepseek-chat');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'deepseek-chat'
        && $request['response_format'] === ['type' => 'json_object']);
});

it('does not ask a reasoning model for JSON mode and reads JSON inside a code fence', function () {
    Http::fake(['https://llm.example.com/v1/chat/completions' => Http::response(chatCompletionResponse("```json\n{\"summary\":\"ok\"}\n```"))]);

    $result = (new ChatCompletionClient('sk-test', 'https://llm.example.com/v1/', 'deepseek-reasoner'))->completeJson([['role' => 'user', 'content' => 'x']]);

    expect($result->data)->toBe(['summary' => 'ok']);
    Http::assertSent(fn (Request $request): bool => ! isset($request['response_format']));
});

it('explains provider errors without exposing the API key', function (int $status, string $message) {
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(['error' => ['message' => 'nope']], $status)]);

    expect(fn () => (new ChatCompletionClient('sk-secret-key'))->completeJson([['role' => 'user', 'content' => 'x']]))
        ->toThrow(function (AiAssistantException $e) use ($message) {
            expect($e->getMessage())->toContain($message)->not->toContain('sk-secret-key');
        });
})->with([
    'rejected key' => [401, 'rejected the API key'],
    'no balance' => [402, 'insufficient balance'],
    'rate limit' => [429, 'rate limit'],
    'server error' => [503, 'HTTP 503'],
]);

it('fails when the answer is not a JSON object or was cut off', function (string $content, string $finishReason, string $message) {
    Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(chatCompletionResponse($content, $finishReason))]);

    expect(fn () => (new ChatCompletionClient('sk-test'))->completeJson([['role' => 'user', 'content' => 'x']]))
        ->toThrow(AiAssistantException::class, $message);
})->with([
    'plain text' => ['I cannot help with that.', 'stop', 'did not return a valid JSON answer'],
    'a JSON list' => ['[1, 2]', 'stop', 'did not return a valid JSON answer'],
    'cut off' => ['{"summary": "too lo', 'length', 'cut off'],
]);

it('validates the API key with the model list', function (int $status, bool $valid) {
    Http::fake(['https://api.deepseek.com/models' => Http::response(['data' => []], $status)]);

    expect((new ChatCompletionClient('sk-test'))->validate())->toBe($valid);
})->with([[200, true], [401, false]]);
