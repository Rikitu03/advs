<?php

use App\Support\DemoData;
use App\Support\DemoStore;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * The submission under review. Named $record so the {submission} route
     * parameter is not auto-assigned to it before mount() runs.
     *
     * @var array<string, mixed>
     */
    public array $record;

    /** Modal state: null when closed, 'approve' or 'reject' while confirming. */
    public ?string $confirming = null;

    public string $comments = '';

    public function mount(string $submission): void
    {
        $found = DemoStore::findSubmission($submission);

        abort_if($found === null, 404);

        $this->record = $found;
    }

    public function startDecision(string $mode): void
    {
        abort_unless(in_array($mode, ['approve', 'reject'], true), 400);

        $this->confirming = $mode;
        $this->comments = '';
    }

    public function cancelDecision(): void
    {
        $this->confirming = null;
    }

    public function submitDecision(): void
    {
        if ($this->confirming === null) {
            return;
        }

        // Simulated processing so the confirm-button spinner is visible.
        DemoStore::simulateProcessing(700);

        DemoStore::decide(
            $this->record['id'],
            $this->confirming === 'approve' ? 'approved' : 'rejected',
            $this->comments,
        );

        $this->confirming = null;
        $this->refreshRecord();
    }

    public function undoDecision(): void
    {
        DemoStore::simulateProcessing(400);
        DemoStore::undoDecision($this->record['id']);
        $this->refreshRecord();
    }

    private function refreshRecord(): void
    {
        $this->record = DemoStore::findSubmission($this->record['id']);
    }
}; ?>

