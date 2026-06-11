<x-layouts.app>
    @php
        $notifications = \App\Support\DemoData::notifications();
        $readMap = $notifications->mapWithKeys(fn ($n) => [$n['id'] => $n['read']]);
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div
            class="mx-auto flex w-full max-w-4xl flex-col gap-6"
            x-data="{
                filter: 'all',
                read: @js($readMap),
                markAll() { Object.keys(this.read).forEach(k => this.read[k] = true); },
                get unreadCount() { return Object.values(this.read).filter(r => !r).length; },
                get visibleCount() { return this.filter === 'all' ? Object.keys(this.read).length : this.unreadCount; },
            }"
        >
            {{-- Header --}}
            <div class="flex flex-col gap-2">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Notifications</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Notifications</h1>
                        <p class="text-sm text-cu-muted">Review alerts, high-risk flags, and decision activity.</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                        <span class="size-2 rounded-full bg-cu-purple"></span>
                        <span x-text="unreadCount"></span> unread
                    </span>
                </div>
            </div>

            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4">
                <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                    @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $text)
                        <button type="button" x-on:click="filter = '{{ $value }}'"
                                :class="filter === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                    @endforeach
                </div>
                <button type="button" x-on:click="markAll()" :disabled="unreadCount === 0"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10 disabled:cursor-not-allowed disabled:opacity-40">
                    <flux:icon icon="check" class="size-4" />
                    Mark all as read
                </button>
            </div>

            {{-- Feed --}}
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-cu-surface">
                <div class="divide-y divide-white/5">
                    @foreach ($notifications as $n)
                        <div
                            x-show="filter === 'all' || !read[{{ $n['id'] }}]"
                            class="flex gap-4 px-5 py-4 transition hover:bg-white/[0.03]"
                            :class="read[{{ $n['id'] }}] ? '' : 'bg-cu-purple/5'"
                        >
                            <x-activity-icon :icon="$n['icon']" :color="$n['color']" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-medium" :class="read[{{ $n['id'] }}] ? 'text-cu-muted' : 'text-white'">
                                        {{ $n['title'] }}
                                    </p>
                                    <span class="flex shrink-0 items-center gap-2">
                                        <span class="text-xs text-cu-muted" title="{{ $n['at']->toDayDateTimeString() }}">{{ $n['at']->diffForHumans() }}</span>
                                        <span x-show="!read[{{ $n['id'] }}]" class="size-2 rounded-full bg-cu-purple" aria-label="Unread"></span>
                                    </span>
                                </div>
                                <p class="mt-0.5 text-sm text-cu-muted">{{ $n['body'] }}</p>
                                <div class="mt-2 flex items-center gap-4">
                                    @if ($n['submission_id'] !== null)
                                        <a href="{{ route('admin.submissions.show', $n['submission_id']) }}" wire:navigate
                                           x-on:click="read[{{ $n['id'] }}] = true"
                                           class="inline-flex items-center gap-1 text-xs font-medium text-cu-blue transition hover:text-white">
                                            View submission
                                            <flux:icon icon="arrow-up-right" class="size-3" />
                                        </a>
                                    @endif
                                    <button type="button" x-show="!read[{{ $n['id'] }}]" x-on:click="read[{{ $n['id'] }}] = true"
                                            class="text-xs font-medium text-cu-muted transition hover:text-white">
                                        Mark as read
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Empty state (all caught up) --}}
                <div x-show="visibleCount === 0" x-cloak class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <flux:icon icon="bell-slash" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-white">You're all caught up</p>
                    <p class="text-xs text-cu-muted">No unread notifications right now.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
