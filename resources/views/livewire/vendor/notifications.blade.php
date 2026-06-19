<?php

use App\Support\VendorDemoData;
use Livewire\Volt\Component;

new class extends Component {
    public string $filter = 'all';

    /** @var list<int> */
    public array $read = [];

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function markRead(int $id): void
    {
        $this->read[] = $id;
        $this->read = array_values(array_unique($this->read));
    }

    public function markAllRead(): void
    {
        $this->read = VendorDemoData::notifications()->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $notifications = VendorDemoData::notifications()->map(function (array $notification): array {
            if (in_array($notification['id'], $this->read, true)) {
                $notification['read'] = true;
            }

            return $notification;
        });

        return [
            'rows' => $this->filter === 'unread'
                ? $notifications->where('read', false)->values()
                : $notifications,
            'unreadCount' => $notifications->where('read', false)->count(),
        ];
    }
}; ?>

<div class="-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <div class="cu-animate-in">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-950">Notifications</h1>
                    <p class="mt-1 text-sm text-zinc-500">Upload updates, processing notices, and officer decisions.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 shadow-sm">
                    <span class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $unreadCount }} unread
                </span>
            </div>
        </div>

        <div class="cu-animate-in flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm" style="animation-delay: 60ms">
            <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1">
                @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $text)
                    <button type="button" wire:click="setFilter('{{ $value }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white shadow-sm' : 'text-zinc-600 hover:bg-white hover:text-zinc-950' }}">
                        {{ $text }}
                    </button>
                @endforeach
            </div>
            <button type="button" wire:click="markAllRead" @disabled($unreadCount === 0)
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 transition hover:border-cu-purple hover:bg-cu-purple/10 hover:text-cu-purple disabled:cursor-not-allowed disabled:opacity-40">
                <flux:icon icon="check" class="size-4" />
                Mark all as read
            </button>
        </div>

        <div class="cu-animate-in overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" style="animation-delay: 120ms">
            <div class="divide-y divide-zinc-100">
                @forelse ($rows as $notification)
                    <div wire:key="vendor-notification-{{ $notification['id'] }}" class="flex gap-4 px-5 py-4 transition hover:bg-zinc-50 {{ $notification['read'] ? '' : 'bg-cu-purple/5' }}">
                        <x-activity-icon :icon="$notification['icon']" :color="$notification['color']" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium {{ $notification['read'] ? 'text-zinc-600' : 'text-zinc-950' }}">{{ $notification['title'] }}</p>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="text-xs text-zinc-500">{{ $notification['at']->diffForHumans() }}</span>
                                    @unless ($notification['read'])
                                        <span class="size-2 rounded-full bg-cu-purple"></span>
                                    @endunless
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-zinc-500">{{ $notification['body'] }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <a href="{{ route('vendor.submissions') }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-xs font-medium text-cu-purple transition hover:text-cu-pink">
                                    View submissions
                                    <flux:icon icon="arrow-up-right" class="size-3" />
                                </a>
                                @unless ($notification['read'])
                                    <button type="button" wire:click="markRead({{ $notification['id'] }})" class="text-xs font-medium text-zinc-500 transition hover:text-zinc-950">
                                        Mark as read
                                    </button>
                                @endunless
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                        <flux:icon icon="bell-slash" class="size-8 text-zinc-400" />
                        <p class="text-sm font-medium text-zinc-950">No unread notifications</p>
                        <p class="text-xs text-zinc-500">You are caught up for now.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
