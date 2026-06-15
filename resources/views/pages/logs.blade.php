@php
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Str;

    $levelPill = function (int $value): string {
        return match (true) {
            $value >= 500 => 'is-danger',   // critical / alert / emergency
            $value >= 400 => 'is-danger',   // error
            $value >= 300 => 'is-warn',     // warning
            $value >= 250 => 'is-info',     // notice
            default => 'is-neutral',        // info / debug
        };
    };
@endphp

<div wire:poll.visible.10s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Logs</h1>
            <p class="v-page-sub">Searchable application logs, correlated to the trace that emitted them.</p>
        </div>
        <span class="text-xs v-muted v-num">{{ $logs->count() }} shown</span>
    </div>

    @unless ($enabled)
        <div class="v-card v-card--pad text-sm" role="status" style="border-color: var(--v-warn); background: var(--v-warn-bg); color: var(--v-warn);">
            <span class="font-semibold">Log capture is off.</span>
            Set <code class="font-mono">VIGILANCE_LOGS=true</code> (or <code class="font-mono">vigilance.logs.enabled</code>) to start
            collecting <code class="font-mono">Log::*</code> records here. Existing rows are still browsable below.
        </div>
    @endunless

    {{-- Filters --}}
    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="v-label" for="v-l-q">Search</label>
                <input id="v-l-q" type="search" wire:model.live.debounce.400ms="q" placeholder="message…" class="v-input w-56">
            </div>

            <div>
                <label class="v-label" for="v-l-level">Min level</label>
                <select id="v-l-level" wire:model.live="level" class="v-select">
                    <option value="">All</option>
                    @foreach ($levels as $name)
                        <option value="{{ $name }}">{{ ucfirst($name) }}+</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="v-label" for="v-l-channel">Channel</label>
                <select id="v-l-channel" wire:model.live="channel" class="v-select">
                    <option value="">All</option>
                    @foreach ($channels as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </div>

            @if ($trace !== '')
                <span class="v-pill is-info v-num self-center">scoped to trace {{ Str::limit($trace, 8, '…') }}</span>
            @endif

            <button type="button" wire:click="clear" class="v-btn v-btn--sm ml-auto">Clear</button>
        </div>
    </div>

    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <thead>
                    <tr>
                        <th scope="col" class="w-28">Time</th>
                        <th scope="col" class="w-24">Level</th>
                        <th scope="col" class="w-28">Channel</th>
                        <th scope="col">Message</th>
                        <th scope="col" class="text-right">Trace</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="whitespace-nowrap v-muted" title="{{ CarbonImmutable::createFromTimestamp($log->loggedAt)->toDateTimeString() }}">
                                {{ CarbonImmutable::createFromTimestamp($log->loggedAt)->diffForHumans(short: true) }}
                            </td>
                            <td>
                                <span @class(['v-pill', $levelPill($log->levelValue)])>{{ $log->level }}</span>
                            </td>
                            <td class="font-mono text-[11px] v-faint">{{ $log->channel ?: '—' }}</td>
                            <td class="min-w-0">
                                <span @class(['font-mono text-[12px]', 'v-strong' => $log->isProblem(), 'v-muted' => ! $log->isProblem()])>{{ Str::limit($log->message, 160) }}</span>
                                @if ($log->context !== [])
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-[11px] v-link">context</summary>
                                        <pre class="mt-1 max-w-full overflow-x-auto rounded p-2 text-[11px] v-num" tabindex="0" style="background: var(--v-surface-2);">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($log->traceId)
                                    <a href="{{ route('vigilance.traces.show', $log->traceId) }}" class="v-link text-[11px]">view trace →</a>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">
                            <div class="v-empty">
                                <p class="v-empty__title">No logs match.</p>
                                <p>Captured <code class="font-mono">Log::*</code> records appear here as your app writes them.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
