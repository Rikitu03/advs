<?php

use App\Support\DemoStore;
use Livewire\Volt\Component;

new class extends Component {
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function markRead(int $id): void
    {
        DemoStore::markNotificationRead($id);
    }

    public function markAllRead(): void
    {
        DemoStore::simulateProcessing(400);
        DemoStore::markAllNotificationsRead();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $notifications = DemoStore::notifications();

        return [
            'rows' => $this->filter === 'unread'
                ? $notifications->where('read', false)->values()
                : $notifications,
            'unreadCount' => $notifications->where('read', false)->count(),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
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
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-cu-purple" />
                    <span wire:loading.remove class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $unreadCount }} unread
                </span>
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="cu-animate-in flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4" style="animation-delay: 60ms">
            <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $text)
                    <button type="button" wire:click="setFilter('{{ $value }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white' }}">{{ $text }}</button>
                @endforeach
            </div>
            <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead" @disabled($unreadCount === 0)
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10 disabled:cursor-not-allowed disabled:opacity-40">
                <flux:icon.loading wire:loading wire:target="markAllRead" variant="micro" class="size-4" />
                <flux:icon icon="check" wire:loading.remove wire:target="markAllRead" class="size-4" />
                Mark all as read
            </button>
        </div>

        {{-- Feed --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-white/5 bg-cu-surface" style="animation-delay: 120ms">
            <div class="divide-y divide-white/5" wire:loading.class="opacity-40" wire:target="setFilter, markAllRead">
                @foreach ($rows as $n)
                    <div wire:key="notification-{{ $n['id'] }}"
                         class="cu-animate-in flex gap-4 px-5 py-4 transition hover:bg-white/[0.03] {{ $n['read'] ? '' : 'bg-cu-purple/5' }}"
                         style="animation-delay: {{ min($loop->index * 40, 320) }}ms">
                        <x-activity-icon :icon="$n['icon']" :color="$n['color']" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium {{ $n['read'] ? 'text-cu-muted' : 'text-white' }}">
                                    {{ $n['title'] }}
                                </p>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="text-xs text-cu-muted" title="{{ $n['at']->toDayDateTimeString() }}">{{ $n['at']->diffForHumans() }}</span>
                                    @unless ($n['read'])
                                        <span class="size-2 rounded-full bg-cu-purple" aria-label="Unread"></span>
                                    @endunless
                                </span>
                            </div>
                            <p class="mt-0.5 text-sm text-cu-muted">{{ $n['body'] }}</p>
                            <div class="mt-2 flex items-center gap-4">
                                @if ($n['submission_id'] !== null)
                                    <a href="{{ route('admin.submissions.show', $n['submission_id']) }}" wire:navigate
                                       wire:click="markRead({{ $n['id'] }})"
                                       class="inline-flex items-center gap-1 text-xs font-medium text-cu-blue transition hover:text-white">
                                        View submission
                                        <flux:icon icon="arrow-up-right" class="size-3" />
                                    </a>
                                @endif
                                @unless ($n['read'])
                                    <button type="button" wire:click="markRead({{ $n['id'] }})"
                                            class="text-xs font-medium text-cu-muted transition hover:text-white">
                                        Mark as read
                                    </button>
                                @endunless
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($rows->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <flux:icon icon="bell-slash" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-white">You're all caught up</p>
                    <p class="text-xs text-cu-muted">No unread notifications right now.</p>
                </div>
            @endif
        </div>
    </div>
</x-page>
