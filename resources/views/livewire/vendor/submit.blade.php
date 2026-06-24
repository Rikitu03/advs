<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $documentType = 'Business Permit';

    public bool $submitted = false;

    public function submitDocuments(): void
    {
        $this->submitted = true;
    }

    public function resetSubmission(): void
    {
        $this->submitted = false;
        $this->documentType = 'Business Permit';
    }
}; ?>

<x-page>
    <div
        class="mx-auto flex w-full max-w-6xl flex-col gap-6"
        x-data="{
            file: null,
            errors: [],
            documentType: 'Business Permit',
            allowed: ['pdf', 'png', 'jpg', 'jpeg'],
            maxBytes: 10 * 1024 * 1024,
            dragActive: false,
            readFiles(fileList) {
                this.errors = [];

                const selected = Array.from(fileList)[0] ?? null;

                if (! selected) {
                    this.file = null;
                    return;
                }

                const extension = selected.name.split('.').pop().toLowerCase();
                const validType = this.allowed.includes(extension);
                const validSize = selected.size <= this.maxBytes;

                if (! validType) {
                    this.errors.push(`${selected.name} must be a PDF, PNG, JPG, or JPEG file.`);
                }

                if (! validSize) {
                    this.errors.push(`${selected.name} is larger than the 10 MB per-file limit.`);
                }

                this.file = {
                    name: selected.name,
                    size: `${(selected.size / 1024 / 1024).toFixed(2)} MB`,
                    extension,
                    valid: validType && validSize,
                };
            },
            clearFiles() {
                this.file = null;
                this.errors = [];
                this.$refs.upload.value = '';
            },
            get canSubmit() {
                return this.file !== null && this.file.valid && this.errors.length === 0;
            },
        }"
    >
        <div class="cu-animate-in">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Submit Documents</h1>
                <p class="mt-1 text-sm text-cu-muted">Choose one document type and upload one file for validation.</p>
            </div>
        </div>

        @if ($submitted)
            <div class="cu-animate-in rounded-2xl border border-emerald-500/40 bg-emerald-500/15 p-5 text-emerald-700 dark:text-emerald-300 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                            <flux:icon icon="check-circle" class="size-5" />
                        </span>
                        <div>
                            <p class="font-semibold">Submission received</p>
                            <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                                Your document is ready for processing and review.
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="resetSubmission" class="inline-flex items-center justify-center rounded-xl border border-emerald-500/40 bg-cu-surface px-3 py-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300 transition hover:bg-emerald-500/15">
                        Submit another
                    </button>
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
            <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface shadow-sm" style="animation-delay: 80ms">
                <div class="border-b border-cu-border px-5 py-4">
                    <h2 class="text-base font-semibold text-cu-text">Document submission</h2>
                    <p class="text-xs text-cu-muted">Business Permit is selected by default.</p>
                </div>

                <div class="flex flex-col gap-5 p-5">
                    <flux:field>
                        <flux:label>Document type</flux:label>
                        <select
                            wire:model="documentType"
                            x-model="documentType"
                            class="h-10 rounded-lg border border-cu-border bg-cu-surface px-3 text-sm text-cu-text outline-none transition focus:border-cu-purple focus:ring-2 focus:ring-cu-purple/20"
                        >
                            <option>Business Permit</option>
                            <option>BIR Permit</option>
                            <option>Financial Statement</option>
                        </select>
                    </flux:field>

                    <div
                        class="rounded-2xl border border-dashed p-5 text-center transition"
                        :class="dragActive ? 'border-cu-purple bg-cu-purple/10' : 'border-cu-border bg-black/5 dark:bg-white/5 hover:border-cu-blue'"
                        @dragover.prevent="dragActive = true"
                        @dragleave.prevent="dragActive = false"
                        @drop.prevent="dragActive = false; readFiles($event.dataTransfer.files)"
                    >
                        <input x-ref="upload" type="file" accept=".pdf,.png,.jpg,.jpeg" class="hidden" @change="readFiles($event.target.files)">

                        <button type="button" @click="$refs.upload.click()" class="mx-auto flex size-11 items-center justify-center rounded-xl bg-cu-blue/10 text-sky-700 ring-1 ring-cu-blue/20 transition hover:bg-cu-blue/20">
                            <flux:icon icon="arrow-up-tray" class="size-5" />
                        </button>
                        <h3 class="mt-3 text-base font-semibold text-cu-text">Drag a file here</h3>
                        <p class="mt-1 text-sm text-cu-muted">One PDF, PNG, JPG, or JPEG file.</p>
                        <button type="button" @click="$refs.upload.click()" class="mt-4 inline-flex items-center gap-2 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-semibold text-cu-text transition hover:bg-black/5 dark:hover:bg-white/5">
                            <flux:icon icon="paper-clip" class="size-4" />
                            Choose file
                        </button>
                    </div>

                    <template x-if="file !== null">
                        <div class="overflow-hidden rounded-2xl border border-cu-border">
                            <div class="flex items-center justify-between gap-3 border-b border-cu-border bg-black/5 dark:bg-white/5 px-4 py-3">
                                <p class="text-sm font-semibold text-cu-text">Selected document</p>
                                <button type="button" @click="clearFiles()" class="text-xs font-medium text-cu-muted transition hover:text-cu-text">Clear</button>
                            </div>
                            <div class="divide-y divide-cu-border">
                                <div class="flex items-center gap-3 px-4 py-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-black/5 dark:bg-white/5" :class="file.valid ? 'text-sky-700' : 'text-rose-600'">
                                        <flux:icon icon="document-text" class="size-4" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-cu-text" x-text="file.name"></p>
                                        <p class="text-xs text-cu-muted">
                                            <span x-text="documentType"></span>
                                            <span> - </span>
                                            <span x-text="`${file.extension.toUpperCase()} - ${file.size}`"></span>
                                        </p>
                                    </div>
                                    <span class="rounded-full px-2 py-1 text-xs font-medium" :class="file.valid ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/15 text-rose-700 dark:text-rose-300'" x-text="file.valid ? 'Ready' : 'Check file'"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="errors.length > 0">
                        <div class="rounded-2xl border border-rose-500/40 bg-rose-500/15 p-4">
                            <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">Please fix these files</p>
                            <ul class="mt-2 flex flex-col gap-1 text-sm text-rose-700 dark:text-rose-300">
                                <template x-for="error in errors" :key="error">
                                    <li x-text="error"></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <button type="button" @click="clearFiles()" class="inline-flex items-center justify-center rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-semibold text-cu-text transition hover:bg-black/5 dark:hover:bg-white/5">
                            Clear
                        </button>
                        <button
                            type="button"
                            wire:click="submitDocuments"
                            :disabled="!canSubmit"
                            :class="canSubmit ? 'cu-gradient text-white hover:opacity-90' : 'cursor-not-allowed bg-black/10 dark:bg-white/10 text-cu-muted'"
                            class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition"
                        >
                            <flux:icon icon="paper-airplane" class="size-4" />
                            Submit Documents
                        </button>
                    </div>
                </div>
            </div>

            <aside class="cu-animate-in flex flex-col gap-4" style="animation-delay: 140ms">
                <div class="rounded-2xl border border-cu-border bg-cu-surface p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-cu-text">Validation rules</h2>
                    <div class="mt-4 flex flex-col gap-3 text-sm">
                        <div class="flex items-start gap-3">
                            <x-activity-icon icon="document-text" color="sky" />
                            <p class="text-cu-muted">Upload PDF, PNG, JPG, or JPEG files only.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <x-activity-icon icon="archive-box" color="amber" />
                            <p class="text-cu-muted">Each file must be 10 MB or smaller.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <x-activity-icon icon="photo" color="emerald" />
                            <p class="text-cu-muted">Use a clear, readable scan of the current document.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-cu-border bg-cu-surface p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-cu-text">Submission flow</h2>
                    <div class="mt-5 flex flex-col gap-3">
                        @foreach (['Submit Document', 'Processing', 'Pending Review', 'Final Decision'] as $step)
                            <div class="flex items-center gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-cu-purple/10 text-xs font-semibold text-cu-purple ring-1 ring-cu-purple/20">{{ $loop->iteration }}</span>
                                <span class="text-sm font-medium text-cu-text">{{ $step }}</span>
                            </div>
                            @unless ($loop->last)
                                <span class="ml-3 h-5 w-px bg-cu-border"></span>
                            @endunless
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-page>
