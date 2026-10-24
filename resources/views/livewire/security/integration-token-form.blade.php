<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="addToken">
        <x-forms.listbox required id="provider" label="Provider" :live="true" :options="[
            ['value' => 'cloudflare', 'label' => 'Cloudflare'],
            ['value' => 'doppler', 'label' => 'Doppler'],
            ['value' => 'infisical', 'label' => 'Infisical'],
            ['value' => 'vault', 'label' => 'HashiCorp Vault'],
            ['value' => 'deepseek', 'label' => 'DeepSeek (AI deployment assistant)'],
        ]" />

        @if ($provider === 'infisical')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" label="Token name" placeholder="Production secrets" />
                <x-forms.input required id="metadata.client_id" label="Client ID"
                    placeholder="Machine identity client ID" />
                <x-forms.input required type="password" id="token" label="Client secret"
                    placeholder="Paste the machine identity client secret" />
                <x-forms.input required id="metadata.base_url" label="Base URL"
                    placeholder="https://app.infisical.com"
                    helper="Use the URL of your self-hosted Infisical instance, or the Infisical cloud URL." />
                <x-forms.input type="number" min="1" max="300" id="metadata.timeout" label="Request timeout (optional)"
                    placeholder="10" helper="Seconds to wait for each request. Increase it if your instance responds slowly." />
            </div>
        @elseif ($provider === 'deepseek')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" label="Token name" placeholder="DeepSeek" />
                <x-forms.input required type="password" id="token" label="API key"
                    placeholder="sk-..." />
                <x-forms.input id="metadata.model" label="Model (optional)"
                    placeholder="{{ \App\Services\Ai\ChatCompletionClient::DEFAULT_MODEL }}"
                    helper="Model name sent to the API. Leave blank for {{ \App\Services\Ai\ChatCompletionClient::DEFAULT_MODEL }}." />
                <x-forms.input id="metadata.base_url" label="Base URL (optional)"
                    placeholder="{{ \App\Services\Ai\ChatCompletionClient::DEFAULT_BASE_URL }}"
                    helper="HTTPS URL of an OpenAI-compatible chat completions API. Leave blank for DeepSeek." />
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" label="Token name"
                    placeholder="{{ $provider === 'cloudflare' ? 'Production DNS' : 'Production secrets' }}" />
                <x-forms.input required type="password" id="token"
                    label="{{ $provider === 'vault' ? 'Vault token' : 'API token' }}"
                    placeholder="Paste the provider token" />
            </div>
        @endif

        @if ($provider === 'vault')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="metadata.base_url" label="Base URL"
                    placeholder="https://vault.example.com:8200" />
                <x-forms.input id="metadata.namespace" label="Namespace (optional)"
                    placeholder="admin/team-a" helper="Only for Vault Enterprise / HCP Vault." />
                <x-forms.input type="number" min="1" max="300" id="metadata.timeout" label="Request timeout (optional)"
                    placeholder="10" helper="Seconds to wait for each request. Increase it if your instance responds slowly." />
            </div>
        @endif

        @if ($provider === 'cloudflare')
            <fieldset>
                <legend class="text-sm font-medium text-black dark:text-fg">Capabilities</legend>
                <div class="mt-3 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                    <x-forms.checkbox id="dns-capability" label="DNS" domValue="dns" fullWidth
                        :disabled="in_array('tunnel', $capabilities, true)" wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Manage Cloudflare DNS records.
                    </p>
                    <x-forms.checkbox id="tunnel-capability" label="Cloudflare Tunnel" domValue="tunnel" fullWidth
                        wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Create and manage tunnels for server SSH. Needs DNS.
                    </p>
                </div>
                @error('capabilities')
                    <span class="text-xs text-red-500">{{ $message }}</span>
                @enderror
            </fieldset>
            <div class="rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="automatic-dns" label="Automatically configure DNS" fullWidth
                    wire:model.live="automaticDns" canGate="create" :canResource="\App\Models\IntegrationToken::class" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    Create DNS records automatically when a new domain has one unambiguous matching credential.
                </p>
            </div>
            <div class="rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="proxied-dns" label="Proxy DNS records through Cloudflare" fullWidth
                    wire:model.live="proxiedDns" canGate="create" :canResource="\App\Models\IntegrationToken::class" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    Create new DNS records with the Cloudflare proxy on (orange cloud), so the server IP is not public. Existing records keep their proxy status.
                </p>
            </div>
        @elseif ($provider === 'deepseek')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Capability: AI deployment assistant</div>
                <p>When a deployment fails and you ask for help (or turn on automatic fixes for an application),
                    Coolify sends the file list of the repository, selected build files, the current build settings,
                    the names of the environment variables, and the deployment log with secrets hidden to this
                    provider. Environment variable values and <code>.env</code> files are never sent.</p>
            </div>
        @else
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Capability: Secrets (read-only)</div>
                <p>Coolify reads secrets from this provider at deployment time and writes them into the generated
                    <code>.env</code> file. Secret values are never stored in the Coolify database.</p>
            </div>
        @endif

        @if ($provider === 'cloudflare' && in_array('dns', $capabilities, true))
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Required Cloudflare permissions</div>
                <ul class="list-inside list-disc">
                    <li>Zone - DNS - Edit</li>
                    <li>Zone - Zone - Read</li>
                    @if (in_array('tunnel', $capabilities, true))
                        <li>Account - Cloudflare Tunnel - Edit</li>
                    @endif
                </ul>
                <p>Limit zone resources to the zones Coolify should manage.</p>
                <a href="{{ \App\Models\IntegrationToken::cloudflareTokenTemplateUrl($capabilities) }}"
                    target="_blank" rel="noopener noreferrer"
                    class="button mt-2">
                    Create token at Cloudflare
                    <x-external-link />
                </a>
            </div>
        @elseif ($provider === 'doppler')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Recommended Doppler token</div>
                <p>Use a read-only <span class="font-medium">Service Token</span> (dp.st.*). It is pinned to one
                    project and config. Create it in Doppler under Project &gt; Config &gt; Access.</p>
            </div>
        @elseif ($provider === 'infisical')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Infisical machine identity</div>
                <p>Create a machine identity with Universal Auth and read access to your project. Paste the client
                    ID above and the client secret in the secret field.</p>
            </div>
        @elseif ($provider === 'vault')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Vault token</div>
                <p>Use a token with read access to your KV v2 secrets. Prefer a periodic or long-lived token —
                    deployments fail when the token expires.</p>
            </div>
        @endif

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" wire:target="addToken" isHighlighted>
                Validate and add
            </x-forms.button>
        </div>
    </form>
</div>
