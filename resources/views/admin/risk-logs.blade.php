<x-layouts.app>
    @php
        $logs = \App\Support\DemoData::riskLogs();
        $types = \App\Support\DemoData::flagTypes();
        $metas = $logs->map(fn ($log) => [
            'id' => $log['id'],
            'type' => $log['type'],
            'severity' => $log['severity'],
            'age' => (int) abs($log['raised_at']->diffInHours(now())),
            'text' => trim("{$log['ref']} {$log['company']} {$log['vendor']} {$log['title']} {$log['document_type']}"),
        ])->values();
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-6"
            x-data="{
                q: '',
                type: 'all',
                severity: 'all',
                range: 'all',
                rows: @js($metas),
                match(r) {
                    const okType = this.type === 'all' || r.type === this.type;
                    const okSeverity = this.severity === 'all' || r.severity === this.severity;
                    const okRange = this.range === 'all' || r.age <= Number(this.range);
                    const okText = this.q.trim() === '' || r.text.toLowerCase().includes(this.q.toLowerCase());
                    return okType && okSeverity && okRange && okText;
                },
                get visibleCount() { return this.rows.filter(r => this.match(r)).length; },
            }"
        >
            {{-- Header --}}
            <div class="flex flex-col gap-2">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Risk Logs</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Risk Logs</h1>
                        <p class="text-sm text-cu-muted">Chronological audit log of every flag the validation pipeline has raised.</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                        <span class="size-2 rounded-full bg-rose-400"></span>
                        <span x-text="visibleCount"></span> of {{ $logs->count() }} flags
                    </span>
                </div>
            </div>

            {{-- Filter bar --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <label class="relative flex-1">
                        <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                        <input
                            type="search"
                            x-model="q"
                            placeholder="Search vendor, company, reference, or flag…"
                            class="w-full rounded-xl border border-white/10 bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                        />
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                            @foreach (['all' => 'All severity', 'high' => 'High', 'medium' => 'Medium'] as $value => $text)
                                <button type="button" x-on:click="severity = '{{ $value }}'"
                                        :class="severity === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                            @foreach (['all' => 'All time', '24' => '24h', '168' => '7 days', '720' => '30 days'] as $value => $text)
                                <button type="button" x-on:click="range = '{{ $value }}'"
                                        :class="range === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                {{-- Flag-type filter (§4: text mismatch, low classification confidence, signature mismatch, stamp mismatch) --}}
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1 self-start">
                    <button type="button" x-on:click="type = 'all'"
                            :class="type === 'all' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition">All flags</button>
                    @foreach ($types as $key => $meta)
                        <button type="button" x-on:click="type = '{{ $key }}'"
                                :class="type === '{{ $key }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $meta['label'] }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Log table --}}
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-cu-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/5 text-xs uppercase tracking-wide text-cu-muted">
                                <th class="px-5 py-3 font-medium">Flag</th>
                                <th class="px-5 py-3 font-medium">Severity</th>
                                <th class="px-5 py-3 font-medium">Vendor</th>
                                <th class="px-5 py-3 font-medium">Raised</th>
                                <th class="px-5 py-3 text-right font-medium">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($logs as $log)
                                @php($typeMeta = $types[$log['type']])
                                <tr
                                    x-show="match(@js(['id' => $log['id'], 'type' => $log['type'], 'severity' => $log['severity'], 'age' => (int) abs($log['raised_at']->diffInHours(now())), 'text' => trim("{$log['ref']} {$log['company']} {$log['vendor']} {$log['title']} {$log['document_type']}")]))"
                                    class="transition hover:bg-white/[0.03]"
                                >
                                    <td class="px-5 py-4">
                                        <div class="flex items-start gap-3">
                                            <x-activity-icon :icon="$typeMeta['icon']" :color="$typeMeta['color']" />
                                            <div>
                                                <span class="block font-medium text-white">{{ $log['title'] }}</span>
                                                <span class="block max-w-md text-xs text-cu-muted">{{ $log['detail'] }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <x-risk-badge :level="$log['severity']" />
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="block font-medium text-white">{{ $log['company'] }}</span>
                                        <span class="block text-xs text-cu-muted">{{ $log['ref'] }} · {{ $log['document_type'] }}</span>
                                    </td>
                                    <td class="px-5 py-4 text-cu-muted">
                                        <span title="{{ $log['raised_at']->toDayDateTimeString() }}">{{ $log['raised_at']->diffForHumans() }}</span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('admin.submissions.show', $log['submission_id']) }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10">
                                            View
                                            <flux:icon icon="arrow-up-right" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Empty state (filtered) --}}
                <div x-show="visibleCount === 0" x-cloak class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <flux:icon icon="clipboard-document-list" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-white">No flags match your filters</p>
                    <p class="text-xs text-cu-muted">Try a different search term, flag type, severity, or date range.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
