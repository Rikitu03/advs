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
                    Notification::TYPE_HIGH_RISK_ALERT => 'exclamation-triangle',
                    Notification::TYPE_DOCUMENT_FLAGGED => 'flag',
                    Notification::TYPE_DECISION_MADE => 'check-circle',
                    Notification::TYPE_SUBMISSION_RECEIVED => 'inbox-arrow-down',
                    Notification::TYPE_PROCESSING_COMPLETE => 'check-badge',
                    default => 'bell',
                },
                'color' => match ($notification->type) {
                    Notification::TYPE_HIGH_RISK_ALERT => 'rose',
                    Notification::TYPE_DOCUMENT_FLAGGED => 'amber',
                    Notification::TYPE_DECISION_MADE, Notification::TYPE_PROCESSING_COMPLETE => 'emerald',
                    Notification::TYPE_SUBMISSION_RECEIVED => 'sky',
                    default => 'zinc',
                },
                'title' => $notification->subject,
                'body' => $notification->body,
                'at' => $notification->created_at,
                'submission_id' => $notification->related_submission_id,
            ]);

        return [
            'rows' => $rows,
            'unreadCount' => Auth::user()->notifications()->unread()->count(),
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
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Notifications</h1>
                    <p class="text-sm text-cu-muted">Review alerts, high-risk flags, and decision activity.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-black/5 px-3 py-1.5 text-sm text-cu-muted dark:bg-white/5">
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-cu-purple" />
                    <span wire:loading.remove class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $unreadCount }} unread
                </span>
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="cu-animate-in flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4" style="animation-delay: 60ms">
            <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 p-1 dark:bg-white/5">
                @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $text)
                    <button type="button" wire:click="setFilter('{{ $value }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                @endforeach
            </div>
            <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead" @disabled($unreadCount === 0)
                    class="inline-flex items-center gap-1.5 rounded-lg border border-cu-border px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-purple hover:bg-cu-purple/10 disabled:cursor-not-allowed disabled:opacity-40">
                <flux:icon.loading wire:loading wire:target="markAllRead" variant="micro" class="size-4" />
                <flux:icon icon="check" wire:loading.remove wire:target="markAllRead" class="size-4" />
                Mark all as read
            </button>
        </div>

        {{-- Feed --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 120ms">
            <div class="divide-y divide-cu-border" wire:loading.class="opacity-40" wire:target="setFilter, markAllRead">
                @foreach ($rows as $n)
                    <div wire:key="notification-{{ $n['id'] }}"
                         class="cu-animate-in flex gap-4 px-5 py-4 transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03] {{ $n['read'] ? '' : 'bg-cu-purple/5' }}"
                         style="animation-delay: {{ min($loop->index * 40, 320) }}ms">
                        <x-activity-icon :icon="$n['icon']" :color="$n['color']" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium {{ $n['read'] ? 'text-cu-muted' : 'text-cu-text' }}">
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
                                       class="inline-flex items-center gap-1 text-xs font-medium text-cu-blue transition hover:text-cu-text">
                                        View submission
                                        <flux:icon icon="arrow-up-right" class="size-3" />
                                    </a>
                                @endif
                                @unless ($n['read'])
                                    <button type="button" wire:click="markRead({{ $n['id'] }})"
                                            class="text-xs font-medium text-cu-muted transition hover:text-cu-text">
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
                    <p class="text-sm font-medium text-cu-text">You're all caught up</p>
                    <p class="text-xs text-cu-muted">No unread notifications right now.</p>
                </div>
            @endif
        </div>
    </div>
</x-page>
