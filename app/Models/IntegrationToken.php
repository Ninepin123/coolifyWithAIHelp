<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationToken extends BaseModel
{
    use HasFactory;

    public const SECRET_MANAGER_PROVIDERS = ['doppler', 'infisical', 'vault'];

    /**
     * Providers of the AI deployment assistant. They use the OpenAI-compatible chat completions API.
     */
    public const AI_PROVIDERS = ['deepseek'];

    public const PROVIDER_NAMES = [
        'cloudflare' => 'Cloudflare',
        'doppler' => 'Doppler',
        'infisical' => 'Infisical',
        'vault' => 'HashiCorp Vault',
        'deepseek' => 'DeepSeek',
    ];

    protected $fillable = [
        'team_id',
        'provider',
        'name',
        'token',
        'capabilities',
        'metadata',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function secretManagerLinks(): HasMany
    {
        return $this->hasMany(SecretManagerLink::class);
    }

    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsProviderZone::class);
    }

    public function managedDnsRecords(): HasMany
    {
        return $this->hasMany(ManagedDnsRecord::class);
    }

    public function isSecretManager(): bool
    {
        return in_array($this->provider, self::SECRET_MANAGER_PROVIDERS, true);
    }

    public function isAiProvider(): bool
    {
        return in_array($this->provider, self::AI_PROVIDERS, true);
    }

    /**
     * The newest AI token of a team, which the AI deployment assistant uses for the team's resources.
     */
    public static function aiTokenForTeam(int $teamId): ?self
    {
        return self::query()
            ->where('team_id', $teamId)
            ->whereIn('provider', self::AI_PROVIDERS)
            ->latest('id')
            ->first();
    }

    public function providerName(): string
    {
        return self::PROVIDER_NAMES[$this->provider] ?? ucfirst($this->provider);
    }

    /**
     * Capabilities a token of the provider can have. Cloudflare Tunnel setup also writes DNS records, so `tunnel` needs `dns`.
     *
     * @return array<int, string>
     */
    public static function allowedCapabilities(string $provider): array
    {
        return match (true) {
            $provider === 'cloudflare' => ['dns', 'tunnel'],
            in_array($provider, self::AI_PROVIDERS, true) => ['ai'],
            default => ['secrets'],
        };
    }

    /**
     * Validation rules for the metadata of an AI token. The base URL must use HTTPS because the API key
     * is sent to it with every request.
     *
     * @return array<string, array<int, string>>
     */
    public static function aiMetadataRules(): array
    {
        return [
            'metadata.base_url' => ['nullable', 'url:https', 'max:255'],
            'metadata.model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
        ];
    }

    /**
     * Cloudflare dashboard link that pre-fills a user API token with the permissions the capabilities need.
     *
     * @param  array<int, string>  $capabilities
     */
    public static function cloudflareTokenTemplateUrl(array $capabilities): string
    {
        $permissions = [
            ['key' => 'dns', 'type' => 'edit'],
            ['key' => 'zone', 'type' => 'read'],
        ];
        if (in_array('tunnel', $capabilities, true)) {
            // `argotunnel` is Cloudflare's internal key for the "Cloudflare Tunnel" permission group.
            $permissions[] = ['key' => 'argotunnel', 'type' => 'edit'];
        }

        return 'https://dash.cloudflare.com/profile/api-tokens?'.http_build_query([
            'permissionGroupKeys' => json_encode($permissions),
            'accountId' => '*',
            'zoneId' => 'all',
            'name' => in_array('tunnel', $capabilities, true) ? 'Coolify DNS and Tunnel Management' : 'Coolify DNS Management',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    public function automaticDnsEnabled(): bool
    {
        return $this->provider === 'cloudflare' && data_get($this->metadata, 'automatic_dns', true) !== false;
    }

    /** New Cloudflare records are created proxied (orange cloud) when enabled, so the server IP is not exposed. */
    public function proxiedDnsEnabled(): bool
    {
        return $this->provider === 'cloudflare' && data_get($this->metadata, 'proxied_dns') === true;
    }

    /**
     * Optional request timeout in seconds for self-hosted secret managers.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function requestTimeout(array $metadata): ?int
    {
        return filled($metadata['timeout'] ?? null) ? (int) $metadata['timeout'] : null;
    }

    public function dopplerTokenType(): ?string
    {
        if ($this->provider !== 'doppler') {
            return null;
        }

        return match (true) {
            str_starts_with($this->token, 'dp.st.') => 'service',
            str_starts_with($this->token, 'dp.sa.') => 'service_account',
            default => null,
        };
    }

    public static function ownedByCurrentTeam()
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
