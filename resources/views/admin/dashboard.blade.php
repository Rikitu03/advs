<x-layouts.app>
    @php
        $kpis = \App\Support\DemoData::kpis();
        $urgent = \App\Support\DemoData::urgentSubmissions();
        $activity = \App\Support\DemoData::activity();
        $user = auth()->user();
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

            {{-- Hero --}}
            <div class="relative overflow-hidden rounded-2xl cu-gradient p-6 sm:p-8">
                <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex items-start gap-4">
                        <span
                            class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-lg font-semibold text-white uppercase ring-1 ring-white/30 backdrop-blur"
                            aria-hidden="true"
                        >
                            {{ $user->initials() }}
                        </span>
                        <div class="flex flex-col gap-2">
                            <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-xs font-medium text-white/90 backdrop-blur">
                                <flux:icon icon="shield-check" class="size-3.5" />
                                Compliance workspace
                            </span>
                            <h1 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                                Welcome back, {{ str($user->name)->before(' ') ?: $user->name }}
                            </h1>
                            <p class="max-w-xl text-sm text-white/80">
                                Review AI-generated validation reports, drill into risk scores, and issue the final accreditation decision.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <flux:badge color="zinc" class="bg-white/15 text-white">{{ str($user->role)->headline() }}</flux:badge>
                        <a href="{{ route('admin.pending') }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-cu-purple shadow-sm transition hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                            <flux:icon icon="inbox-stack" class="size-4" />
                            Review queue
                        </a>
                    </div>
                </div>
                <div class="pointer-events-none absolute -right-10 -top-10 size-48 rounded-full bg-white/10 blur-2xl"></div>
            </div>

            {{-- KPI cards --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-kpi-card label="Pending review" :value="$kpis['pending']" icon="inbox-stack" accent="purple" hint="Awaiting your decision" />
                <x-kpi-card label="Flagged today" :value="$kpis['flagged_today']" icon="flag" accent="pink" hint="New flags raised today" />
                <x-kpi-card label="High-risk (≥ 61)" :value="$kpis['high_risk']" icon="exclamation-triangle" accent="yellow" hint="Need priority review" />
                <x-kpi-card label="Approval rate" :value="$kpis['approval_rate']" icon="check-badge" accent="blue" hint="Last 30 days" />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                {{-- Most urgent flagged submissions --}}
                <div class="lg:col-span-2 rounded-2xl border border-white/5 bg-cu-surface">
                    <div class="flex items-center justify-between gap-3 border-b border-white/5 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-white">Most urgent submissions</h2>
                            <p class="text-xs text-cu-muted">Highest composite risk, awaiting review</p>
                        </div>
                        <a href="{{ route('admin.pending') }}" wire:navigate
                           class="inline-flex items-center gap-1 text-sm font-medium text-cu-blue hover:text-cu-purple">
                            View all
                            <flux:icon icon="arrow-up-right" class="size-4" />
                        </a>
                    </div>

                    <ul class="divide-y divide-white/5">
                        @foreach ($urgent as $s)
                            <li>
                                <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                   class="group flex items-center gap-4 px-5 py-4 transition hover:bg-white/[0.03]">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/5 text-cu-muted">
                                        <flux:icon icon="document-text" class="size-5" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-white">{{ $s['company'] }}</p>
                                        <p class="truncate text-xs text-cu-muted">
                                            {{ $s['ref'] }} · {{ $s['document_type'] }} · {{ $s['submitted_at']->diffForHumans() }}
                                        </p>
                                    </div>
                                    @if (count($s['flags']) > 0)
                                        <span class="hidden items-center gap-1 text-xs text-cu-muted sm:inline-flex">
                                            <flux:icon icon="flag" class="size-3.5 text-rose-400" />
                                            {{ count($s['flags']) }} {{ \Illuminate\Support\Str::plural('flag', count($s['flags'])) }}
                                        </span>
                                    @endif
                                    <x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" />
                                    <flux:icon icon="chevron-right" class="size-4 text-cu-muted transition group-hover:translate-x-0.5 group-hover:text-white" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Activity feed --}}
                <div class="rounded-2xl border border-white/5 bg-cu-surface">
                    <div class="border-b border-white/5 px-5 py-4">
                        <h2 class="text-base font-semibold text-white">Recent activity</h2>
                        <p class="text-xs text-cu-muted">Alerts from the validation pipeline</p>
                    </div>
                    <ul class="flex flex-col px-5 py-2">
                        @foreach ($activity as $event)
                            <li class="flex gap-3 py-3">
                                <x-activity-icon :icon="$event['icon']" :color="$event['color']" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm leading-snug text-zinc-200">{{ $event['text'] }}</p>
                                    <p class="mt-0.5 text-xs text-cu-muted">{{ $event['at']->diffForHumans() }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
