<?php

use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function markRead(int $id): void
    {
        Auth::user()->notifications()->whereKey($id)->update(['is_read' => true]);
    }

    public function markAllRead(): void
    {
        Auth::user()->notifications()->unread()->update(['is_read' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $rows = Auth::user()->notifications()
            ->when($this->filter === 'unread', fn ($query) => $query->unread())
            ->get()
            ->map(fn (Notification $notification): array => [
                'id' => $notification->id,
                'read' => $notification->is_read,
                'icon' => match ($notification->type) {
                    Notification::TYPE_SUBMISSION_RECEIVED => 'inbox-arrow-down',
                    Notification::TYPE_PROCESSING_COMPLETE => 'check-badge',
                    Notification::TYPE_DECISION_MADE => 'check-circle',
                    default => 'bell',
                },
                'color' => match ($notification->type) {
                    Notification::TYPE_SUBMISSION_RECEIVED => 'sky',
                    Notification::TYPE_PROCESSING_COMPLETE => 'emerald',
                    Notification::TYPE_DECISION_MADE => 'emerald',
                    default => 'zinc',
                },
                'title' => $notification->subject,
                'body' => $notification->body,
                'at' => $notification->created_at,
            ]);

        return [
            'rows' => $rows,
            'unreadCount' => Auth::user()->notifications()->unread()->count(),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <div class="cu-animate-in">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Notifications</h1>
                    <p class="mt-1 text-sm text-cu-muted">Upload updates, processing notices, and officer decisions.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full border border-cu-border bg-cu-surface px-3 py-1.5 text-sm text-cu-muted shadow-sm">
                    <span class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $unreadCount }} unread
                </span>
            </div>
        </div>

        <div class="cu-animate-in flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4 shadow-sm" style="animation-delay: 60ms">
            <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $text)
                    <button type="button" wire:click="setFilter('{{ $value }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white shadow-sm' : 'text-cu-muted hover:bg-black/5 dark:hover:bg-white/5 hover:text-cu-text' }}">
                        {{ $text }}
                    </button>
                @endforeach
            </div>
            <button type="button" wire:click="markAllRead" @disabled($unreadCount === 0)
                    wire:target="markAllRead" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-cu-border bg-cu-surface px-3 py-1.5 text-sm font-medium text-cu-muted transition hover:border-cu-purple hover:bg-cu-purple/10 hover:text-cu-purple disabled:cursor-not-allowed disabled:opacity-40">
                <flux:icon icon="check" class="size-4" wire:loading.remove wire:target="markAllRead" />
                <flux:icon icon="arrow-path" class="size-4 animate-spin" wire:loading wire:target="markAllRead" />
                Mark all as read
            </button>
        </div>

        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface shadow-sm" style="animation-delay: 120ms">
            <div class="divide-y divide-cu-border transition-opacity duration-200" wire:loading.class="opacity-40" wire:target="setFilter, markAllRead, markRead">
                @forelse ($rows as $notification)
                    <div wire:key="vendor-notification-{{ $notification['id'] }}" class="flex gap-4 px-5 py-4 transition hover:bg-black/5 dark:hover:bg-white/5 {{ $notification['read'] ? '' : 'bg-cu-purple/5' }}">
                        <x-activity-icon :icon="$notification['icon']" :color="$notification['color']" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium {{ $notification['read'] ? 'text-cu-muted' : 'text-cu-text' }}">{{ $notification['title'] }}</p>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="text-xs text-cu-muted">{{ $notification['at']->diffForHumans() }}</span>
                                    @unless ($notification['read'])
                                        <span class="size-2 rounded-full bg-cu-purple"></span>
                                    @endunless
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-cu-muted">{{ $notification['body'] }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <a href="{{ route('vendor.submissions') }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-xs font-medium text-cu-purple transition hover:text-cu-pink">
                                    View submissions
                                    <flux:icon icon="arrow-up-right" class="size-3" />
                                </a>
                                @unless ($notification['read'])
                                    <button type="button" wire:click="markRead({{ $notification['id'] }})" class="text-xs font-medium text-cu-muted transition hover:text-cu-text">
                                        Mark as read
                                    </button>
                                @endunless
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                        <flux:icon icon="bell-slash" class="size-8 text-cu-muted" />
                        <p class="text-sm font-medium text-cu-text">No unread notifications</p>
                        <p class="text-xs text-cu-muted">You are caught up for now.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-page>
