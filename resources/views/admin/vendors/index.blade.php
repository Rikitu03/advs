<x-layouts.app>
    @php
        $vendors = \App\Support\DemoData::vendors();
        $metas = $vendors->map(fn ($v) => [
            'id' => $v['id'],
            'status' => $v['status'],
            'text' => trim("{$v['company']} {$v['contact']} {$v['registration_number']}"),
        ])->values();
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-6"
            x-data="{
                q: '',
                status: 'all',
                rows: @js($metas),
                match(r) {
                    const okStatus = this.status === 'all' || r.status === this.status;
                    const okText = this.q.trim() === '' || r.text.toLowerCase().includes(this.q.toLowerCase());
                    return okStatus && okText;
                },
                get visibleCount() { return this.rows.filter(r => this.match(r)).length; },
            }"
        >
            {{-- Header --}}
            <div class="flex flex-col gap-2">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Vendor Profiles</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Vendor Profiles</h1>
                        <p class="text-sm text-cu-muted">Directory of registered vendors and their accreditation status.</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                        <span class="size-2 rounded-full bg-cu-purple"></span>
                        <span x-text="visibleCount"></span> of {{ $vendors->count() }} vendors
                    </span>
                </div>
            </div>

            {{-- Filter bar --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4 lg:flex-row lg:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        x-model="q"
                        placeholder="Search company, contact, or registration no.…"
                        class="w-full rounded-xl border border-white/10 bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                    @foreach (['all' => 'All', 'approved' => 'Approved', 'under_review' => 'Under review', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $value => $text)
                        <button type="button" x-on:click="status = '{{ $value }}'"
                                :class="status === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Vendor grid --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($vendors as $v)
                    <a
                        href="{{ route('admin.vendors.show', $v['id']) }}" wire:navigate
                        x-show="match(@js(['id' => $v['id'], 'status' => $v['status'], 'text' => trim("{$v['company']} {$v['contact']} {$v['registration_number']}")]))"
                        class="group flex flex-col gap-4 rounded-2xl border border-white/5 bg-cu-surface p-5 transition hover:border-cu-purple/40 hover:bg-white/[0.03]"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-cu-purple/15 text-sm font-semibold text-cu-purple">
                                    {{ \Illuminate\Support\Str::of($v['company'])->explode(' ')->take(2)->map(fn ($w) => \Illuminate\Support\Str::substr($w, 0, 1))->implode('') }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-white group-hover:text-cu-blue">{{ $v['company'] }}</p>
                                    <p class="truncate text-xs text-cu-muted">{{ $v['contact'] }}</p>
                                </div>
                            </div>
                            <flux:badge size="sm" :color="\App\Support\DemoData::vendorStatusColor($v['status'])">{{ str($v['status'])->headline() }}</flux:badge>
                        </div>

                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs text-cu-muted">Registered</dt>
                                <dd class="text-zinc-200">{{ $v['registered_at']->format('M Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-cu-muted">Submissions</dt>
                                <dd class="text-zinc-200">{{ $v['submissions_count'] }}</dd>
                            </div>
                        </dl>

                        <div class="flex items-center justify-between border-t border-white/5 pt-3">
                            @if ($v['enrolled'])
                                <span class="inline-flex items-center gap-1 text-xs text-emerald-300">
                                    <flux:icon icon="check-badge" variant="micro" class="size-3.5" /> References enrolled
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-cu-muted">
                                    <flux:icon icon="minus-circle" variant="micro" class="size-3.5" /> Not enrolled
                                </span>
                            @endif
                            <flux:icon icon="chevron-right" class="size-4 text-cu-muted transition group-hover:translate-x-0.5 group-hover:text-white" />
                        </div>
                    </a>
                @endforeach
            </div>

            <div x-show="visibleCount === 0" x-cloak class="flex flex-col items-center gap-2 rounded-2xl border border-white/5 bg-cu-surface px-5 py-16 text-center">
                <flux:icon icon="identification" class="size-8 text-cu-muted" />
                <p class="text-sm font-medium text-white">No vendors match your filters</p>
                <p class="text-xs text-cu-muted">Try a different search term or status.</p>
            </div>
        </div>
    </div>
</x-layouts.app>
