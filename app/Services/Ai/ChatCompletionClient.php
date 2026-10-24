<?php

namespace App\Services\Ai;

use App\Exceptions\AiAssistantException;
use App\Models\IntegrationToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the OpenAI-compatible chat completions API of DeepSeek.
 */
class ChatCompletionClient
{
    public const DEFAULT_BASE_URL = 'https://api.deepseek.com';

    public const DEFAULT_MODEL = 'deepseek-chat';

    public function __construct(
        private string $apiKey,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private string $model = self::DEFAULT_MODEL,
        private int $timeout = 300,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public static function forToken(IntegrationToken $token): self
    {
        return new self(
            apiKey: (string) $token->token,
            baseUrl: (string) (data_get($token->metadata, 'base_url') ?: self::DEFAULT_BASE_URL),
            model: (string) (data_get($token->metadata, 'model') ?: self::DEFAULT_MODEL),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Checks the API key with the model list endpoint, which costs no tokens.
     */
    public function validate(): bool
    {
        try {
            return $this->client(timeout: 15)->get($this->baseUrl.'/models')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sends the conversation and returns the JSON object of the answer.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws AiAssistantException
     */
    public function completeJson(array $messages, int $maxTokens = 8192): ChatCompletionResult
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];
        // Reasoning models do not accept the JSON output mode; their answer is parsed the same way.
        if (! $this->isReasoningModel()) {
            $payload['response_format'] = ['type' => 'json_object'];
            $payload['temperature'] = 0.2;
        }

        try {
            $response = $this->client(timeout: $this->timeout)->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new AiAssistantException('Could not reach the AI provider, or it did not answer in time. Try again later.');
        }

        if (! $response->successful()) {
            throw new AiAssistantException($this->errorMessage($response));
        }

        $content = (string) $response->json('choices.0.message.content', '');
        $data = self::decodeJsonObject($content);
        if ($data === null) {
            $reason = $response->json('choices.0.finish_reason') === 'length'
                ? 'The AI answer was cut off because it was too long.'
                : 'The AI provider did not return a valid JSON answer.';

            throw new AiAssistantException($reason);
        }

        return new ChatCompletionResult(
            data: $data,
            content: $content,
            model: $response->json('model'),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
        );
    }

    /**
     * The JSON object in a model answer. Some models wrap it in a Markdown code fence.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJsonObject(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }
        if (! str_starts_with($content, '{')) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start === false || $end === false || $end < $start) {
                return null;
            }
            $content = substr($content, $start, $end - $start + 1);
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function isReasoningModel(): bool
    {
        return str_contains(strtolower($this->model), 'reasoner');
    }

    private function errorMessage(Response $response): string
    {
        return match ($response->status()) {
            400, 422 => 'The AI provider rejected the request: '.str((string) ($response->json('error.message') ?? 'invalid request'))->limit(300),
            401, 403 => 'The AI provider rejected the API key. Check the DeepSeek token in Security > Integration tokens.',
            402 => 'The AI provider account has insufficient balance.',
            404 => 'The AI provider does not know the model or the URL. Check the model and the base URL of the token.',
            429 => 'The AI provider rate limit was reached. Try again in a few minutes.',
            default => 'The AI provider returned an error (HTTP '.$response->status().'). Try again later.',
        };
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($timeout);
    }
}
