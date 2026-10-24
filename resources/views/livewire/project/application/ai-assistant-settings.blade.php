<div>
    <x-application.settings-section id="advanced-ai-assistant-section" title="AI deployment assistant"
        helper="When a deployment fails, DeepSeek can analyze the repository and the log and propose a Coolify configuration. Open the failed deployment to ask for help.">
        <div class="flex flex-col gap-4">
            @if (! $this->hasToken)
                <x-callout type="info" title="No DeepSeek token">
                    Add a DeepSeek token in
                    <a class="underline" href="{{ route('security.integration-tokens') }}" {{ wireNavigate() }}>Security &gt; Integration tokens</a>
                    to use the AI deployment assistant.
                </x-callout>
            @endif

            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isAiAutoFixEnabled" label="Automatic fixes" onChange="instantSave"
                    helper="When on, every failed deployment is sent to the AI, and its plan is applied and deployed without review. It stops after {{ \App\Services\Ai\AiDeploymentAssistant::MAX_AUTOMATIC_ATTEMPTS }} attempts in a row without a successful deployment."
                    :options="[
                        ['value' => false, 'label' => 'Review each plan before it is applied'],
                        ['value' => true, 'label' => 'Apply plans automatically'],
                    ]" :disabled="! $this->canUpdate" />
            </div>

            <div>
                <div class="text-[13px] font-medium text-black dark:text-fg">Generated build files</div>
                @if ($this->buildFiles === [])
                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-faint">None. The build uses only the files in the repository.</p>
                @else
                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-faint">
                        Written into the checkout before every build, relative to the base directory. They replace repository files with the same path.
                    </p>
                    <div class="mt-2 flex flex-col gap-2">
                        @foreach ($this->buildFiles as $file)
                            <details wire:key="ai-build-file-{{ md5($file['path']) }}"
                                class="rounded-md border border-neutral-200 text-[12px] dark:border-white/[0.08]">
                                <summary class="flex cursor-pointer items-center justify-between gap-2 px-2 py-1.5">
                                    <span class="truncate font-mono">{{ $file['path'] }}</span>
                                    <x-forms.button wire:click.stop="removeBuildFile('{{ $file['path'] }}')" isError
                                        canGate="update" :canResource="$application">Remove</x-forms.button>
                                </summary>
                                <pre class="max-h-80 overflow-auto border-t border-neutral-200 p-2 text-[11px] leading-4 dark:border-white/[0.08]">{{ $file['content'] }}</pre>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($this->recentAttempts->isNotEmpty())
                <div>
                    <div class="text-[13px] font-medium text-black dark:text-fg">Recent requests</div>
                    <ul class="mt-1 flex flex-col divide-y divide-neutral-200 text-[12px] dark:divide-white/[0.08]">
                        @foreach ($this->recentAttempts as $attempt)
                            <li wire:key="ai-attempt-{{ $attempt->uuid }}" class="flex flex-col gap-0.5 py-2 sm:flex-row sm:items-start sm:gap-3">
                                <span class="shrink-0 text-neutral-500 dark:text-fg-faint">{{ $attempt->created_at?->diffForHumans() }}</span>
                                <span class="shrink-0">
                                    <x-status-badge :label="Str::headline($attempt->status)"
                                        :type="match ($attempt->status) { 'applied' => 'success', 'failed' => 'error', 'ready', 'pending', 'analyzing' => 'warning', default => 'neutral' }" />
                                </span>
                                <span class="min-w-0 break-words">
                                    {{ $attempt->trigger === 'automatic' ? 'Automatic.' : '' }}
                                    {{ $attempt->summary ?: ($attempt->error ?: 'No summary.') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-application.settings-section>
</div>
