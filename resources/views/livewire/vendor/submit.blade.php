<?php

use App\Models\Document;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    public function submitBatch(array $files = []): mixed
    {
        $user = Auth::user();
        $vendor = $user?->vendor;

        if ($vendor === null) {
            abort(403);
        }

        $submission = Submission::create([
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_PROCESSING,
        ]);

        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            $type = (string) ($file['type'] ?? '');
            $extension = (string) ($file['extension'] ?? '');
            $sizeBytes = (int) ($file['sizeBytes'] ?? 0);

            if ($name === '' || $type === '') {
                continue;
            }

            Document::create([
                'submission_id' => $submission->id,
                'vendor_id' => $vendor->id,
                'document_type_id' => $this->documentTypeIdFor($type),
                'original_filename' => $name,
                'file_path' => 'pending/'.$name,
                'mime_type' => match ($extension) {
                    'pdf' => 'application/pdf',
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    default => 'application/octet-stream',
                },
                'file_size_bytes' => $sizeBytes,
                'processing_status' => Document::STATUS_QUEUED,
            ]);
        }

        return redirect()->route('vendor.submissions')->with('status', 'Submission queued successfully.');
    }

    private function documentTypeIdFor(string $type): ?int
    {
        $aliases = match ($type) {
            'BIR Permit' => ['bir_certificate', 'bir_permit', 'BIR Certificate of Registration', 'BIR Permit'],
            'Business Permit' => ['business_permit', 'Business Permit'],
            'Financial Statement' => ['financial_statement', 'Financial Statement'],
            default => [$type],
        };

        $id = DB::table('document_types')
            ->whereIn('code', $aliases)
            ->orWhereIn('name', $aliases)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}; ?>

