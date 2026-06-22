<x-layouts.app.sidebar>
    <flux:main>
        <x-page>
            <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">

                <div class="cu-animate-in flex flex-col gap-2">
                    <flux:breadcrumbs>
                        <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                        <flux:breadcrumbs.item href="{{ route('admin.audit.index') }}" wire:navigate>Audit Trail</flux:breadcrumbs.item>
                        <flux:breadcrumbs.item>Entry #{{ $log->id }}</flux:breadcrumbs.item>
                    </flux:breadcrumbs>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 class="text-2xl font-semibold tracking-tight text-white">Audit Entry #{{ $log->id }}</h1>
                            <p class="text-sm text-cu-muted">
                                {{ $log->created_at?->format('M d, Y · H:i:s') }}
                                @if ($log->ip_address)
                                    · <span class="font-mono">{{ $log->ip_address }}</span>
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('admin.audit.index') }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-cu-surface px-4 py-2.5 text-sm font-medium text-cu-muted hover:text-white">
                            <flux:icon icon="arrow-left" class="size-4" />
                            Back to audit trail
                        </a>
                    </div>
                </div>

                <div class="cu-animate-in grid grid-cols-1 gap-3 sm:grid-cols-2" style="animation-delay: 60ms">
                    <div class="rounded-2xl border border-white/5 bg-cu-surface p-4">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Actor</p>
                        @if ($log->user)
                            <p class="mt-1 text-sm font-medium text-white">{{ $log->user->name }}</p>
                            <p class="text-xs text-cu-muted">{{ $log->user->email }}</p>
                            <p class="mt-1 text-xs text-cu-muted">Role: {{ ucfirst(str_replace('_', ' ', $log->user->role)) }}</p>
                        @else
                            <p class="mt-1 text-sm font-medium text-white">(deleted user)</p>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-white/5 bg-cu-surface p-4">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Action</p>
                        <p class="mt-1">
                            <span class="inline-flex items-center rounded-full bg-cu-purple/20 px-2 py-0.5 font-mono text-xs font-semibold text-cu-purple">{{ $log->action }}</span>
                        </p>
                        <p class="mt-2 text-xs uppercase tracking-wider text-cu-muted">Affected entity</p>
                        @if ($log->entity_type)
                            <p class="mt-1 text-sm text-white">
                                {{ class_basename($log->entity_type) }}<span class="text-cu-muted"> #{{ $log->entity_id }}</span>
                            </p>
                        @else
                            <p class="mt-1 text-sm text-cu-muted">—</p>
                        @endif
                    </div>
                </div>

                <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-4" style="animation-delay: 120ms">
                    <div class="flex items-center justify-between">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Details payload</p>
                        @if (is_array($log->details))
                            <span class="text-[10px] uppercase tracking-wider text-cu-muted">{{ count($log->details) }} field(s)</span>
                        @endif
                    </div>
                    <pre class="mt-3 overflow-x-auto rounded-xl border border-white/5 bg-cu-bg p-4 text-xs text-white">@json($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)</pre>
                </div>

                @php
                    $before = $log->details['before'] ?? null;
                    $after = $log->details['after'] ?? null;
                @endphp

                @if (is_array($before) || is_array($after))
                    <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-4" style="animation-delay: 180ms">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Before → After</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-cu-muted">Before</p>
                                <pre class="mt-1 overflow-x-auto rounded-xl border border-white/5 bg-cu-bg p-3 text-xs text-rose-200">@json($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)</pre>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-cu-muted">After</p>
                                <pre class="mt-1 overflow-x-auto rounded-xl border border-white/5 bg-cu-bg p-3 text-xs text-emerald-200">@json($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)</pre>
                            </div>
                        </div>
                    </div>
                @endif

            </div>
        </x-page>
    </flux:main>
</x-layouts.app.sidebar>