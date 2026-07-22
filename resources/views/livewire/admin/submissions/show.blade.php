<?php

use App\Models\Submission;
use App\Services\Document\OfficerDecisionService;
use App\Support\SubmissionPresenter;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /** The submission id under review (from the {submission} route parameter). */
    public int $submissionId;

    /**
     * Presented drill-down data for the template.
     *
     * @var array<string, mixed>
     */
    public array $record;

    /** Modal state: null when closed, 'approve' or 'resubmit' while confirming. */
    public ?string $confirming = null;

    public string $comments = '';

    /** Active risk-score breakdown filter: 'all' or a key from component_filters. */
    public string $componentFilter = 'all';

    public function mount(string $submission): void
    {
        $model = Submission::query()
            ->whereIn('status', [
                Submission::STATUS_PENDING_REVIEW,
                Submission::STATUS_APPROVED,
                Submission::STATUS_RESUBMISSION_REQUESTED,
            ])
            ->find($submission);

        abort_if($model === null, 404);

        $this->submissionId = $model->id;
        $this->record = SubmissionPresenter::detail($model);
    }

    public function setComponentFilter(string $key): void
    {
        abort_unless(array_key_exists($key, $this->record['component_sets']), 400);

        $this->componentFilter = $key;
    }

    public function startDecision(string $mode): void
    {
        abort_unless(in_array($mode, ['approve', 'resubmit'], true), 400);

        $this->confirming = $mode;
        $this->comments = '';
    }

    public function cancelDecision(): void
    {
        $this->confirming = null;
    }

    public function submitDecision(OfficerDecisionService $decisions): void
    {
        if ($this->confirming === null) {
            return;
        }

        $submission = Submission::query()->findOrFail($this->submissionId);

        abort_unless($submission->status === Submission::STATUS_PENDING_REVIEW, 400);

        $decisions->decide(
            $submission,
            Auth::user(),
            $this->confirming === 'approve' ? Submission::STATUS_APPROVED : Submission::STATUS_RESUBMISSION_REQUESTED,
            $this->comments,
        );

        $this->confirming = null;
        $this->refreshRecord();
    }

    private function refreshRecord(): void
    {
        $this->record = SubmissionPresenter::detail(
            Submission::query()->findOrFail($this->submissionId),
        );
    }
}; ?>

