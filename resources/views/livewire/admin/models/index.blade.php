<?php

use App\Models\AuditLog;
use App\Models\MlModel;
use App\Services\MlModelScanner;
use App\Services\MlModelService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin "ML Model Management" page.
 *
 * Renders every {@see MlModel} row in a paginated, filterable table with:
 *   - free-text search (name, notes, version, metrics JSON),
 *   - purpose filter,
 *   - status filter,
 *   - per-model KPI tiles (active / missing / standby),
 *   - "Sync all" button that re-probes every file on disk,
 *   - per-row "Re-check", edit, and bulk status / version / notes edit,
 *   - "Last changed by" hint per row,
 *   - KPI chip for "models needing attention" (status = `missing`).
 *
 * Authorization is enforced three ways:
 *   1. `role:admin` middleware on the route,
 *   2. {@see \App\Policies\MlModelPolicy::viewAny()} / `update()` checks
 *      inside the component and the service,
 *   3. The `mount()` abort guard below, mirroring the
 *      {@see \App\Livewire\Admin\Settings\Index} and
 *      {@see \App\Livewire\Admin\Audit\Index} pattern.
 *
 * Every state-changing action records an {@see AuditLog} row through
 * {@see MlModelService}, so the audit trail reflects model changes.
 */
new #[Layout('components.layouts.app'), Title('ML Model Management')]
class extends Component
{
    use WithPagination;

    /**
     * Free-text search term. Bound to `?q=` so the URL stays shareable.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Pipeline-stage filter. `''` = all stages. See {@see MlModel::PURPOSES}.
     */
    #[Url(as: 'purpose', except: '')]
    public string $purpose = '';

    /**
     * Deployment-status filter. `''` = all statuses. See {@see MlModel::STATUSES}.
     */
    #[Url(as: 'status', except: '')]
    public string $status = '';

