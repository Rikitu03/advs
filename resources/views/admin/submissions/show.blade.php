<x-layouts.app>
    @php
        $s = $submission;
        $c = $s['components'];

        // Normalised component-breakdown rows (ADVS_System_Reference.md §6).
        $sigDetected = $c['signature']['detected'];
        $stampDetected = $c['stamp']['detected'];

        $breakdown = [
            [
                'key' => 'text', 'label' => 'Text Validation (OCR)', 'icon' => 'document-text',
                'score' => $c['text']['score'].'%', 'threshold' => \App\Support\DemoData::TEXT_THRESHOLD.'%',
                'pass' => $c['text']['pass'], 'detail' => $c['text']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'classification', 'label' => 'Document Classification (ResNet-50)', 'icon' => 'sparkles',
                'score' => $c['classification']['confidence'].'% conf.', 'threshold' => \App\Support\DemoData::CLASSIFICATION_THRESHOLD.'%',
                'pass' => $c['classification']['pass'], 'detail' => $c['classification']['detail'], 'expandable' => false,
            ],
            [
                'key' => 'signature', 'label' => 'Signature Match (Siamese CNN)', 'icon' => 'finger-print',
                'score' => $sigDetected ? $c['signature']['similarity'].'% sim.' : 'Not detected', 'threshold' => \App\Support\DemoData::SIGNATURE_THRESHOLD.'%',
                'pass' => $c['signature']['pass'], 'detail' => $c['signature']['detail'], 'expandable' => true,
            ],
            [
                'key' => 'stamp', 'label' => 'Stamp Match (EfficientNet)', 'icon' => 'check-badge',
                'score' => $stampDetected ? $c['stamp']['similarity'].'% sim.' : 'Not detected', 'threshold' => \App\Support\DemoData::STAMP_THRESHOLD.'%',
                'pass' => $c['stamp']['pass'], 'detail' => $c['stamp']['detail'], 'expandable' => true,
            ],
        ];
    @endphp

    <div
        class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8"
        x-data="{
            decision: @js($s['decision']),
            expanded: { signature: false, stamp: false },
            modal: { open: false, mode: 'approve', comment: '' },
            openModal(mode) { this.modal.mode = mode; this.modal.comment = ''; this.modal.open = true; },
            confirm() { this.decision = this.modal.mode === 'approve' ? 'approved' : 'rejected'; this.modal.open = false; },
        }"
        x-on:keydown.escape.window="modal.open = false"
    >
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

            {{-- Breadcrumb + heading --}}
            <div class="flex flex-col gap-3">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item href="{{ route('admin.pending') }}" wire:navigate>Pending Submissions</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>{{ $s['ref'] }}</flux:breadcrumbs.item>
                </flux:breadcrumbs>

                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $s['company'] }}</h1>
                            {{-- Live status reflecting the (demo) decision --}}
                            <span x-show="!decision" class="inline-flex items-center gap-1.5 rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-300">
                                <span class="size-1.5 rounded-full bg-amber-400"></span> Pending review
                            </span>
                            <span x-show="decision === 'approved'" x-cloak class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                <flux:icon icon="check-circle" variant="micro" class="size-3.5" /> Approved
                            </span>
                            <span x-show="decision === 'rejected'" x-cloak class="inline-flex items-center gap-1.5 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-300">
                                <flux:icon icon="x-circle" variant="micro" class="size-3.5" /> Rejected
                            </span>
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
                <div class="flex items-center gap-6 rounded-2xl border border-white/5 bg-cu-surface p-6">
                    <x-risk-gauge :score="$s['risk_score']" :level="$s['risk_level']" />
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-medium text-cu-muted">Composite risk score</p>
                        <x-risk-badge :level="$s['risk_level']" class="w-fit !px-3 !py-1.5 !text-sm" />
                        <p class="mt-1 max-w-xs text-sm text-zinc-300">{{ $s['risk_driver'] }}</p>
                    </div>
                </div>

                {{-- Decision panel --}}
                <div class="rounded-2xl border border-white/5 bg-cu-surface p-6 lg:col-span-2">
                    <h2 class="text-base font-semibold text-white">Officer decision</h2>
                    <p class="mt-1 text-sm text-cu-muted">
                        The pipeline only raises flags and a risk score — a compliance officer makes the final call.
                    </p>

                    <div x-show="!decision" class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" x-on:click="openModal('approve')"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">
                            <flux:icon icon="check-circle" class="size-4" /> Approve accreditation
                        </button>
                        <button type="button" x-on:click="openModal('reject')"
                                class="inline-flex items-center gap-2 rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-400">
                            <flux:icon icon="x-circle" class="size-4" /> Reject
                        </button>
                    </div>

                    <div x-show="decision" x-cloak class="mt-4 flex items-center gap-3 rounded-xl border border-white/10 bg-cu-bg px-4 py-3">
                        <flux:icon icon="check-circle" class="size-5 text-emerald-400" x-show="decision === 'approved'" />
                        <flux:icon icon="x-circle" class="size-5 text-rose-400" x-show="decision === 'rejected'" x-cloak />
                        <p class="text-sm text-zinc-200">
                            Decision recorded
                            <span class="font-semibold text-white" x-text="decision === 'approved' ? '(Approved)' : '(Rejected)'"></span>.
                            <span class="text-cu-muted">Persisting the decision &amp; notifying the vendor lands in Phase 8.</span>
                        </p>
                        <button type="button" x-on:click="decision = null" class="ml-auto text-xs font-medium text-cu-blue hover:text-cu-purple">Undo</button>
                    </div>
                </div>
            </div>

            {{-- Component breakdown --}}
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-cu-surface">
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
                <div class="rounded-2xl border border-white/5 bg-cu-surface p-5">
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
                <div class="rounded-2xl border border-white/5 bg-cu-surface p-5">
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
                <div class="rounded-2xl border border-white/5 bg-cu-surface p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-white">OCR extracted text</h2>
                        <flux:badge size="sm" color="zinc">PyTesseract</flux:badge>
                    </div>
                    <pre class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap rounded-xl border border-white/5 bg-cu-bg p-3 font-mono text-xs leading-relaxed text-zinc-300">{{ $s['ocr_excerpt'] }}</pre>
                </div>
            </div>

            <p class="text-center text-xs text-cu-muted/70">
                Sample data for UI review — live validation reports populate once the document pipeline ships (Phase 8).
            </p>
        </div>

        {{-- Approve / Reject modal --}}
        <template x-teleport="body">
            <div x-show="modal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div x-show="modal.open" x-transition.opacity x-on:click="modal.open = false" class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>

                <div
                    x-show="modal.open"
                    x-transition
                    class="relative w-full max-w-md rounded-2xl border border-white/10 bg-cu-surface p-6 text-cu-text shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                              :class="modal.mode === 'approve' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300'">
                            <flux:icon icon="check-circle" class="size-5" x-show="modal.mode === 'approve'" />
                            <flux:icon icon="x-circle" class="size-5" x-show="modal.mode === 'reject'" x-cloak />
                        </span>
                        <div>
                            <h3 class="text-lg font-semibold text-white" x-text="modal.mode === 'approve' ? 'Approve accreditation' : 'Reject submission'"></h3>
                            <p class="mt-1 text-sm text-cu-muted">{{ $s['company'] }} · {{ $s['ref'] }}</p>
                        </div>
                    </div>

                    <label class="mt-4 block text-sm font-medium text-zinc-200">
                        Comments <span class="text-cu-muted">(optional)</span>
                        <textarea
                            x-model="modal.comment"
                            rows="3"
                            placeholder="Add a note for the audit trail…"
                            class="mt-1.5 w-full rounded-xl border border-white/10 bg-cu-bg p-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                        ></textarea>
                    </label>

                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" x-on:click="modal.open = false"
                                class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:text-white">
                            Cancel
                        </button>
                        <button type="button" x-on:click="confirm()"
                                class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition"
                                :class="modal.mode === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-rose-500 hover:bg-rose-600'"
                                x-text="modal.mode === 'approve' ? 'Confirm approval' : 'Confirm rejection'">
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-layouts.app>
