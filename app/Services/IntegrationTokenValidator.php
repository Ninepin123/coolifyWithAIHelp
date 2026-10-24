<?php

namespace App\Services;

use App\Models\IntegrationToken;
use App\Services\Ai\ChatCompletionClient;

/**
 * Validates an integration token against its provider API before it is saved.
 */
class IntegrationTokenValidator
{
    public function validate(string $provider, string $token, array $capabilities, array $metadata = []): bool
    {
        return match ($provider) {
            'cloudflare' => app(CloudflareTokenValidator::class)->validate($token, $capabilities),
            'doppler' => (new DopplerService($token))->validate(),
            'infisical' => (new InfisicalService(
                (string) data_get($metadata, 'base_url', 'https://app.infisical.com'),
                (string) data_get($metadata, 'client_id'),
                $token,
                IntegrationToken::requestTimeout($metadata),
            ))->validate(),
            'vault' => (new VaultService(
                (string) data_get($metadata, 'base_url'),
                $token,
                data_get($metadata, 'namespace'),
                IntegrationToken::requestTimeout($metadata),
            ))->validate(),
            'deepseek' => (new ChatCompletionClient(
                apiKey: $token,
                baseUrl: (string) (data_get($metadata, 'base_url') ?: ChatCompletionClient::DEFAULT_BASE_URL),
                model: (string) (data_get($metadata, 'model') ?: ChatCompletionClient::DEFAULT_MODEL),
            ))->validate(),
            default => false,
        };
    }

    public function errorMessage(string $provider): string
    {
        return match ($provider) {
            'cloudflare' => 'The token could not access the selected Cloudflare capabilities. Check its permissions and zone resources.',
            'doppler' => 'The Doppler token could not be verified. Check the token and its access.',
            'infisical' => 'Infisical login failed. Check the base URL, the client ID, and the client secret.',
            'vault' => 'The Vault token could not be verified. Check the base URL, the namespace, and the token.',
            'deepseek' => 'The DeepSeek API key could not be verified. Check the key and the base URL.',
            default => 'The token could not be verified.',
        };
    }
}
