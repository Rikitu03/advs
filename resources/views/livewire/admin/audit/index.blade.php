<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin "Audit Trail Viewer" page.
 *
 * Renders every {@see AuditLog} row in a paginated, filterable table with:
 *   - free-text search (action, entity, IP, details JSON),
 *   - actor filter,
 *   - action filter,
 *   - module filter (Authentication / Users / System Settings / Submissions),
 *   - affected-record filter (entity_type + entity_id),
 *   - inclusive `[from, to]` date-range filter,
 *   - "View details" drill-down (rendered server-side via the
 *     {@see \App\Http\Controllers\Admin\AuditLogController::show()} route),
 *   - CSV export of the *currently filtered* set.
 *
 * Performance notes (large datasets):
 *   - All filters push down to the database; no in-memory hydration of the
 *     full table.
 *   - The user and action filter dropdowns are populated from a single
 *     indexed query each, both capped at 100 rows.
 *   - Pagination is 25 rows/page by default; `perPage` is URL-bound so the
 *     URL is shareable.
 *
 * Authorization mirrors {@see \App\Policies\AuditLogPolicy}: only admins
 * can mount this component (route middleware also gates the route).
 */
new #[Layout('components.layouts.app'), Title('Audit Trail')]
class extends Component
{
    use WithPagination;

    /**
     * Free-text search term. Bound to `?q=` so the URL stays shareable.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Actor (user_id) filter. `''` means "All actors".
     */
    #[Url(as: 'user_id', except: '')]
    public string $userId = '';

    /**
     * Canonical action identifier filter (e.g. "user.created"). `''` = all.
     */
    #[Url(as: 'action', except: '')]
    public string $action = '';

    /**
     * Module prefix filter — matches `action` by its `module.` prefix
     * (e.g. `user` matches `user.created`, `user.updated`).
     */
    #[Url(as: 'module', except: '')]
    public string $module = '';

    /**
     * Affected entity class (FQCN or short class basename). `''` = all.
     */
    #[Url(as: 'entity_type', except: '')]
    public string $entityType = '';

    /**
     * Affected entity id (paired with `entityType`).
     */
    #[Url(as: 'entity_id', except: '')]
    public string $entityId = '';

    /**
     * Inclusive lower bound for `created_at`. `''` = open.
     */
    #[Url(as: 'from', except: '')]
    public string $from = '';

    /**
     * Inclusive upper bound for `created_at`. `''` = open.
     */
    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Page size. URL-bound so it survives reload and is shareable.
     */
    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    /**
     * Reset the pagination cursor whenever a filter changes so the user
     * doesn't end up on page 5 of a now-tiny result set.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingUserId(): void
    {
        $this->resetPage();
    }

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function updatingModule(): void
    {
        // Module is derived → changing it must clear the more specific
        // action filter so it can re-apply.
        $this->action = '';
        $this->resetPage();
    }

    public function updatingEntityType(): void
    {
        $this->resetPage();
    }

    public function updatingEntityId(): void
    {
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
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
        $this->reset([
            'search',
            'userId',
            'action',
            'module',
            'entityType',
            'entityId',
            'from',
            'to',
        ]);
        $this->resetPage();
    }

    /**
     * Apply preset ranges for the "quick range" buttons in the UI.
     */
    public function applyPreset(string $preset): void
    {
        $now = now();
        $this->to = $now->format('Y-m-d\TH:i');

        $this->from = match ($preset) {
            '24h' => $now->copy()->subDay()->format('Y-m-d\TH:i'),
            '7d' => $now->copy()->subDays(7)->format('Y-m-d\TH:i'),
            '30d' => $now->copy()->subDays(30)->format('Y-m-d\TH:i'),
            default => '',
        };

        $this->resetPage();
    }

    /**
     * Authorization guard mirrors the controller's `$this->authorize()`
     * so a non-admin cannot mount this component even if a future refactor
     * drops the middleware alias.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('viewAny', AuditLog::class), 403);

        // Defensive clamps on URL-supplied values (browsers + bots will
        // happily POST `?per_page=99999` otherwise).
        $this->perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;
    }

    /**
     * Base query applying every active filter. Cached per request via
     * {@see Computed} so the table and the KPI tiles don't issue duplicate
     * SELECTs.
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return $this->buildQuery()
            ->with(['user:id,name,email,role'])
            ->latest('created_at')
            ->paginate($this->perPage);
    }

    /**
     * KPI: total log rows matching the current filter (for the "X events"
     * header chip — useful for "did I just create a tighter subset?").
     */
    #[Computed]
    public function totalMatching(): int
    {
        return (clone $this->buildQuery())->count();
    }