<x-page>
    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6"
        x-init="
            $watch('files', () => {
                const hasPending = files.some((file) => file.status !== 'completed' && file.status !== 'invalid' && file.status !== 'failed');
                if (! hasPending) {
                    submitted = false;
                }
            });
            Livewire.on('submit-batch', (payload) => {
                $wire.submitBatch(payload.files || []);
            });
        "
        x-data="{
            files: [],
            timers: {},
            allowed: ['pdf', 'png', 'jpg', 'jpeg'],
            maxBytes: 10 * 1024 * 1024,
            dragActive: false,
            submitted: false,
            readFiles(fileList) {
                const selectedFiles = Array.from(fileList);

                if (selectedFiles.length === 0) {
                    return;
                }

                selectedFiles.forEach((selected) => {
                    const file = this.createFileLane(selected);

                    this.files.push(file);
                    this.prepareFile(file);
                });

                this.$refs.upload.value = '';
                this.submitted = false;
            },
            createFileLane(selected) {
                const extension = selected.name.split('.').pop().toLowerCase();
                const validType = this.allowed.includes(extension);
                const validSize = selected.size <= this.maxBytes;
                return {
                    id: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
                    name: selected.name,
                    size: this.formatBytes(selected.size),
                    sizeBytes: selected.size,
                    extension,
                    valid: validType && validSize,
                    error: ! validType
                        ? 'File must be a PDF, PNG, JPG, or JPEG.'
                        : (! validSize ? 'File is larger than the 10 MB per-file limit.' : ''),
                    type: '',
                    status: validType && validSize ? 'waiting' : 'invalid',
                    progress: 0,
                };
            },
            formatBytes(bytes) {
                if (bytes < 1024 * 1024) {
                    return `${(bytes / 1024).toFixed(1)} KB`;
                }

                return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
            },
            statusLabel(file) {
                if (file.status === 'invalid') {
                    return 'Check File';
                }

                if (file.status === 'uploading') {
                    return 'Uploading';
                }

                if (file.status === 'completed') {
                    return 'Ready';
                }

                if (file.status === 'failed') {
                    return 'Upload Failed';
                }

                if (file.type === '') {
                    return 'Pending Type Selection';
                }

                return 'Waiting';
            },
            statusClasses(file) {
                if (file.status === 'completed') {
                    return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
                }

                if (file.status === 'failed' || file.status === 'invalid') {
                    return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
                }

                if (file.status === 'uploading') {
                    return 'bg-cu-blue/10 text-sky-700 dark:text-sky-300';
                }

                if (file.type === '') {
                    return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
                }

                return 'bg-black/10 text-cu-muted dark:bg-white/10';
            },
            handleTypeChange(file) {
                window.clearInterval(this.timers[file.id]);
                file.status = file.valid ? 'waiting' : 'invalid';
                file.progress = 0;
                this.submitted = false;
                this.prepareFile(file);
            },
            canUpload(file) {
                return file.valid && file.type !== '' && ['waiting', 'failed'].includes(file.status);
            },
            prepareFile(file) {
                if (! this.canUpload(file)) {
                    return;
                }

                window.clearInterval(this.timers[file.id]);

                file.status = 'uploading';
                file.progress = 0;
                file.error = '';

                this.timers[file.id] = window.setInterval(() => {
                    const nextProgress = Math.min(100, file.progress + Math.floor(Math.random() * 18) + 12);
                    file.progress = nextProgress;

                    if (nextProgress < 100) {
                        return;
                    }

                    window.clearInterval(this.timers[file.id]);
                    file.status = 'completed';
                    file.progress = 100;
                }, 180);
            },
            uploadReadyFiles() {
                if (! this.canSubmit) {
                    return;
                }

                this.submitted = true;

                const payload = this.files.map((file) => ({
                    name: file.name,
                    type: file.type,
                    extension: file.extension,
                    sizeBytes: file.sizeBytes ?? 0,
                }));

                Livewire.dispatch('submit-batch', { files: payload });
            },
            uploadFile(file) {
                if (! this.canUpload(file)) {
                    return;
                }

                window.clearInterval(this.timers[file.id]);

                file.status = 'uploading';
                file.progress = 0;
                file.error = '';

                this.timers[file.id] = window.setInterval(() => {
                    const nextProgress = Math.min(100, file.progress + Math.floor(Math.random() * 14) + 7);
                    file.progress = nextProgress;

                    if (nextProgress < 100) {
                        return;
                    }

                    window.clearInterval(this.timers[file.id]);

                    const shouldFail = file.name.toLowerCase().includes('fail') || Math.random() < 0.08;

                    if (shouldFail) {
                        file.status = 'failed';
                        file.progress = 0;
                        file.error = 'Preparation failed. Remove or replace this file.';
                        return;
                    }

                    file.status = 'completed';
                    file.progress = 100;
                }, 260);
            },
            removeFile(file) {
                window.clearInterval(this.timers[file.id]);
                this.files = this.files.filter((lane) => lane.id !== file.id);
                this.submitted = false;
            },
            clearFiles() {
                this.files.forEach((file) => window.clearInterval(this.timers[file.id]));
                this.files = [];
                this.timers = {};
                this.$refs.upload.value = '';
                this.submitted = false;
            },
            get hasFiles() {
                return this.files.length > 0;
            },
            get readyCount() {
                return this.files.filter((file) => this.canUpload(file)).length;
            },
            get completedCount() {
                return this.files.filter((file) => file.status === 'completed').length;
            },
            get allUploaded() {
                return this.submitted && this.hasFiles && this.completedCount === this.files.length;
            },
            get canSubmit() {
                return this.hasFiles && this.files.every((file) => file.valid && file.type !== '' && file.status === 'completed');
            },
        }"
    >
        <div class="cu-animate-in">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Submit Documents</h1>
                <p class="mt-1 text-sm text-cu-muted">Drop mixed accreditation documents and assign a type to each file before upload.</p>
            </div>
        </div>

        <div x-show="allUploaded" x-cloak x-transition class="cu-animate-in rounded-2xl border border-emerald-500/40 bg-emerald-500/15 p-5 text-emerald-700 shadow-sm dark:text-emerald-300">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                        <flux:icon icon="check-circle" class="size-5" />
                    </span>
                    <div>
                        <p class="font-semibold">Submission queued</p>
                        <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                            Each document lane is ready and the batch is queued for processing.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
            <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface shadow-sm">
                <div class="border-b border-cu-border px-5 py-4">
                    <h2 class="text-base font-semibold text-cu-text">Document submission</h2>
                    <p class="text-xs text-cu-muted">Each file has its own document type and upload status.</p>
                </div>

                <div class="flex flex-col gap-5 p-5">
                    <input x-ref="upload" type="file" multiple accept=".pdf,.png,.jpg,.jpeg" class="hidden" @change="readFiles($event.target.files)">

                    <div
                        x-show="! hasFiles"
                        class="rounded-2xl border border-dashed p-8 text-center transition"
                        :class="dragActive ? 'border-cu-purple bg-cu-purple/10' : 'border-cu-border bg-black/5 dark:bg-white/5 hover:border-cu-blue'"
                        @dragover.prevent="dragActive = true"
                        @dragleave.prevent="dragActive = false"
                        @drop.prevent="dragActive = false; readFiles($event.dataTransfer.files)"
                    >
                        <button type="button" @click="$refs.upload.click()" class="mx-auto flex size-12 items-center justify-center rounded-xl bg-cu-blue/10 text-sky-700 ring-1 ring-cu-blue/20 transition hover:bg-cu-blue/20">
                            <flux:icon icon="arrow-up-tray" class="size-5" />
                        </button>
                        <h3 class="mt-3 text-base font-semibold text-cu-text">Drop files here or click to upload</h3>
                        <p class="mt-1 text-sm text-cu-muted">PDF, PNG, JPG, or JPEG files up to 10 MB each.</p>
                        <button type="button" @click="$refs.upload.click()" class="mt-4 inline-flex items-center gap-2 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-semibold text-cu-text transition hover:bg-black/5 dark:hover:bg-white/5">
                            <flux:icon icon="paper-clip" class="size-4" />
                            Choose files
                        </button>
                    </div>

                    <div x-show="hasFiles" x-cloak x-transition class="flex flex-col gap-4">
                        <div
                            class="rounded-2xl border border-dashed p-4 transition"
                            :class="dragActive ? 'border-cu-purple bg-cu-purple/10' : 'border-cu-border bg-black/5 dark:bg-white/5 hover:border-cu-blue'"
                            @dragover.prevent="dragActive = true"
                            @dragleave.prevent="dragActive = false"
                            @drop.prevent="dragActive = false; readFiles($event.dataTransfer.files)"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-blue/10 text-sky-700">
                                        <flux:icon icon="arrow-up-tray" class="size-5" />
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-cu-text">Add more files</p>
                                        <p class="text-xs text-cu-muted">Drop another batch here or choose from your device.</p>
                                    </div>
                                </div>
                                <button type="button" @click="$refs.upload.click()" class="inline-flex items-center justify-center gap-2 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-semibold text-cu-text transition hover:bg-black/5 dark:hover:bg-white/5">
                                    <flux:icon icon="paper-clip" class="size-4" />
                                    Choose files
                                </button>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-2xl border border-cu-border">
                            <div class="flex flex-col gap-3 border-b border-cu-border bg-black/5 px-4 py-3 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-cu-text">File queue</p>
                                    <p class="text-xs text-cu-muted">
                                        <span x-text="completedCount"></span>
                                        <span> of </span>
                                        <span x-text="files.length"></span>
                                        <span> ready</span>
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" @click="clearFiles()" class="inline-flex items-center justify-center gap-2 rounded-lg border border-cu-border bg-cu-surface px-3 py-2 text-xs font-semibold text-cu-text transition hover:bg-black/5 dark:hover:bg-white/5">
                                        Clear queue
                                    </button>
                                    <button
                                        type="button"
                                        @click="uploadReadyFiles()"
                                        :disabled="! canSubmit"
                                        :class="canSubmit ? 'cu-gradient text-white hover:opacity-90' : 'cursor-not-allowed bg-black/10 text-cu-muted dark:bg-white/10'"
                                        class="inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition"
                                    >
                                        <flux:icon icon="arrow-up-tray" class="size-3.5" />
                                        Submit
                                    </button>
                                </div>
                            </div>

                            <div class="divide-y divide-cu-border overflow-x-auto">
                                <template x-for="file in files" :key="file.id">
                                    <div class="flex min-w-[900px] items-center gap-4 px-4 py-3">
                                        <div class="flex min-w-0 flex-1 gap-3">
                                            <span
                                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-black/5 dark:bg-white/5"
                                                :class="file.status === 'completed' ? 'text-emerald-700 dark:text-emerald-300' : (file.status === 'failed' || file.status === 'invalid' ? 'text-rose-600 dark:text-rose-300' : 'text-sky-700 dark:text-sky-300')"
                                            >
                                                <template x-if="file.status === 'completed'">
                                                    <flux:icon icon="check-circle" class="size-5" />
                                                </template>
                                                <template x-if="file.status !== 'completed'">
                                                    <flux:icon icon="document-text" class="size-5" />
                                                </template>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                                    <p class="truncate text-sm font-medium text-cu-text" x-text="file.name"></p>
                                                </div>
                                                <p class="mt-1 text-xs text-cu-muted">
                                                    <span x-text="file.extension.toUpperCase()"></span>
                                                    <span> - </span>
                                                    <span x-text="file.size"></span>
                                                </p>
                                                <p x-show="file.error !== ''" class="mt-1 text-xs font-medium text-rose-700 dark:text-rose-300" x-text="file.error"></p>
                                            </div>
                                        </div>

                                        <select
                                            x-model="file.type"
                                            @change="handleTypeChange(file)"
                                            aria-label="Document type"
                                            class="h-10 w-56 shrink-0 rounded-lg border border-cu-border bg-cu-surface px-3 text-sm text-cu-text outline-none transition disabled:cursor-not-allowed disabled:opacity-60 focus:border-cu-purple focus:ring-2 focus:ring-cu-purple/20"
                                        >
                                            <option value="">Select type</option>
                                            <option>Business Permit</option>
                                            <option>BIR Permit</option>
                                            <option>Financial Statement</option>
                                        </select>

                                        <span class="w-28 shrink-0 rounded-full px-2.5 py-1 text-center text-xs font-medium" :class="statusClasses(file)" x-text="statusLabel(file)"></span>
                                        <span class="w-10 shrink-0 text-right text-xs font-medium text-cu-muted" x-text="`${file.progress}%`"></span>
                                        <progress class="h-2 w-44 shrink-0 overflow-hidden rounded-full accent-cu-purple [&::-moz-progress-bar]:rounded-full [&::-moz-progress-bar]:bg-cu-purple [&::-webkit-progress-bar]:rounded-full [&::-webkit-progress-bar]:bg-cu-border [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-cu-purple" max="100" :value="file.progress"></progress>
                                        <button
                                            type="button"
                                            @click="removeFile(file)"
                                            aria-label="Remove file"
                                            title="Remove file"
                                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-cu-border bg-cu-surface text-cu-muted transition hover:bg-rose-500/10 hover:text-rose-700 dark:hover:text-rose-300"
                                        >
                                            <flux:icon icon="x-mark" class="size-4" />
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <noscript>
                        <div class="rounded-2xl border border-rose-500/40 bg-rose-500/15 p-4 text-sm text-rose-700 dark:text-rose-300">
                            JavaScript is required to assign document types and track uploads per file.
                        </div>
                    </noscript>
                </div>
            </div>

            <aside class="cu-animate-in flex flex-col gap-4">
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
                        <div class="flex items-start gap-3">
                            <x-activity-icon icon="check-circle" color="sky" />
                            <p class="text-cu-muted">Assign Business Permit, BIR Permit, or Financial Statement per file.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-cu-border bg-cu-surface p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-cu-text">Submission flow</h2>
                    <div class="mt-5 flex flex-col gap-3">
                        @foreach (['Queue Files', 'Assign Document Type', 'Upload Files', 'Pending Review'] as $step)
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
