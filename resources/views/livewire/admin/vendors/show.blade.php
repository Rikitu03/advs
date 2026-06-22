<?php

use App\Support\DemoData;
use App\Support\DemoStore;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * The vendor profile. Named $record so the {vendor} route parameter is
     * not auto-assigned to it before mount() runs.
     *
     * @var array<string, mixed>
     */
    public array $record;

    public function mount(string $vendor): void
    {
        $found = DemoStore::findVendor($vendor);

        abort_if($found === null, 404);

        $this->record = $found;
    }
}; ?>

<x-page>
    @php
        $v = $record;
        $statusColor = DemoData::vendorStatusColor($v['status']);
        $riskLevel = DemoData::riskLevel((int) $v['risk_score']);
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-3">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('admin.vendors') }}" wire:navigate>Vendor Profiles</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $v['company'] }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-cu-purple/15 text-lg font-semibold text-cu-purple">
                        {{ \Illuminate\Support\Str::of($v['company'])->explode(' ')->take(2)->map(fn ($w) => \Illuminate\Support\Str::substr($w, 0, 1))->implode('') }}
                    </span>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $v['company'] }}</h1>
                            <flux:badge :color="$statusColor">{{ str($v['status'])->headline() }}</flux:badge>
                        </div>
                        <p class="text-sm text-cu-muted">{{ $v['contact'] }} · Registered {{ $v['registered_at']->format('M j, Y') }}</p>
                    </div>
                </div>
                <a href="{{ route('admin.vendors') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 px-3 py-2 text-sm font-medium text-cu-muted transition hover:border-white/20 hover:text-white">
                    <flux:icon icon="arrow-left" class="size-4" />
                    All vendors
                </a>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Company details --}}
            <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-5 lg:col-span-2" style="animation-delay: 60ms">
                <h2 class="text-base font-semibold text-white">Company details</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-cu-muted">Registration number</dt>
                        <dd class="mt-0.5 text-sm text-zinc-200">{{ $v['registration_number'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Phone</dt>
                        <dd class="mt-0.5 text-sm text-zinc-200">{{ $v['phone'] }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-cu-muted">Address</dt>
                        <dd class="mt-0.5 text-sm text-zinc-200">{{ $v['address'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Registered on</dt>
                        <dd class="mt-0.5 text-sm text-zinc-200">{{ $v['registered_at']->format('F j, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Last submission</dt>
                        <dd class="mt-0.5 text-sm text-zinc-200">{{ $v['last_submission_at']?->diffForHumans() ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Accreditation snapshot --}}
            <div class="cu-animate-in flex flex-col gap-4 rounded-2xl border border-white/5 bg-cu-surface p-5" style="animation-delay: 120ms">
                <h2 class="text-base font-semibold text-white">Accreditation</h2>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Status</span>
                    <flux:badge :color="$statusColor">{{ str($v['status'])->headline() }}</flux:badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Latest risk</span>
                    <x-risk-badge :level="$riskLevel" :score="(int) $v['risk_score']" />
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Submissions</span>
                    <span class="text-sm font-semibold text-white">{{ $v['submissions_count'] }}</span>
                </div>
            </div>
        </div>

        {{-- Reference biometrics --}}
        <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-5" style="animation-delay: 180ms">
            <h2 class="text-base font-semibold text-white">Reference biometrics</h2>
            <p class="text-xs text-cu-muted">Enrolled on the vendor's first approved submission (§5 4a/4b).</p>

            @if ($v['enrolled'])
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="flex items-center gap-4 rounded-xl border border-white/5 bg-cu-bg p-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-blue/15 text-cu-blue">
                            <flux:icon icon="finger-print" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-white">Signature embedding</p>
                            <p class="text-xs text-cu-muted">
                                {{ $v['signature_ref']['model'] }} · {{ $v['signature_ref']['dimensions'] }}-D ·
                                enrolled {{ $v['signature_ref']['enrolled_at']->format('M j, Y') }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 rounded-xl border border-white/5 bg-cu-bg p-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-pink/15 text-cu-pink">
                            <flux:icon icon="check-badge" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-white">Stamp feature vector</p>
                            <p class="text-xs text-cu-muted">
                                {{ $v['stamp_ref']['model'] }} · {{ $v['stamp_ref']['metric'] }} ·
                                enrolled {{ $v['stamp_ref']['enrolled_at']->format('M j, Y') }}
                            </p>
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-4 flex items-center gap-3 rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3">
                    <flux:icon icon="minus-circle" class="size-5 shrink-0 text-amber-300" />
                    <p class="text-sm text-zinc-200">No reference signature or stamp enrolled yet — enrollment runs on the vendor's first approved submission.</p>
                </div>
            @endif
        </div>

        {{-- Submission history --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-white/5 bg-cu-surface" style="animation-delay: 240ms">
            <div class="border-b border-white/5 px-5 py-4">
                <h2 class="text-base font-semibold text-white">Submission history</h2>
            </div>
            @if ($v['submissions']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/5 text-xs uppercase tracking-wide text-cu-muted">
                                <th class="px-5 py-3 font-medium">Reference</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Submitted</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Risk</th>
                                <th class="px-5 py-3 text-right font-medium">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($v['submissions'] as $s)
                                <tr wire:key="history-{{ $s['id'] }}" class="transition hover:bg-white/[0.03]">
                                    <td class="px-5 py-4 font-medium text-white">{{ $s['ref'] }}</td>
                                    <td class="px-5 py-4 text-cu-muted">{{ $s['document_type'] }}</td>
                                    <td class="px-5 py-4 text-cu-muted">{{ $s['submitted_at']->diffForHumans() }}</td>
                                    <td class="px-5 py-4">
                                        @php($label = \Illuminate\Support\Str::of($s['status'])->replace('_', ' ')->headline())
                                        @if ($s['status'] === 'approved')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-300">{{ $label }}</span>
                                        @elseif ($s['status'] === 'rejected')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-300">{{ $label }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-300">{{ $label }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4"><x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" /></td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10">
                                            View
                                            <flux:icon icon="arrow-up-right" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-5 py-10 text-center text-sm text-cu-muted">No submissions on record for this vendor.</p>
            @endif
        </div>
    </div>
</x-page>
