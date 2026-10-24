@php
    $attempt = $this->attempt;
    $status = $attempt?->status;
    $statusBadge = match ($status) {
        'pending', 'analyzing' => ['label' => 'Analyzing', 'type' => 'warning', 'pulse' => true],
        'ready' => ['label' => $attempt->fixable ? 'Plan ready' : 'Needs your action', 'type' => $attempt->fixable ? 'success' : 'warning', 'pulse' => false],
        'applied' => ['label' => 'Applied', 'type' => 'success', 'pulse' => false],
        'failed' => ['label' => 'Failed', 'type' => 'error', 'pulse' => false],
        'dismissed' => ['label' => 'Dismissed', 'type' => 'neutral', 'pulse' => false],
        default => null,
    };
@endphp
<div @if ($attempt?->isActive()) wire:poll.3s="refreshAttempt" @endif>
    <x-application.settings-section id="ai-deployment-assistant-section" title="AI deployment assistant"
        helper="DeepSeek reads the repository file list, selected build files, the build settings, the names of the environment variables, and this log with secrets hidden, then proposes a Coolify configuration. Nothing changes until the plan is applied.">
        @if ($statusBadge)
            <x-slot:badges>
                <x-status-badge :label="$statusBadge['label']" :type="$statusBadge['type']" :pulse="$statusBadge['pulse']" />
            </x-slot:badges>
        @endif

        <div class="flex flex-col gap-4 text-[13px] text-neutral-700 dark:text-fg-dim">
            @if (! $this->hasToken)
                <p>Let AI find the cause of this failure and configure the build pack, the folders, the commands, and
                    the ports for you. Add a DeepSeek API key to use it.</p>
                @if ($this->canUpdate)
                    <div>
                        <a class="button" href="{{ route('security.integration-tokens') }}" {{ wireNavigate() }}>
                            Add a DeepSeek token
                        </a>
                    </div>
                @endif
            @elseif (! $attempt || in_array($status, ['failed', 'dismissed'], true))
                @if ($status === 'failed')
                    <x-callout type="danger" title="The AI could not finish">{{ $attempt->error }}</x-callout>
                @endif
                <p>Let AI find the cause of this failure and propose a configuration. For a repository with a
                    frontend and a backend, it can split it into one application per folder and add a database.</p>
                <div>
                    <x-forms.button wire:click="ask" isHighlighted canGate="update" :canResource="$application">
                        {{ $attempt ? 'Ask AI again' : 'Ask AI to fix' }}
                    </x-forms.button>
                </div>
            @elseif ($attempt->isActive())
                <x-loading text="Reading the repository and the log. This can take a few minutes." class="justify-start" />
            @else
                <div class="flex flex-col gap-2">
                    @if (filled($attempt->diagnosis))
                        <div>
                            <div class="text-[12px] font-semibold text-black dark:text-fg">Why it failed</div>
                            <p class="mt-0.5 whitespace-pre-line">{{ $attempt->diagnosis }}</p>
                        </div>
                    @endif
                    @if (filled($attempt->summary))
                        <div>
                            <div class="text-[12px] font-semibold text-black dark:text-fg">What the plan does</div>
                            <p class="mt-0.5 whitespace-pre-line">{{ $attempt->summary }}</p>
                        </div>
                    @endif
                </div>

                @if (! $attempt->fixable)
                    <x-callout type="warning" title="Configuration cannot fix this">
                        The AI thinks the code or a value only you know must change. Fix it as described above, then deploy again.
                    </x-callout>
                @endif

                @if ($attempt->fixable)
                    @foreach ($this->components as $component)
                        <div wire:key="ai-component-{{ $attempt->uuid }}-{{ $component['name'] }}"
                            class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-black dark:text-fg">{{ $component['name'] }}</span>
                                @if ($component['is_new'])
                                    <x-status-badge label="New application" type="success" />
                                @else
                                    <span class="truncate text-[12px] text-neutral-500 dark:text-fg-faint">{{ $component['application_name'] }}</span>
                                @endif
                                @if ($component['is_new'] && ! $component['public'])
                                    <x-status-badge label="Internal only" type="neutral" />
                                @endif
                            </div>

                            @if ($component['changes'] === [] && $component['env'] === [] && $component['files'] === [] && ! $component['files_removed'])
                                <p class="mt-2 text-[12px] text-neutral-500 dark:text-fg-faint">No setting changes. The application is deployed again.</p>
                            @endif

                            @if ($component['changes'] !== [])
                                <dl class="mt-2 grid gap-1 text-[12px]">
                                    @foreach ($component['changes'] as $change)
                                        <div class="grid gap-1 sm:grid-cols-[10rem_minmax(0,1fr)]">
                                            <dt class="text-neutral-500 dark:text-fg-faint">{{ $change['label'] }}</dt>
                                            <dd class="min-w-0 break-words font-mono">
                                                @unless ($component['is_new'])
                                                    <span class="text-neutral-500 line-through dark:text-fg-faint">{{ $change['from'] }}</span>
                                                    <span aria-hidden="true">→</span>
                                                @endunless
                                                <span class="text-black dark:text-fg">{{ $change['to'] }}</span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            @if ($component['env'] !== [])
                                <div class="mt-3 text-[12px]">
                                    <div class="font-medium text-black dark:text-fg">Environment variables to add</div>
                                    <ul class="mt-1 grid gap-0.5 font-mono">
                                        @foreach ($component['env'] as $variable)
                                            <li wire:key="ai-env-{{ $attempt->uuid }}-{{ $component['name'] }}-{{ $variable['key'] }}" class="break-all">
                                                <span @class(['line-through text-neutral-500 dark:text-fg-faint' => $variable['skipped']])>{{ $variable['key'] }}={{ $variable['value'] }}</span>
                                                @if ($variable['skipped'])
                                                    <span class="font-sans text-neutral-500 dark:text-fg-faint">(exists, kept)</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($component['files'] !== [])
                                <div class="mt-3 flex flex-col gap-1 text-[12px]">
                                    <div class="font-medium text-black dark:text-fg">Generated build files</div>
                                    @foreach ($component['files'] as $file)
                                        <details wire:key="ai-file-{{ $attempt->uuid }}-{{ $component['name'] }}-{{ $file['path'] }}"
                                            class="rounded-md border border-neutral-200 dark:border-white/[0.08]">
                                            <summary class="cursor-pointer px-2 py-1 font-mono">{{ $file['path'] }}</summary>
                                            <pre class="max-h-80 overflow-auto border-t border-neutral-200 p-2 text-[11px] leading-4 dark:border-white/[0.08]">{{ $file['content'] }}</pre>
                                        </details>
                                    @endforeach
                                </div>
                            @elseif ($component['files_removed'])
                                <p class="mt-3 text-[12px]">The build files that an earlier plan generated are removed.</p>
                            @endif
                        </div>
                    @endforeach

                    @if ($this->databases !== [])
                        <div class="text-[12px]">
                            <div class="font-medium text-black dark:text-fg">Databases</div>
                            <ul class="mt-1 grid gap-0.5">
                                @foreach ($this->databases as $database)
                                    <li wire:key="ai-db-{{ $attempt->uuid }}-{{ $database['type'] }}">
                                        {{ \App\Services\Ai\DeploymentPlanPreview::databaseLabel($database['type']) }}:
                                        {{ $database['is_new'] ? 'a new database is created and started' : 'uses '.$database['name'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif

                @if ($status === 'applied')
                    <x-callout type="success" title="Plan applied">
                        Applied {{ $attempt->applied_at?->diffForHumans() }}{{ $attempt->applier ? ' by '.$attempt->applier->name : ' automatically' }}.
                        @if ($this->deployments !== [])
                            <span class="mt-1 flex flex-wrap gap-x-3">
                                @foreach ($this->deployments as $deployment)
                                    <a class="underline" href="{{ $deployment['url'] }}" {{ wireNavigate() }}>{{ $deployment['name'] }} ({{ Str::headline($deployment['status']) }})</a>
                                @endforeach
                            </span>
                        @endif
                    </x-callout>
                @elseif (filled($attempt->error))
                    <x-callout type="warning" title="Not applied">{{ $attempt->error }}</x-callout>
                @endif

                @if ($status === 'ready')
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-forms.button wire:click="dismiss" canGate="update" :canResource="$application">Dismiss</x-forms.button>
                        <x-forms.button wire:click="ask" canGate="update" :canResource="$application">Ask again</x-forms.button>
                        @if ($attempt->fixable)
                            <x-forms.button wire:click="apply" isHighlighted canGate="update" :canResource="$application">
                                Apply and deploy
                            </x-forms.button>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </x-application.settings-section>
</div>
