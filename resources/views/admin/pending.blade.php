<x-layouts.app>
    @php
        $pending = \App\Support\DemoData::pendingSubmissions();
        $metas = $pending->map(fn ($s) => [
            'id' => $s['id'],
            'level' => $s['risk_level'],
            'text' => trim("{$s['ref']} {$s['company']} {$s['vendor']} {$s['document_type']}"),
        ])->values();
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-6"
            x-data="{
                q: '',
                risk: 'all',
                rows: @js($metas),
                match(r) {
                    const okRisk = this.risk === 'all' || r.level === this.risk;
                    const okText = this.q.trim() === '' || r.text.toLowerCase().includes(this.q.toLowerCase());
                    return okRisk && okText;
                },
                get visibleCount() { return this.rows.filter(r => this.match(r)).length; },
            }"
        >
            {{-- Header --}}
            <div class="flex flex-col gap-2">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Pending Submissions</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Pending Submissions</h1>
                        <p class="text-sm text-cu-muted">Sorted by composite risk score, highest first.</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                        <span class="size-2 rounded-full bg-cu-purple"></span>
                        <span x-text="visibleCount"></span> of {{ $pending->count() }} awaiting review
                    </span>
                </div>
            </div>

            {{-- Filter bar --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4 sm:flex-row sm:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        x-model="q"
                        placeholder="Search vendor, company, or reference…"
                        class="w-full rounded-xl border border-white/10 bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    />
                </label>
                <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                    @foreach (['all' => 'All', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $text)
                        <button
                            type="button"
                            x-on:click="risk = '{{ $value }}'"
                            :class="risk === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        >{{ $text }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Queue table --}}
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-cu-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/5 text-xs uppercase tracking-wide text-cu-muted">
                                <th class="px-5 py-3 font-medium">Submission</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Submitted</th>
                                <th class="px-5 py-3 font-medium">Flags</th>
                                <th class="px-5 py-3 font-medium">Risk</th>
                                <th class="px-5 py-3 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($pending as $s)
                                <tr
                                    x-show="match(@js(['id' => $s['id'], 'level' => $s['risk_level'], 'text' => trim("{$s['ref']} {$s['company']} {$s['vendor']} {$s['document_type']}")]))"
                                    class="transition hover:bg-white/[0.03]"
                                >
                                    <td class="px-5 py-4">
                                        <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate class="group block">
                                            <span class="font-medium text-white group-hover:text-cu-blue">{{ $s['company'] }}</span>
                                            <span class="block text-xs text-cu-muted">{{ $s['ref'] }} · {{ $s['vendor'] }}</span>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4 text-cu-muted">{{ $s['document_type'] }}</td>
                                    <td class="px-5 py-4 text-cu-muted">
                                        <span title="{{ $s['submitted_at']->toDayDateTimeString() }}">{{ $s['submitted_at']->diffForHumans() }}</span>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if (count($s['flags']) > 0)
                                            <span class="inline-flex items-center gap-1.5 text-cu-muted">
                                                <flux:icon icon="flag" class="size-4 text-rose-400" />
                                                {{ count($s['flags']) }}
                                            </span>
                                        @else
                                            <span class="text-cu-muted/60">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" />
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10">
                                            Review
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
                    <flux:icon icon="inbox-stack" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-white">No submissions match your filters</p>
                    <p class="text-xs text-cu-muted">Try a different search term or risk level.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
