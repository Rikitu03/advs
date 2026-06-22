<?php

use App\Support\VendorDemoData;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'kpis' => VendorDemoData::kpis(),
            'submissions' => VendorDemoData::submissions()->take(3),
        ];
    }
}; ?>

<x-page>
    @php($user = auth()->user())

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div class="cu-animate-in relative overflow-hidden rounded-2xl cu-gradient p-6 sm:p-8">
            <div class="relative z-10 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex items-start gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-lg font-semibold text-white uppercase ring-1 ring-white/30 backdrop-blur">
                        {{ $user->initials() }}
                    </span>
                    <div class="flex flex-col gap-2">
                        <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-xs font-medium text-white/90 backdrop-blur">
                            <flux:icon icon="building-office" class="size-3.5" />
                            Vendor workspace
                        </span>
                        <h1 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                            Welcome back, {{ str($user->name)->before(' ') ?: $user->name }}
                        </h1>
                        <p class="max-w-2xl text-sm text-white/80">
                            Submit accreditation documents, track validation progress, and watch for officer updates from one place.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('vendor.submit') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-cu-purple shadow-sm transition hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        <flux:icon icon="arrow-up-tray" class="size-4" />
                        Submit Business Permit
                    </a>
                    <a href="{{ route('vendor.submissions') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-xl border border-white/30 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        <flux:icon icon="document-text" class="size-4" />
                        View submissions
                    </a>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Total submissions', $kpis['total'], 'folder-open', 'bg-cu-purple/10 text-cu-purple', 'Documents sent for validation'],
                ['In progress', $kpis['processing'], 'arrow-path', 'bg-cu-blue/10 text-sky-700', 'Processing or review'],
                ['Approved', $kpis['approved'], 'check-badge', 'bg-cu-yellow/20 text-yellow-700', 'Accepted documents'],
                ['Rejected', $kpis['rejected'], 'x-circle', 'bg-cu-pink/10 text-rose-600', 'Needs resubmission'],
            ] as [$label, $value, $icon, $accent, $hint])
                <div class="cu-animate-in rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-500">{{ $label }}</p>
                            <p class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">{{ $value }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
                        </div>
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $accent }}">
                            <flux:icon :icon="$icon" class="size-5" />
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="cu-animate-in rounded-2xl border border-zinc-200 bg-white shadow-sm" style="animation-delay: 180ms">
            <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold text-zinc-950">Recent submissions</h2>
                    <p class="text-xs text-zinc-500">Latest documents and their review status</p>
                </div>
                <a href="{{ route('vendor.submissions') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-cu-purple hover:text-cu-pink">
                    View all
                    <flux:icon icon="arrow-up-right" class="size-4" />
                </a>
            </div>

            <div class="divide-y divide-zinc-100">
                @foreach ($submissions as $submission)
                    <a href="{{ route('vendor.submissions') }}" wire:navigate class="group grid gap-4 px-5 py-4 transition hover:bg-zinc-50 md:grid-cols-[1fr_auto] md:items-center">
                        <div class="flex min-w-0 gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-purple/10 text-cu-purple">
                                <flux:icon icon="document-text" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-zinc-950">{{ $submission['document_type'] }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ $submission['ref'] }} - {{ $submission['file_name'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 md:justify-end">
                            <span class="text-xs text-zinc-500">{{ $submission['submitted_at']->diffForHumans() }}</span>
                            <x-vendor-status-badge :status="$submission['status']" />
                            <flux:icon icon="chevron-right" class="hidden size-4 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-cu-purple md:block" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-page>