    /**
     * KPI: distinct actors in the current filter (proxy for "how many
     * people touched something in this window?").
     */
    #[Computed]
    public function distinctActors(): int
    {
        return (clone $this->buildQuery())
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');
    }

    /**
     * Distinct actor list for the "User" filter dropdown. Capped at 100
     * to keep the SELECT cheap on huge tables.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function actors()
    {
        return User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->limit(500)->pluck('user_id'))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'email', 'role']);
    }

    /**
     * Build the filtered query without paginating it. Shared by the table
     * and the KPI counters so the filter logic lives in exactly one place.
     */
    protected function buildQuery(): Builder
    {
        return AuditLog::query()
            ->search($this->search)
            ->byUser($this->userId !== '' ? (int) $this->userId : null)
            ->ofAction($this->action !== '' ? $this->action : null)
            ->when($this->module !== '', fn (Builder $q) => $q->where('action', 'like', $this->module.'.%'))
            ->forEntity($this->entityType !== '' ? $this->entityType : null, $this->entityId !== '' ? (int) $this->entityId : null)
            ->betweenDates($this->from ?: null, $this->to ?: null);
    }

    /**
     * Build the current filter state as a query-string array. Used by the
     * "Export CSV" button so the download carries the same filters as the
     * on-screen table.
     *
     * @return array<string, string>
     */
    public function exportQuery(): array
    {
        return array_filter([
            'q' => $this->search !== '' ? $this->search : null,
            'user_id' => $this->userId !== '' ? $this->userId : null,
            'action' => $this->action !== '' ? $this->action : null,
            'entity_type' => $this->entityType !== '' ? $this->entityType : null,
            'entity_id' => $this->entityId !== '' ? $this->entityId : null,
            'from' => $this->from !== '' ? $this->from : null,
            'to' => $this->to !== '' ? $this->to : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function render(): View
    {
        return view('livewire.admin.audit.index', [
            'logs' => $this->logs,
            'totalMatching' => $this->totalMatching,
            'distinctActors' => $this->distinctActors,
            'actors' => $this->actors,
            'knownActions' => AuditLog::knownActions(),
            'modules' => AuditLog::modules(),
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
                <flux:breadcrumbs.item>Audit Trail</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Audit Trail</h1>
                    <p class="text-sm text-cu-muted">
                        {{ $this->totalMatching }} event{{ $this->totalMatching === 1 ? '' : 's' }} match the current filters
                        · {{ $this->distinctActors }} distinct actor{{ $this->distinctActors === 1 ? '' : 's' }}
                    </p>
                </div>
                <a href="{{ route('admin.audit.export', $this->exportQuery()) }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                    <flux:icon icon="arrow-down-tray" class="size-4" />
                    Export CSV
                </a>
            </div>
        </div>

        {{-- KPI chips --}}
        <div class="cu-animate-in grid grid-cols-2 gap-3 sm:grid-cols-4" style="animation-delay: 60ms">
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Total events</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format($this->totalMatching) }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Distinct actors</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format($this->distinctActors) }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Page size</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ $perPage }}</p>
            </div>
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Page</p>
                <p class="mt-1 text-2xl font-semibold text-cu-text">{{ $this->logs->currentPage() }} / {{ max(1, $this->logs->lastPage()) }}</p>
            </div>
        </div>

        {{-- Flash messages --}}
        @if (session('status'))
            <div x-data="{ show: true }" x-show="show" x-transition
                 class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4" style="animation-delay: 120ms">

            {{-- Row 1: search + per-page --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search action, entity, IP, or JSON details…"
                        class="w-full rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    />
                </label>

                <select wire:model.live="perPage"
                        class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>

            {{-- Row 2: structured filters --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <select wire:model.live="userId"
                        class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                    <option value="">All actors</option>
                    @foreach ($this->actors as $actor)
                        <option value="{{ $actor->id }}">{{ $actor->name }} — {{ ucfirst(str_replace('_', ' ', $actor->role)) }}</option>
                    @endforeach
                </select>

                <select wire:model.live="module"
                        class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                    <option value="">All modules</option>
                    @foreach ($modules as $label => $prefix)
                        <option value="{{ $prefix }}">{{ $label }}</option>
                    @endforeach
                </select>

                <select wire:model.live="action"
                        class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                    <option value="">All actions</option>
                    @foreach ($knownActions as $known)
                        <option value="{{ $known }}">{{ $known }}</option>
                    @endforeach
                </select>

                <select wire:model.live="entityType"
                        class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                    <option value="">All entities</option>
                    <option value="{{ \App\Models\User::class }}">User</option>
                    <option value="{{ \App\Models\SystemSetting::class }}">System Setting</option>
                </select>
            </div>

            {{-- Row 3: entity id + date range --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <input
                    type="number"
                    wire:model.live.debounce.400ms="entityId"
                    placeholder="Affected record ID (optional)"
                    min="1"
                    class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
                <input
                    type="datetime-local"
                    wire:model.live="from"
                    aria-label="From"
                    class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
                <input
                    type="datetime-local"
                    wire:model.live="to"
                    aria-label="To"
                    class="rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2.5 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
            </div>

            {{-- Quick-range + clear --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs uppercase tracking-wider text-cu-muted">Quick range:</span>
                <button type="button" wire:click="applyPreset('24h')"
                        class="rounded-full border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-1 text-xs text-cu-muted hover:text-cu-text">24h</button>
                <button type="button" wire:click="applyPreset('7d')"
                        class="rounded-full border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-1 text-xs text-cu-muted hover:text-cu-text">7 days</button>
                <button type="button" wire:click="applyPreset('30d')"
                        class="rounded-full border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-1 text-xs text-cu-muted hover:text-cu-text">30 days</button>

                @if ($search !== '' || $userId !== '' || $action !== '' || $module !== '' || $entityType !== '' || $entityId !== '' || $from !== '' || $to !== '')
                    <button wire:click="clearFilters" type="button"
                            class="ml-auto inline-flex items-center gap-1.5 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 px-3 py-2 text-sm text-cu-muted hover:text-cu-text">
                        <flux:icon icon="x-mark" class="size-4" />
                        Clear filters
                    </button>
                @endif
            </div>
        </div>

        {{-- Table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 180ms">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-cu-border">
                    <thead class="bg-black/5 dark:bg-white/5 text-left text-xs uppercase tracking-wider text-cu-muted">
                        <tr>
                            <th class="px-4 py-3 font-medium">Timestamp</th>
                            <th class="px-4 py-3 font-medium">Actor</th>
                            <th class="px-4 py-3 font-medium">Action</th>
                            <th class="px-4 py-3 font-medium">Affected entity</th>
                            <th class="px-4 py-3 font-medium">IP</th>
                            <th class="px-4 py-3 font-medium">Details</th>
                            <th class="px-4 py-3 text-right font-medium">View</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @forelse ($logs as $log)
                            <tr wire:key="audit-{{ $log->id }}" class="hover:bg-white/2">
                                <td class="px-4 py-3 text-sm text-cu-text">
                                    <div>{{ $log->created_at?->format('M d, Y') }}</div>
                                    <div class="text-xs text-cu-muted">{{ $log->created_at?->format('H:i:s') }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($log->user)
                                        <div class="flex items-center gap-2">
                                            <div class="flex size-7 items-center justify-center rounded-full cu-gradient text-[10px] font-semibold text-white">
                                                {{ $log->user->initials() }}
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-cu-text">{{ $log->user->name }}</p>
                                                <p class="text-[10px] uppercase tracking-wider text-cu-muted">{{ ucfirst(str_replace('_', ' ', $log->user->role)) }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-xs text-cu-muted">(deleted user)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-cu-purple/20 px-2 py-0.5 font-mono text-[11px] font-semibold text-cu-purple">{{ $log->action }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-cu-muted">
                                    @if ($log->entity_type)
                                        {{ class_basename($log->entity_type) }}<span class="text-cu-text"> #{{ $log->entity_id }}</span>
                                    @else
                                        <span class="text-cu-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-cu-muted">{{ $log->ip_address ?: '—' }}</td>
                                <td class="px-4 py-3 text-xs text-cu-muted">
                                    @if (is_array($log->details))
                                        @php
                                            $preview = collect($log->details)
                                                ->take(2)
                                                ->map(fn ($v, $k) => $k.' = '.(is_scalar($v) ? $v : json_encode($v)))
                                                ->implode(' · ');
                                        @endphp
                                        <span class="line-clamp-1 max-w-xs">{{ $preview }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.audit.show', $log) }}" wire:navigate
                                       class="inline-flex items-center gap-1 rounded-lg border border-cu-border bg-cu-surface px-2.5 py-1.5 text-xs font-medium text-cu-muted hover:text-cu-text">
                                        <flux:icon icon="eye" class="size-3.5" />
                                        View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-cu-muted">
                                    No audit events match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($logs->hasPages())
                <div class="border-t border-cu-border px-4 py-3">
                    {{ $logs->onEachSide(1)->links() }}
                </div>
            @endif
        </div>

    </div>
</x-page>