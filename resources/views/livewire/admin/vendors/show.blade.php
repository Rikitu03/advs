<?php

use App\Models\Vendor;
use App\Support\SubmissionPresenter;
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
        $model = Vendor::query()
            ->with(['user', 'submissions' => fn ($query) => $query->latest()])
            ->find($vendor);

        abort_if($model === null, 404);

        $typeNames = SubmissionPresenter::typeNames();

        $this->record = [
            'id' => $model->id,
            'company' => $model->company_name,
            'contact' => $model->user?->name ?? '—',
            'registration_number' => $model->registration_number ?: ($model->dti_registration_number ?: ($model->sec_registration_number ?: '—')),
            'phone' => $model->phone_number ?: '—',
            'address' => $model->business_address ?: ($model->address ?: '—'),
            'status' => $model->status,
            'risk_score' => $model->risk_score,
            'registered_at' => $model->created_at,
            'last_submission_at' => $model->submissions->first()?->created_at,
            'submissions_count' => $model->submissions->count(),
            'enrolled' => $model->user?->hasEnrolledSignature() ?? false,
            'signature_enrolled_at' => $model->user?->signature_enrolled_at,
            'submissions' => $model->submissions
                ->map(fn ($submission): array => SubmissionPresenter::summary($submission, $typeNames))
                ->values(),
        ];
    }
}; ?>

<x-page>
    @php
        $v = $record;
        $statusColor = \App\Models\Vendor::statusColor($v['status']);
        $riskLevel = $v['risk_score'] !== null
            ? match (true) {
                (float) $v['risk_score'] >= (int) config('advs.risk.high_threshold') => 'high',
                (float) $v['risk_score'] >= (int) config('advs.risk.medium_threshold') => 'medium',
                default => 'low',
            }
            : null;
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
                            <h1 class="text-2xl font-semibold tracking-tight text-cu-text">{{ $v['company'] }}</h1>
                            <flux:badge :color="$statusColor">{{ str($v['status'])->headline() }}</flux:badge>
                        </div>
                        <p class="text-sm text-cu-muted">{{ $v['contact'] }} · Registered {{ $v['registered_at']->format('M j, Y') }}</p>
                    </div>
                </div>
                <a href="{{ route('admin.vendors') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-xl border border-cu-border px-3 py-2 text-sm font-medium text-cu-muted transition hover:border-cu-border hover:text-cu-text">
                    <flux:icon icon="arrow-left" class="size-4" />
                    All vendors
                </a>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Company details --}}
            <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-5 lg:col-span-2" style="animation-delay: 60ms">
                <h2 class="text-base font-semibold text-cu-text">Company details</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-cu-muted">Registration number</dt>
                        <dd class="mt-0.5 text-sm text-cu-text">{{ $v['registration_number'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Phone</dt>
                        <dd class="mt-0.5 text-sm text-cu-text">{{ $v['phone'] }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-cu-muted">Address</dt>
                        <dd class="mt-0.5 text-sm text-cu-text">{{ $v['address'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Registered on</dt>
                        <dd class="mt-0.5 text-sm text-cu-text">{{ $v['registered_at']->format('F j, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">Last submission</dt>
                        <dd class="mt-0.5 text-sm text-cu-text">{{ $v['last_submission_at']?->diffForHumans() ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Accreditation snapshot --}}
            <div class="cu-animate-in flex flex-col gap-4 rounded-2xl border border-cu-border bg-cu-surface p-5" style="animation-delay: 120ms">
                <h2 class="text-base font-semibold text-cu-text">Accreditation</h2>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Status</span>
                    <flux:badge :color="$statusColor">{{ str($v['status'])->headline() }}</flux:badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Latest risk</span>
                    @if ($riskLevel !== null)
                        <x-risk-badge :level="$riskLevel" :score="(int) $v['risk_score']" />
                    @else
                        <span class="text-sm text-cu-muted">—</span>
                    @endif
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-cu-muted">Submissions</span>
                    <span class="text-sm font-semibold text-cu-text">{{ $v['submissions_count'] }}</span>
                </div>
            </div>
        </div>

        {{-- Reference biometrics --}}
        <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-5" style="animation-delay: 180ms">
            <h2 class="text-base font-semibold text-cu-text">Reference biometrics</h2>
            <p class="text-xs text-cu-muted">The signature reference is enrolled at registration (§5 4a); logo/stamp references are issuer-keyed, not per vendor (§5 4b).</p>

            @if ($v['enrolled'])
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="flex items-center gap-4 rounded-xl border border-cu-border bg-cu-bg p-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-blue/15 text-cu-blue">
                            <flux:icon icon="finger-print" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-cu-text">Signature embedding</p>
                            <p class="text-xs text-cu-muted">
                                Siamese CNN · 128-D ·
                                enrolled {{ $v['signature_enrolled_at']?->format('M j, Y') ?? 'at registration' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 rounded-xl border border-cu-border bg-cu-bg p-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-pink/15 text-cu-pink">
                            <flux:icon icon="check-badge" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-cu-text">Issuer logo references</p>
                            <p class="text-xs text-cu-muted">
                                Kept per issuing agency/city in the reference library — not stored on the vendor.
                            </p>
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-4 flex items-center gap-3 rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3">
                    <flux:icon icon="minus-circle" class="size-5 shrink-0 text-amber-400" />
                    <p class="text-sm text-cu-text">No reference signature enrolled yet — signature enrollment is the second step of vendor registration.</p>
                </div>
            @endif
        </div>

        {{-- Submission history --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 240ms">
            <div class="border-b border-cu-border px-5 py-4">
                <h2 class="text-base font-semibold text-cu-text">Submission history</h2>
            </div>
            @if ($v['submissions']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-cu-border text-xs uppercase tracking-wide text-cu-muted">
                                <th class="px-5 py-3 font-medium">Reference</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Submitted</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Risk</th>
                                <th class="px-5 py-3 text-right font-medium">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cu-border">
                            @foreach ($v['submissions'] as $s)
                                <tr wire:key="history-{{ $s['id'] }}" class="transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03]">
                                    <td class="px-5 py-4 font-medium text-cu-text">{{ $s['ref'] }}</td>
                                    <td class="px-5 py-4 text-cu-muted">{{ $s['document_type'] }}</td>
                                    <td class="px-5 py-4 text-cu-muted">{{ $s['submitted_at']->diffForHumans() }}</td>
                                    <td class="px-5 py-4">
                                        @php($label = \Illuminate\Support\Str::of($s['status'])->replace('_', ' ')->headline())
                                        @if ($s['status'] === 'approved')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ $label }}</span>
                                        @elseif ($s['status'] === 'rejected')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300">{{ $label }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $label }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4"><x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" /></td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-cu-border px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-purple hover:bg-cu-purple/10">
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