    /**
     * Page size. URL-bound so it survives reload and is shareable.
     */
    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    /**
     * Per-row edit buffer. Holds the in-progress form values for a single
     * model so editing multiple rows doesn't clobber each other.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $editing = [];

    /**
     * Whether the "include checksum" toggle was on for the last "Sync all"
     * run. Stored on the component purely for the toast message.
     */
    public bool $lastSyncHashed = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('viewAny', MlModel::class), 403);

        // Defensive clamp on URL-supplied values (bots will happily POST
        // `?per_page=99999` otherwise).
        $this->perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 25;
        $this->purpose = in_array($this->purpose, array_merge([''], MlModel::PURPOSES), true) ? $this->purpose : '';
        $this->status = in_array($this->status, array_merge([''], MlModel::STATUSES), true) ? $this->status : '';
    }

    /**
     * Reset pagination whenever a filter changes so the user doesn't end
     * up on page 5 of a now-empty result set.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPurpose(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Restore every filter to its default. Bound to the "Clear" button.
     */
    public function clearFilters(): void
    {
        $this->reset(['search', 'purpose', 'status']);
        $this->resetPage();
    }

    /**
     * Build the filtered, paginated query. Shared by the table and the
     * KPI counters so the filter logic lives in exactly one place.
     */
    #[Computed]
    public function models(): LengthAwarePaginator
    {
        return MlModel::query()
            ->with(['updater:id,name,email'])
            ->search($this->search)
            ->forPurpose($this->purpose !== '' ? $this->purpose : null)
            ->withStatus($this->status !== '' ? $this->status : null)
            ->orderBy('purpose')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    /**
     * KPI: total registered models (so the header chip reads consistently).
     */
    #[Computed]
    public function totalCount(): int
    {
        return MlModel::query()->count();
    }

    /**
     * KPI: rows currently marked `missing` (file disappeared from disk).
     * Drives the "needs attention" banner.
     */
    #[Computed]
    public function missingCount(): int
    {
        return MlModel::query()->where('status', 'missing')->count();
    }

    /**
     * KPI: rows marked `active` (driving the pipeline).
     */
    #[Computed]
    public function activeCount(): int
    {
        return MlModel::query()->where('status', 'active')->count();
    }

    /**
     * KPI: most-recent sync timestamp across all rows. Used for the
     * "last refreshed" hint in the header.
     */
    #[Computed]
    public function lastSyncedAt(): ?\Illuminate\Support\Carbon
    {
        $raw = MlModel::query()
            ->whereNotNull('last_synced_at')
            ->max('last_synced_at');

        if ($raw === null) {
            return null;
        }

        // SQLite returns the raw `datetime` string from MAX(), MySQL returns
        // a Carbon. Normalise so the view's `diffForHumans()` always works.
        return $raw instanceof \Illuminate\Support\Carbon
            ? $raw
            : \Illuminate\Support\Carbon::parse($raw);
    }

    /**
     * Probe-result cache for every model in the current page, keyed by id.
     * Lets the view render "file on disk / file missing" without re-hitting
     * `stat()` for every row on every render.
     *
     * @return array<int, array{exists:bool, resolved_path:?string, file_size_bytes:?int}>
     */
    #[Computed]
    public function probes(): array
    {
        $scanner = app(MlModelScanner::class);

        $out = [];
        foreach ($this->models->items() as $model) {
            /** @var MlModel $model */
            $probe = $scanner->probe($model, false);
            $out[$model->id] = [
                'exists' => $probe['exists'],
                'resolved_path' => $probe['resolved_path'],
                'file_size_bytes' => $probe['file_size_bytes'],
            ];
        }

        return $out;
    }

    /**
     * Sync every registered model against the filesystem. Bound to the
     * header "Sync all" button. Records an audit log per affected row
     * through {@see MlModelService::syncOne()}.
     */
    public function syncAll(MlModelService $service): void
    {
        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('viewAny', MlModel::class), 403);

        // The bulk method refreshes status / size / mtime in a single
        // transaction but doesn't audit each row (it would explode the
        // audit log on every sync). We write a single summary log instead.
        $summary = $service->syncAll($this->lastSyncHashed);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'ml_model.synced',
            'entity_type' => MlModel::class,
            'entity_id' => null,
            'details' => [
                'summary' => $summary,
                'with_hash' => $this->lastSyncHashed,
            ],
            'ip_address' => request()?->ip(),
        ]);

        session()->flash(
            'status',
            sprintf(
                'Synced %d model%s — %d found on disk, %d missing%s.',
                $summary['scanned'],
                $summary['scanned'] === 1 ? '' : 's',
                $summary['found'],
                $summary['missing'],
                $this->lastSyncHashed ? ' (with SHA-256 checksums)' : '',
            ),
        );
    }

    /**
     * Re-probe a single row. Bound to the per-row "Re-check" button.
     */
    public function syncOne(int $id, MlModelService $service): void
    {
        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('viewAny', MlModel::class), 403);

        $model = MlModel::query()->findOrFail($id);
        $result = $service->syncOne($model, $actor, $this->lastSyncHashed);

        session()->flash(
            'status',
            $result['exists']
                ? "Re-checked \"{$model->name}\" — file present on disk."
                : "Re-checked \"{$model->name}\" — file NOT found on disk. Status: {$result['status_after']}.",
        );
    }

    /**
     * Begin editing a row: seed the form buffer with the persisted values
     * so the "Save" submit can be a clean partial update.
     */
    public function startEditing(int $id): void
    {
        abort_unless(auth()->user()?->can('update', new MlModel), 403);

        $model = MlModel::query()->findOrFail($id);
        $this->editing[(string) $id] = [
            'name' => $model->name,
            'purpose' => $model->purpose,
            'version' => $model->version,
            'status' => $model->status,
            'notes' => $model->notes ?? '',
            'metrics_text' => $model->metrics === null
                ? ''
                : json_encode($model->metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Drop the in-progress edit buffer for one row. Bound to the
     * per-row "Cancel" button.
     */
    public function cancelEditing(int $id): void
    {
        unset($this->editing[(string) $id]);
    }

    /**
     * Persist the buffered edit. Validates inputs and writes an audit
     * row through {@see MlModelService::update()}.
     */
    public function saveEditing(int $id, MlModelService $service): void
    {
        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('update', new MlModel), 403);

        $key = (string) $id;
        if (! isset($this->editing[$key])) {
            return;
        }

        $row = $this->editing[$key];

        // Parse the metrics JSON the operator pasted into the textarea. An
        // empty string clears the metrics; a non-empty string must parse.
        $metrics = null;
        $metricsText = trim((string) ($row['metrics_text'] ?? ''));
        if ($metricsText !== '') {
            $decoded = json_decode($metricsText, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                $this->addError("editing.{$key}.metrics_text", 'Metrics must be a JSON object (e.g. {"top1_accuracy": 0.95}).');

                return;
            }
            $metrics = $decoded;
        }

        $changes = [
            'name' => $row['name'] ?? null,
            'purpose' => $row['purpose'] ?? null,
            'version' => $row['version'] ?? null,
            'status' => $row['status'] ?? null,
            'notes' => $row['notes'] ?? '',
            'metrics' => $metrics,
        ];

        $model = MlModel::query()->findOrFail($id);

        try {
            $service->update($model, $changes, $actor);
        } catch (\InvalidArgumentException $e) {
            $this->addError("editing.{$key}", $e->getMessage());

            return;
        }

        unset($this->editing[$key]);

        session()->flash('status', "Updated \"{$model->name}\".");
    }

    /**
     * Render the view.
     */
    public function render(): View
    {
        return view('livewire.admin.models.index', [
            'models' => $this->models,
            'probes' => $this->probes,
            'totalCount' => $this->totalCount,
            'missingCount' => $this->missingCount,
            'activeCount' => $this->activeCount,
            'lastSyncedAt' => $this->lastSyncedAt,
            'purposes' => MlModel::PURPOSES,
            'statuses' => MlModel::STATUSES,
        ]);
    }
}
?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>ML Model Management</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">ML Model Management</h1>
                    <p class="text-sm text-cu-muted">
                        {{ $this->totalCount }} registered model{{ $this->totalCount === 1 ? '' : 's' }}
                        @if ($this->missingCount > 0)
                            · <span class="font-medium text-rose-600 dark:text-rose-300">{{ $this->missingCount }} need attention</span>
                        @endif
                        @if ($this->lastSyncedAt)
                            · last refreshed {{ $this->lastSyncedAt->diffForHumans() }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-cu-muted">
                        <input type="checkbox" wire:model.live="lastSyncHashed"
                               class="size-4 rounded border-cu-border bg-black/5 text-cu-purple focus:ring-cu-purple/40 dark:bg-white/5" />
                        Include SHA-256 checksum
                    </label>
                    <button type="button" wire:click="syncAll" wire:loading.attr="disabled" wire:target="syncAll"
                            class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                        <flux:icon.loading wire:loading wire:target="syncAll" variant="micro" class="size-4" />
                        <flux:icon icon="arrow-path" wire:loading.remove wire:target="syncAll" class="size-4" />
                        Sync all from disk
                    </button>
                </div>
            </div>
        </div>

        {{-- KPI tiles --}}
        <div class="cu-animate-in grid grid-cols-2 gap-3 sm:grid-cols-4" style="animation-delay: 60ms">
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Total registered</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format($this->totalCount) }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Active</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-300">{{ number_format($this->activeCount) }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Missing on disk</p>
                <p class="mt-1 text-2xl font-semibold text-rose-600 dark:text-rose-300">{{ number_format($this->missingCount) }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Other (standby / deprecated)</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format(max(0, $this->totalCount - $this->activeCount - $this->missingCount)) }}</p>
            </div>
        </div>

        {{-- Needs-attention banner --}}
        @if ($this->missingCount > 0)
            <div class="cu-animate-in flex items-start gap-3 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4" style="animation-delay: 90ms">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl bg-rose-500/20 text-rose-700 dark:text-rose-300">
                    <flux:icon icon="exclamation-triangle" class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-rose-700 dark:text-rose-200">
                        {{ $this->missingCount }} model file{{ $this->missingCount === 1 ? '' : 's' }} missing on disk
                    </p>
                    <p class="text-xs text-rose-700/80 dark:text-rose-200/80">
                        The expected weight file is not at the configured <code class="font-mono">storage_path</code>. Restore the file or click
                        <em>Sync all</em> after fixing the path. The pipeline will continue to use whatever model is currently marked <code class="font-mono">active</code>.
                    </p>
                </div>
            </div>
        @endif

        {{-- Flash --}}
        @if (session('status'))
            <div x-data="{ show: true }" x-show="show" x-transition
                 class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4" style="animation-delay: 120ms">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by name, version, path, or metrics…"
                        class="w-full rounded-xl border border-cu-border bg-black/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"
                    />
                </label>
                <select wire:model.live="perPage"
                        class="rounded-xl border border-cu-border bg-black/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5">
                    <option value="10">10 / page</option>
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <select wire:model.live="purpose"
                        class="rounded-xl border border-cu-border bg-black/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5">
                    <option value="">All pipeline stages</option>
                    @foreach ($purposes as $p)
                        <option value="{{ $p }}">{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status"
                        class="rounded-xl border border-cu-border bg-black/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($search !== '' || $purpose !== '' || $status !== '')
                <div class="flex justify-end">
                    <button wire:click="clearFilters" type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-muted hover:text-cu-text dark:bg-white/5">
                        <flux:icon icon="x-mark" class="size-4" /> Clear filters
                    </button>
                </div>
            @endif
        </div>

        {{-- Table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 180ms">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-cu-border text-sm">
                    <thead class="bg-black/5 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Model</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Purpose</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Version</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Status</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Storage path</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">File</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Last trained</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-cu-muted">Metrics</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium text-cu-muted">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @forelse ($this->models as $model)
                            @php($probe = $this->probes[$model->id] ?? null)
                            @php($isEditing = isset($this->editing[$model->id]))
                            <tr wire:key="ml-model-{{ $model->id }}" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-cu-text">{{ $model->name }}</div>
                                    @if ($model->updater)
                                        <p class="text-[11px] text-cu-muted">Last changed by {{ $model->updater->name }} · {{ $model->updated_at?->diffForHumans() }}</p>
                                    @else
                                        <p class="text-[11px] text-cu-muted">Never edited since registration</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <flux:badge size="sm" color="zinc">{{ ucfirst(str_replace('_', ' ', $model->purpose)) }}</flux:badge>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-cu-muted">{{ $model->version }}</td>
                                <td class="px-4 py-3">
                                    <flux:badge size="sm" :color="$model->statusColor()">{{ $model->statusLabel() }}</flux:badge>
                                </td>
                                <td class="px-4 py-3">
                                    <code class="block max-w-[280px] truncate font-mono text-xs text-cu-blue" title="{{ $model->storage_path }}">
                                        {{ $model->storage_path }}
                                    </code>
                                    @if ($probe && $probe['resolved_path'])
                                        <p class="mt-0.5 truncate text-[10px] text-cu-muted" title="{{ $probe['resolved_path'] }}">→ {{ $probe['resolved_path'] }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if (! $probe)
                                        <span class="text-xs text-cu-muted">—</span>
                                    @elseif ($probe['exists'])
                                        <div class="flex flex-col gap-0.5">
                                            <span class="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-300">
                                                <flux:icon icon="check-circle" class="size-3.5" /> On disk
                                            </span>
                                            @if ($probe['file_size_bytes'] !== null)
                                                <span class="text-[11px] text-cu-muted">{{ number_format($probe['file_size_bytes'] / 1024 / 1024, 2) }} MB</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-rose-700 dark:text-rose-300">
                                            <flux:icon icon="x-circle" class="size-3.5" /> Missing
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-cu-muted">
                                    {{ $model->last_trained_at?->format('M j, Y') ?? '—' }}
                                    @if ($model->last_trained_at)
                                        <p class="text-[11px] text-cu-muted">{{ $model->last_trained_at->diffForHumans() }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if (is_array($model->metrics) && $model->metrics !== [])
                                        <ul class="flex flex-col gap-0.5 text-[11px] text-cu-muted">
                                            @foreach (array_slice($model->metrics, 0, 3, true) as $k => $v)
                                                <li><span class="text-cu-text">{{ $k }}</span>: {{ is_array($v) ? json_encode($v) : $v }}</li>
                                            @endforeach
                                            @if (count($model->metrics) > 3)
                                                <li class="text-cu-muted/70">+{{ count($model->metrics) - 3 }} more…</li>
                                            @endif
                                        </ul>
                                    @else
                                        <span class="text-xs text-cu-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" wire:click="syncOne({{ $model->id }})"
                                                wire:loading.attr="disabled" wire:target="syncOne({{ $model->id }})"
                                                class="inline-flex items-center gap-1 rounded-md border border-cu-border px-2 py-1 text-xs font-medium text-cu-muted hover:text-cu-text">
                                            <flux:icon.loading wire:loading wire:target="syncOne({{ $model->id }})" variant="micro" class="size-3" />
                                            <flux:icon icon="arrow-path" wire:loading.remove wire:target="syncOne({{ $model->id }})" class="size-3" />
                                            Re-check
                                        </button>
                                        @unless ($isEditing)
                                            <button type="button" wire:click="startEditing({{ $model->id }})"
                                                    class="inline-flex items-center gap-1 rounded-md border border-cu-border px-2 py-1 text-xs font-medium text-cu-muted hover:text-cu-text">
                                                <flux:icon icon="pencil-square" class="size-3" /> Edit
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                            @if ($isEditing)
                                <tr wire:key="ml-model-edit-{{ $model->id }}" class="bg-black/[0.02] dark:bg-white/[0.02]">
                                    <td colspan="9" class="px-4 py-4">
                                        <div class="grid gap-4 lg:grid-cols-2">
                                            <div class="flex flex-col gap-3">
                                                <div>
                                                    <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Name</label>
                                                    <input type="text" wire:model="editing.{{ $model->id }}.name"
                                                           class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5" />
                                                </div>
                                                <div class="grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Purpose</label>
                                                        <select wire:model="editing.{{ $model->id }}.purpose"
                                                                class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5">
                                                            @foreach ($purposes as $p)
                                                                <option value="{{ $p }}">{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Version</label>
                                                        <input type="text" wire:model="editing.{{ $model->id }}.version"
                                                               class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5" />
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Status</label>
                                                    <select wire:model="editing.{{ $model->id }}.status"
                                                            class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5">
                                                        @foreach ($statuses as $s)
                                                            <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Notes</label>
                                                    <textarea wire:model="editing.{{ $model->id }}.notes" rows="3"
                                                              class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"></textarea>
                                                </div>
                                            </div>
                                            <div class="flex flex-col gap-3">
                                                <div>
                                                    <label class="text-xs font-medium uppercase tracking-wider text-cu-muted">Metrics (JSON object)</label>
                                                    <textarea wire:model="editing.{{ $model->id }}.metrics_text" rows="9"
                                                              placeholder='{"top1_accuracy": 0.95}'
                                                              class="mt-1 w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 font-mono text-xs text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"></textarea>
                                                    @error("editing.{$model->id}.metrics_text")
                                                        <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                                <div class="flex flex-wrap items-center justify-end gap-2">
                                                    <button type="button" wire:click="cancelEditing({{ $model->id }})"
                                                            class="inline-flex items-center gap-1 rounded-xl border border-cu-border bg-cu-surface px-3 py-2 text-sm text-cu-muted hover:text-cu-text">
                                                        Cancel
                                                    </button>
                                                    <button type="button" wire:click="saveEditing({{ $model->id }})"
                                                            wire:loading.attr="disabled" wire:target="saveEditing({{ $model->id }})"
                                                            class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                                                        <flux:icon.loading wire:loading wire:target="saveEditing({{ $model->id }})" variant="micro" class="size-4" />
                                                        <flux:icon icon="check" wire:loading.remove wire:target="saveEditing({{ $model->id }})" class="size-4" />
                                                        Save changes
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center">
                                    <div class="mx-auto flex max-w-md flex-col items-center gap-2 text-cu-muted">
                                        <flux:icon icon="cpu-chip" class="size-10 opacity-50" />
                                        <p class="text-sm font-medium text-cu-text">No ML models registered yet</p>
                                        <p class="text-xs">Run <code class="font-mono">php artisan db:seed --class=MlModelSeeder</code> to seed the four canonical ADVS model identities.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pagination --}}
        @if ($this->models->hasPages())
            <div class="cu-animate-in flex items-center justify-between text-xs text-cu-muted" style="animation-delay: 220ms">
                <span>Page {{ $this->models->currentPage() }} of {{ $this->models->lastPage() }} · {{ $this->models->total() }} total</span>
                <div>{{ $this->models->links() }}</div>
            </div>
        @endif

    </div>
</x-page>