<x-page>
    @php
        $s = $record;
        $c = $s['component_sets'][$componentFilter] ?? $s['components'];
        $decision = $s['decision'];
        $thresholds = config('advs.thresholds');

        // Normalised component-breakdown rows (ADVS_System_Reference.md §6).
        // A null score means the stage is not yet live (standby pipeline).
        $sigDetected = $c['signature']['detected'];
        $stampDetected = $c['stamp']['detected'];

        $breakdown = [
            [
                'key' => 'text', 'label' => 'Text Validation (OCR)', 'icon' => 'document-text',
                'score' => $c['text']['score'] !== null ? $c['text']['score'].'%' : 'Unavailable',
                'threshold' => round($thresholds['text'] * 100).'%',
                'pass' => $c['text']['pass'], 'detail' => $c['text']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'classification', 'label' => 'Document Classification (ResNet-50)', 'icon' => 'sparkles',
                'score' => $c['classification']['confidence'] !== null ? $c['classification']['confidence'].'% conf.' : 'Unavailable',
                'threshold' => round($thresholds['classification'] * 100).'%',
                'pass' => $c['classification']['pass'], 'detail' => $c['classification']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'signature', 'label' => 'Signature Match (Siamese CNN)', 'icon' => 'finger-print',
                'score' => $sigDetected ? $c['signature']['similarity'].'% sim.' : 'Not detected',
                'threshold' => round($thresholds['signature'] * 100).'%',
                'pass' => $c['signature']['pass'], 'detail' => $c['signature']['detail'], 'expandable' => true,
            ],
            [
                'key' => 'stamp', 'label' => 'Stamp Match (EfficientNet)', 'icon' => 'check-badge',
                'score' => $stampDetected ? $c['stamp']['similarity'].'% sim.' : 'Not detected',
                'threshold' => round($thresholds['stamp'] * 100).'%',
                'pass' => $c['stamp']['pass'], 'detail' => $c['stamp']['detail'], 'expandable' => true,
            ],
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6"
         x-data="{
             expanded: { signature: false, stamp: false },
             preview: { open: false, name: '', type: '', url: '', kind: '' },
             showPreview(doc) { this.preview = { open: true, ...doc }; },
             closePreview() { this.preview.open = false; },
         }">

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
                        <h1 class="text-2xl font-semibold tracking-tight text-cu-text">{{ $s['company'] }}</h1>
                        @if ($decision === 'approved')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                <flux:icon icon="check-circle" variant="micro" class="size-3.5" /> Approved
                            </span>
                        @elseif ($decision === 'resubmission_requested')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300">
                                <flux:icon icon="arrow-path" variant="micro" class="size-3.5" /> Resubmission requested
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300">
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
                   class="inline-flex items-center gap-1.5 rounded-xl border border-cu-border px-3 py-2 text-sm font-medium text-cu-muted transition hover:border-cu-border hover:text-cu-text">
                    <flux:icon icon="arrow-left" class="size-4" />
                    Back to queue
                </a>
            </div>
        </div>

        {{-- Risk summary --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="cu-animate-in flex items-center gap-6 rounded-2xl border border-cu-border bg-cu-surface p-6" style="animation-delay: 60ms">
                <x-risk-gauge :score="$s['risk_score']" :level="$s['risk_level']" />
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-cu-muted">Composite risk score</p>
                    <x-risk-badge :level="$s['risk_level']" class="w-fit !px-3 !py-1.5 !text-sm" />
                    <p class="mt-1 max-w-xs text-sm text-cu-muted">{{ $s['risk_driver'] }}</p>
                </div>
            </div>

            {{-- Decision panel --}}
            <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-6 lg:col-span-2" style="animation-delay: 120ms">
                <h2 class="text-base font-semibold text-cu-text">Officer decision</h2>
                <p class="mt-1 text-sm text-cu-muted">
                    The pipeline only raises flags and a risk score — a compliance officer makes the final call.
                </p>

                @if ($decision === null)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="startDecision('approve')"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">
                            <flux:icon icon="check-circle" class="size-4" /> Approve accreditation
                        </button>
                        <button type="button" wire:click="startDecision('resubmit')"
                                class="inline-flex items-center gap-2 rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-400">
                            <flux:icon icon="arrow-path" class="size-4" /> Request resubmission
                        </button>
                    </div>
                @else
                    <div class="cu-animate-in mt-4 flex items-start gap-3 rounded-xl border border-cu-border bg-cu-bg px-4 py-3">
                        @if ($decision === 'approved')
                            <flux:icon icon="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-400" />
                        @else
                            <flux:icon icon="arrow-path" class="mt-0.5 size-5 shrink-0 text-rose-400" />
                        @endif
                        <div class="min-w-0 flex-1 text-sm text-cu-text">
                            <p>
                                <span class="font-semibold text-cu-text">{{ $decision === 'approved' ? 'Approved' : 'Resubmission requested' }}</span>
                                by {{ $s['reviewed_by'] }} · {{ $s['reviewed_at']->diffForHumans() }}
                            </p>
                            @if ($s['review_comments'])
                                <p class="mt-1 text-cu-muted">"{{ $s['review_comments'] }}"</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Component breakdown --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 180ms">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-cu-border px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold text-cu-text">Risk score breakdown</h2>
                    <p class="text-xs text-cu-muted">Each component's contribution. Expand a forensic check to compare reference vs. query.</p>
                </div>
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1" wire:loading.class="opacity-60" wire:target="setComponentFilter">
                    @foreach ($s['component_filters'] as $filter)
                        <button type="button" wire:click="setComponentFilter('{{ $filter['key'] }}')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $componentFilter === $filter['key'] ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $filter['label'] }}</button>
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto" wire:loading.class="opacity-40" wire:target="setComponentFilter">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-cu-border text-xs uppercase tracking-wide text-cu-muted">
                            <th class="px-5 py-3 font-medium">Component</th>
                            <th class="px-5 py-3 font-medium">Score</th>
                            <th class="px-5 py-3 font-medium">Threshold</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @foreach ($breakdown as $row)
                            <tr
                                @if ($row['expandable']) x-on:click="expanded.{{ $row['key'] }} = !expanded.{{ $row['key'] }}" class="cursor-pointer transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03]" @endif
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-black/5 text-cu-muted dark:bg-white/5">
                                            <flux:icon :icon="$row['icon']" class="size-4" />
                                        </span>
                                        <span class="font-medium text-cu-text">{{ $row['label'] }}</span>
                                        @if ($row['expandable'])
                                            <span class="text-cu-muted transition" :class="expanded.{{ $row['key'] }} && 'rotate-180'">
                                                <flux:icon icon="chevron-down" class="size-4" />
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-semibold text-cu-text">{{ $row['score'] }}</td>
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
                        <tr class="bg-black/[0.02] dark:bg-white/[0.02]">
                            <td class="px-5 py-4">
                                <span class="font-semibold text-cu-text">Composite Risk Score</span>
                            </td>
                            <td class="px-5 py-4 font-bold text-cu-text">{{ $s['risk_score'] }} / 100</td>
                            <td class="px-5 py-4 text-cu-muted">—</td>
                            <td class="px-5 py-4"><x-risk-badge :level="$s['risk_level']" /></td>
                            <td class="px-5 py-4 text-cu-muted">{{ $s['risk_driver'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- OCR (full width) over flags + documents --}}
        <div class="flex flex-col gap-6">
            {{-- OCR text --}}
            <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-5" style="animation-delay: 240ms">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-cu-text">OCR extracted text</h2>
                    <flux:badge size="sm" color="zinc">PyTesseract</flux:badge>
                </div>
                <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap rounded-xl border border-cu-border bg-cu-bg p-4 font-mono text-xs leading-relaxed text-cu-muted">{{ $s['ocr_excerpt'] }}</pre>
            </div>

            {{-- Flags + documents --}}
            <div class="grid gap-6 lg:grid-cols-2">
                {{-- Flags --}}
                <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-5" style="animation-delay: 300ms">
                    <h2 class="text-base font-semibold text-cu-text">Flags raised</h2>
                    @if (count($s['flags_by_document']) > 0)
                        <div class="mt-3 flex flex-col gap-3">
                            @foreach ($s['flags_by_document'] as $group)
                                <div x-data="{ open: true }">
                                    <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                            class="flex w-full items-center gap-2 rounded-lg py-0.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-cu-purple/40">
                                        <span class="shrink-0 text-cu-muted transition-transform duration-200" :class="open && 'rotate-90'">
                                            <flux:icon icon="chevron-right" class="size-3.5" />
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-xs font-medium text-cu-muted">{{ $group['type'] }}</span>
                                        <span class="inline-flex min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-rose-500/10 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-rose-600 dark:text-rose-300">{{ count($group['flags']) }}</span>
                                    </button>
                                    <ul x-show="open" x-collapse class="mt-2 flex flex-col gap-2">
                                        @foreach ($group['flags'] as $flag)
                                            <li class="flex items-start gap-2.5 rounded-xl border border-rose-500/20 bg-rose-500/5 px-3 py-2.5">
                                                <flux:icon icon="flag" class="mt-0.5 size-4 shrink-0 text-rose-400" />
                                                <span class="min-w-0 break-words text-sm text-cu-text">{{ $flag }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-3 flex items-center gap-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-3 py-2.5">
                            <flux:icon icon="check-circle" class="size-4 shrink-0 text-emerald-400" />
                            <span class="text-sm text-cu-text">No flags raised — all components passed.</span>
                        </div>
                    @endif
                </div>

                {{-- Documents --}}
                <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-5" style="animation-delay: 360ms">
                    <h2 class="text-base font-semibold text-cu-text">Documents ({{ count($s['documents']) }})</h2>
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($s['documents'] as $doc)
                            <li>
                                <button type="button"
                                        x-on:click="showPreview({{ \Illuminate\Support\Js::from([
                                            'name' => $doc['name'],
                                            'type' => $doc['type'],
                                            'url' => route('admin.documents.show', $doc['id']),
                                            'kind' => $doc['kind'],
                                        ]) }})"
                                        class="flex w-full items-center gap-3 rounded-xl border border-cu-border bg-cu-bg px-3 py-2.5 text-left transition hover:border-cu-purple focus:outline-none focus-visible:ring-2 focus-visible:ring-cu-purple/40">
                                    <flux:icon icon="document-text" class="size-5 shrink-0 text-cu-blue" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-cu-text">{{ $doc['name'] }}</p>
                                        <p class="text-xs text-cu-muted">{{ $doc['type'] }} · {{ $doc['pages'] }} {{ \Illuminate\Support\Str::plural('page', $doc['pages']) }} · {{ $doc['size'] }}</p>
                                    </div>
                                    <flux:icon icon="eye" class="size-4 shrink-0 text-cu-muted" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Document preview — renders inline, no navigation (Alpine-driven) --}}
        <div x-cloak x-show="preview.open" x-transition.opacity
             class="fixed inset-0 z-50 flex flex-col"
             x-on:keydown.escape.window="closePreview()"
             role="dialog" aria-modal="true" aria-label="Document preview">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" x-on:click="closePreview()"></div>

            {{-- Toolbar --}}
            <div class="relative z-10 flex items-center justify-between gap-4 px-4 py-3 text-white sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <flux:icon icon="document-text" class="size-5 shrink-0 text-white/70" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" x-text="preview.name"></p>
                        <p class="truncate text-xs text-white/60" x-text="preview.type"></p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a :href="preview.url" target="_blank"
                       class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 px-3 py-1.5 text-xs font-medium text-white/90 transition hover:bg-white/10">
                        <flux:icon icon="arrow-top-right-on-square" class="size-4" /> Open original
                    </a>
                    <button type="button" x-on:click="closePreview()" aria-label="Close preview"
                            class="inline-flex size-8 items-center justify-center rounded-lg text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50">
                        <flux:icon icon="x-mark" class="size-5" />
                    </button>
                </div>
            </div>

            {{-- Viewer --}}
            <div class="relative z-10 flex flex-1 items-center justify-center overflow-hidden px-4 pb-4 sm:px-6 sm:pb-6" x-on:click="closePreview()">
                <template x-if="preview.open && preview.kind === 'image'">
                    <img :src="preview.url" :alt="preview.name" x-on:click.stop
                         class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" />
                </template>
                <template x-if="preview.open && preview.kind === 'pdf'">
                    <iframe :src="preview.url" x-on:click.stop title="Document preview"
                            class="h-full w-full rounded-lg border-0 bg-white shadow-2xl"></iframe>
                </template>
                <template x-if="preview.open && preview.kind === 'other'">
                    <div x-on:click.stop class="flex flex-col items-center gap-3 rounded-2xl bg-cu-surface px-8 py-10 text-center">
                        <flux:icon icon="document" class="size-10 text-cu-muted" />
                        <p class="text-sm text-cu-text">This file type can't be previewed here.</p>
                        <a :href="preview.url" target="_blank"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-cu-purple px-3 py-2 text-sm font-medium text-white transition hover:opacity-90">
                            <flux:icon icon="arrow-down-tray" class="size-4" /> Download to view
                        </a>
                    </div>
                </template>
            </div>
        </div>

    </div>

    {{-- Approve / Request-resubmission confirmation modal (Livewire-driven) --}}
    @if ($confirming !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.cancelDecision()">
            <div wire:click="cancelDecision" class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>

            <div class="cu-animate-in relative w-full max-w-md rounded-2xl border border-cu-border bg-cu-surface p-6 text-cu-text shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $confirming === 'approve' ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/15 text-rose-700 dark:text-rose-300' }}">
                        <flux:icon :icon="$confirming === 'approve' ? 'check-circle' : 'arrow-path'" class="size-5" />
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold text-cu-text">{{ $confirming === 'approve' ? 'Approve accreditation' : 'Request resubmission' }}</h3>
                        <p class="mt-1 text-sm text-cu-muted">{{ $s['company'] }} · {{ $s['ref'] }}</p>
                    </div>
                </div>

                <label class="mt-4 block text-sm font-medium text-cu-text">
                    Comments <span class="text-cu-muted">(optional)</span>
                    <textarea
                        wire:model="comments"
                        rows="3"
                        placeholder="Add a note for the audit trail…"
                        class="mt-1.5 w-full rounded-xl border border-cu-border bg-cu-bg p-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    ></textarea>
                </label>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" wire:click="cancelDecision"
                            class="rounded-xl border border-cu-border px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:text-cu-text">
                        Cancel
                    </button>
                    <button type="button" wire:click="submitDecision" wire:loading.attr="disabled" wire:target="submitDecision"
                            class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition disabled:cursor-wait disabled:opacity-75 {{ $confirming === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-rose-500 hover:bg-rose-600' }}">
                        <flux:icon.loading wire:loading wire:target="submitDecision" variant="micro" class="size-4" />
                        <span wire:loading.remove wire:target="submitDecision">{{ $confirming === 'approve' ? 'Confirm approval' : 'Confirm request' }}</span>
                        <span wire:loading wire:target="submitDecision">Recording decision…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-page>