<div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
    @php
        $s = $record;
        $c = $s['components'];
        $decision = $s['decision'];

        // Normalised component-breakdown rows (ADVS_System_Reference.md §6).
        $sigDetected = $c['signature']['detected'];
        $stampDetected = $c['stamp']['detected'];

        $breakdown = [
            [
                'key' => 'text', 'label' => 'Text Validation (OCR)', 'icon' => 'document-text',
                'score' => $c['text']['score'].'%', 'threshold' => DemoData::TEXT_THRESHOLD.'%',
                'pass' => $c['text']['pass'], 'detail' => $c['text']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'classification', 'label' => 'Document Classification (ResNet-50)', 'icon' => 'sparkles',
                'score' => $c['classification']['confidence'].'% conf.', 'threshold' => DemoData::CLASSIFICATION_THRESHOLD.'%',
                'pass' => $c['classification']['pass'], 'detail' => $c['classification']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'signature', 'label' => 'Signature Match (Siamese CNN)', 'icon' => 'finger-print',
                'score' => $sigDetected ? $c['signature']['similarity'].'% sim.' : 'Not detected', 'threshold' => DemoData::SIGNATURE_THRESHOLD.'%',
                'pass' => $c['signature']['pass'], 'detail' => $c['signature']['detail'], 'expandable' => true,
            ],
            [
                'key' => 'stamp', 'label' => 'Stamp Match (EfficientNet)', 'icon' => 'check-badge',
                'score' => $stampDetected ? $c['stamp']['similarity'].'% sim.' : 'Not detected', 'threshold' => DemoData::STAMP_THRESHOLD.'%',
                'pass' => $c['stamp']['pass'], 'detail' => $c['stamp']['detail'], 'expandable' => true,
            ],
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6" x-data="{ expanded: { signature: false, stamp: false } }">

        {{-- Breadcrumb + heading --}}
        <div class="cu-animate-in flex flex-col gap-3">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('admin.pending') }}" wire:navigate>Pending Submissions</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $s['ref'] }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $s['company'] }}</h1>
                        @if ($decision === 'approved')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                <flux:icon icon="check-circle" variant="micro" class="size-3.5" /> Approved
                            </span>
                        @elseif ($decision === 'rejected')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-300">
                                <flux:icon icon="x-circle" variant="micro" class="size-3.5" /> Rejected
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-300">
                                <span class="size-1.5 rounded-full bg-amber-400"></span> Pending review
                            </span>
                        @endif
                    </div>
                    <p class="text-sm text-cu-muted">
                        {{ $s['ref'] }} · Submitted by {{ $s['vendor'] }} · {{ $s['submitted_at']->format('M j, Y g:i A') }}
                        ({{ $s['submitted_at']->diffForHumans() }})
                    </p>
                </div>
                <a href="{{ route('admin.pending') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 px-3 py-2 text-sm font-medium text-cu-muted transition hover:border-white/20 hover:text-white">
                    <flux:icon icon="arrow-left" class="size-4" />
                    Back to queue
                </a>
            </div>
        </div>

        {{-- Risk summary --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="cu-animate-in flex items-center gap-6 rounded-2xl border border-white/5 bg-cu-surface p-6" style="animation-delay: 60ms">
                <x-risk-gauge :score="$s['risk_score']" :level="$s['risk_level']" />
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-cu-muted">Composite risk score</p>
                    <x-risk-badge :level="$s['risk_level']" class="w-fit !px-3 !py-1.5 !text-sm" />
                    <p class="mt-1 max-w-xs text-sm text-zinc-300">{{ $s['risk_driver'] }}</p>
                </div>
            </div>

            {{-- Decision panel --}}
            <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-6 lg:col-span-2" style="animation-delay: 120ms">
                <h2 class="text-base font-semibold text-white">Officer decision</h2>
                <p class="mt-1 text-sm text-cu-muted">
                    The pipeline only raises flags and a risk score — a compliance officer makes the final call.
                </p>

                @if ($decision === null)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="startDecision('approve')"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">
                            <flux:icon icon="check-circle" class="size-4" /> Approve accreditation
                        </button>
                        <button type="button" wire:click="startDecision('reject')"
                                class="inline-flex items-center gap-2 rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-400">
                            <flux:icon icon="x-circle" class="size-4" /> Reject
                        </button>
                    </div>
                @else
                    <div class="cu-animate-in mt-4 flex items-start gap-3 rounded-xl border border-white/10 bg-cu-bg px-4 py-3">
                        @if ($decision === 'approved')
                            <flux:icon icon="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-400" />
                        @else
                            <flux:icon icon="x-circle" class="mt-0.5 size-5 shrink-0 text-rose-400" />
                        @endif
                        <div class="min-w-0 flex-1 text-sm text-zinc-200">
                            <p>
                                <span class="font-semibold text-white">{{ $decision === 'approved' ? 'Approved' : 'Rejected' }}</span>
                                by {{ $s['reviewed_by'] }} · {{ $s['reviewed_at']->diffForHumans() }}
                            </p>
                            @if ($s['review_comments'])
                                <p class="mt-1 text-cu-muted">“{{ $s['review_comments'] }}”</p>
                            @endif
                        </div>
                        <button type="button" wire:click="undoDecision" wire:loading.attr="disabled" wire:target="undoDecision"
                                class="ml-auto inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-cu-blue transition hover:text-cu-purple disabled:opacity-50">
                            <flux:icon.loading wire:loading wire:target="undoDecision" variant="micro" class="size-3.5" />
                            Undo
                        </button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Component breakdown --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-white/5 bg-cu-surface" style="animation-delay: 180ms">
            <div class="border-b border-white/5 px-5 py-4">
                <h2 class="text-base font-semibold text-white">Risk score breakdown</h2>
                <p class="text-xs text-cu-muted">Each component's contribution. Expand a forensic check to compare reference vs. query.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-white/5 text-xs uppercase tracking-wide text-cu-muted">
                            <th class="px-5 py-3 font-medium">Component</th>
                            <th class="px-5 py-3 font-medium">Score</th>
                            <th class="px-5 py-3 font-medium">Threshold</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($breakdown as $row)
                            <tr
                                @if ($row['expandable']) x-on:click="expanded.{{ $row['key'] }} = !expanded.{{ $row['key'] }}" class="cursor-pointer transition hover:bg-white/[0.03]" @endif
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/5 text-cu-muted">
                                            <flux:icon :icon="$row['icon']" class="size-4" />
                                        </span>
                                        <span class="font-medium text-white">{{ $row['label'] }}</span>
                                        @if ($row['expandable'])
                                            <span class="text-cu-muted transition" :class="expanded.{{ $row['key'] }} && 'rotate-180'">
                                                <flux:icon icon="chevron-down" class="size-4" />
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-semibold text-white">{{ $row['score'] }}</td>
                                <td class="px-5 py-4 text-cu-muted">{{ $row['threshold'] }}</td>
                                <td class="px-5 py-4">
                                    <x-pass-fail :pass="$row['pass']" :neutral-label="$row['expandable'] && ! $row['pass'] ? 'Fail' : null" />
                                </td>
                                <td class="px-5 py-4 text-cu-muted">{{ $row['detail'] }}</td>
                            </tr>

                            {{-- Expandable forensic comparison panel --}}
                            @if ($row['key'] === 'signature')
                                <tr x-show="expanded.signature" x-cloak>
                                    <td colspan="5" class="bg-cu-bg/60 px-5 py-5">
                                        <x-signature-compare :data="$c['signature']" />
                                    </td>
                                </tr>
                            @elseif ($row['key'] === 'stamp')
                                <tr x-show="expanded.stamp" x-cloak>
                                    <td colspan="5" class="bg-cu-bg/60 px-5 py-5">
                                        <x-stamp-compare :data="$c['stamp']" />
                                    </td>
                                </tr>
                            @endif
                        @endforeach

                        {{-- Composite total --}}
                        <tr class="bg-white/[0.02]">
                            <td class="px-5 py-4">
                                <span class="font-semibold text-white">Composite Risk Score</span>
                            </td>
                            <td class="px-5 py-4 font-bold text-white">{{ $s['risk_score'] }} / 100</td>
                            <td class="px-5 py-4 text-cu-muted">—</td>
                            <td class="px-5 py-4"><x-risk-badge :level="$s['risk_level']" /></td>
                            <td class="px-5 py-4 text-cu-muted">{{ $s['risk_driver'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Flags + documents + OCR --}}
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Flags --}}
            <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-5" style="animation-delay: 240ms">
                <h2 class="text-base font-semibold text-white">Flags raised</h2>
                @if (count($s['flags']) > 0)
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($s['flags'] as $flag)
                            <li class="flex items-start gap-2.5 rounded-xl border border-rose-500/20 bg-rose-500/5 px-3 py-2.5">
                                <flux:icon icon="flag" class="mt-0.5 size-4 shrink-0 text-rose-400" />
                                <span class="text-sm text-zinc-200">{{ $flag }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-3 flex items-center gap-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-3 py-2.5">
                        <flux:icon icon="check-circle" class="size-4 shrink-0 text-emerald-400" />
                        <span class="text-sm text-zinc-200">No flags raised — all components passed.</span>
                    </div>
                @endif
            </div>

            {{-- Documents --}}
            <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-5" style="animation-delay: 300ms">
                <h2 class="text-base font-semibold text-white">Documents ({{ count($s['documents']) }})</h2>
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($s['documents'] as $doc)
                        <li class="flex items-center gap-3 rounded-xl border border-white/5 bg-cu-bg px-3 py-2.5">
                            <flux:icon icon="document-text" class="size-5 shrink-0 text-cu-blue" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-white">{{ $doc['name'] }}</p>
                                <p class="text-xs text-cu-muted">{{ $doc['type'] }} · {{ $doc['pages'] }} {{ \Illuminate\Support\Str::plural('page', $doc['pages']) }} · {{ $doc['size'] }}</p>
                            </div>
                            <flux:icon icon="document-arrow-down" class="size-4 shrink-0 text-cu-muted" />
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- OCR text --}}
            <div class="cu-animate-in rounded-2xl border border-white/5 bg-cu-surface p-5" style="animation-delay: 360ms">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-white">OCR extracted text</h2>
                    <flux:badge size="sm" color="zinc">PyTesseract</flux:badge>
                </div>
                <pre class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap rounded-xl border border-white/5 bg-cu-bg p-3 font-mono text-xs leading-relaxed text-zinc-300">{{ $s['ocr_excerpt'] }}</pre>
            </div>
        </div>

        <p class="text-center text-xs text-cu-muted/70">
            Prototype data — decisions are kept in your session only and reset from the dashboard.
        </p>
    </div>

    {{-- Approve / Reject confirmation modal (Livewire-driven) --}}
    @if ($confirming !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.cancelDecision()">
            <div wire:click="cancelDecision" class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>

            <div class="cu-animate-in relative w-full max-w-md rounded-2xl border border-white/10 bg-cu-surface p-6 text-cu-text shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $confirming === 'approve' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300' }}">
                        <flux:icon :icon="$confirming === 'approve' ? 'check-circle' : 'x-circle'" class="size-5" />
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold text-white">{{ $confirming === 'approve' ? 'Approve accreditation' : 'Reject submission' }}</h3>
                        <p class="mt-1 text-sm text-cu-muted">{{ $s['company'] }} · {{ $s['ref'] }}</p>
                    </div>
                </div>

                <label class="mt-4 block text-sm font-medium text-zinc-200">
                    Comments <span class="text-cu-muted">(optional)</span>
                    <textarea
                        wire:model="comments"
                        rows="3"
                        placeholder="Add a note for the audit trail…"
                        class="mt-1.5 w-full rounded-xl border border-white/10 bg-cu-bg p-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    ></textarea>
                </label>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" wire:click="cancelDecision"
                            class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:text-white">
                        Cancel
                    </button>
                    <button type="button" wire:click="submitDecision" wire:loading.attr="disabled" wire:target="submitDecision"
                            class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition disabled:cursor-wait disabled:opacity-75 {{ $confirming === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-rose-500 hover:bg-rose-600' }}">
                        <flux:icon.loading wire:loading wire:target="submitDecision" variant="micro" class="size-4" />
                        <span wire:loading.remove wire:target="submitDecision">{{ $confirming === 'approve' ? 'Confirm approval' : 'Confirm rejection' }}</span>
                        <span wire:loading wire:target="submitDecision">Recording decision…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
