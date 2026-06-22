<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin user-management listing page.
 *
 * Live search (debounced), role filter, status filter, and pagination
 * over the users table. Renders the action buttons that hit the
 * /admin/users/{user}/edit, /admin/users/{user}/role, and
 * /admin/users/{user} (DELETE) routes.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'role', except: 'all')]
    public string $role = 'all';

    #[Url(as: 'status', except: 'all')]
    public string $status = 'all';

    public function mount(int $totalCount = 0, int $activeCount = 0, array $roleCounts = []): void
    {
        $this->totalCount = $totalCount;
        $this->activeCount = $activeCount;
        $this->roleCounts = $roleCounts;
    }

    public int $totalCount = 0;
    public int $activeCount = 0;

    /**
     * @var array<string, int>
     */
    public array $roleCounts = [];

    /**
     * Reset pagination when filters change so the user doesn't end up on
     * page 5 of a now-tiny result set.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'role', 'status']);
        $this->resetPage();
    }

    public function deleteUser(int $userId): void
    {
        /** @var User|null $user */
        $user = User::find($userId);

        if (! $user) {
            $this->dispatch('toast', type: 'error', message: 'User not found.');
            return;
        }

        if (! auth()->user()?->can('delete', $user)) {
            $this->dispatch('toast', type: 'error', message: 'You are not authorized to delete that user.');
            return;
        }

        if (User::query()->where('role', User::ROLE_ADMIN)->count() <= 1 && $user->hasRole(User::ROLE_ADMIN)) {
            $this->dispatch('toast', type: 'error', message: 'Cannot delete the last remaining admin.');
            return;
        }

        $email = $user->email;
        $user->delete();

        $this->dispatch('toast', type: 'success', message: "User {$email} deleted.");
    }

    public function toggleActive(int $userId): void
    {
        /** @var User|null $user */
        $user = User::find($userId);

        if (! $user) {
            return;
        }

        if (! auth()->user()?->can('update', $user)) {
            $this->dispatch('toast', type: 'error', message: 'You are not authorized to update that user.');
            return;
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        $this->dispatch('toast', type: 'success', message: "User {$user->email} ".($user->is_active ? 'activated' : 'deactivated').'.');
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return User::query()
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.$term.'%';
                $q->where(function (Builder $q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($this->role !== 'all', fn (Builder $q) => $q->where('role', $this->role))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(10);
    }

    public function render(): View
    {
        return view('livewire.admin.users.index', [
            'users' => $this->users,
        ]);
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>User Management</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-white">User Management</h1>
                    <p class="text-sm text-cu-muted">
                        {{ $this->totalCount }} total · {{ $this->activeCount }} active
                    </p>
                </div>
                <a href="{{ route('admin.users.create') }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                    <flux:icon icon="plus" class="size-4" />
                    Create User
                </a>
            </div>
        </div>

        {{-- KPI chips --}}
        <div class="cu-animate-in grid grid-cols-1 gap-3 sm:grid-cols-3" style="animation-delay: 60ms">
            <div class="rounded-2xl border border-white/5 bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Vendors</p>
                <p class="mt-1 text-2xl font-semibold text-white">{{ $roleCounts[User::ROLE_VENDOR] ?? 0 }}</p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Compliance Officers</p>
                <p class="mt-1 text-2xl font-semibold text-white">{{ $roleCounts[User::ROLE_COMPLIANCE_OFFICER] ?? 0 }}</p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-cu-surface p-4">
                <p class="text-xs uppercase tracking-wider text-cu-muted">Admins</p>
                <p class="mt-1 text-2xl font-semibold text-white">{{ $roleCounts[User::ROLE_ADMIN] ?? 0 }}</p>
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
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4 sm:flex-row sm:items-center" style="animation-delay: 120ms">
            <label class="relative flex-1">
                <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name or email…"
                    class="w-full rounded-xl border border-white/10 bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
            </label>

            <select wire:model.live="role"
                    class="rounded-xl border border-white/10 bg-cu-bg px-3 py-2.5 text-sm text-white focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                <option value="all">All roles</option>
                <option value="{{ User::ROLE_VENDOR }}">Vendor</option>
                <option value="{{ User::ROLE_COMPLIANCE_OFFICER }}">Compliance officer</option>
                <option value="{{ User::ROLE_ADMIN }}">Admin</option>
            </select>

            <select wire:model.live="status"
                    class="rounded-xl border border-white/10 bg-cu-bg px-3 py-2.5 text-sm text-white focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40">
                <option value="all">All status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>

            @if ($search !== '' || $role !== 'all' || $status !== 'all')
                <button wire:click="clearFilters" type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-cu-bg px-3 py-2.5 text-sm text-cu-muted hover:text-white">
                    <flux:icon icon="x-mark" class="size-4" />
                    Clear
                </button>
            @endif
        </div>

        {{-- Table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-white/5 bg-cu-surface" style="animation-delay: 180ms">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wider text-cu-muted">
                        <tr>
                            <th class="px-4 py-3 font-medium">User</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Joined</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($users as $user)
                            @php
                                $isSelf = $user->id === auth()->id();
                                $canEdit = auth()->user()?->can('update', $user) ?? false;
                                $canDelete = auth()->user()?->can('delete', $user) ?? false;
                            @endphp
                            <tr wire:key="user-{{ $user->id }}" class="hover:bg-white/2">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 items-center justify-center rounded-full cu-gradient text-sm font-semibold text-white">
                                            {{ $user->initials() }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-white">
                                                {{ $user->name }}
                                                @if ($isSelf)
                                                    <span class="ml-1 rounded-full bg-cu-purple/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-cu-purple">You</span>
                                                @endif
                                            </p>
                                            <p class="text-xs text-cu-muted">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->role === User::ROLE_ADMIN)
                                        <span class="inline-flex items-center rounded-full bg-cu-pink/20 px-2 py-0.5 text-xs font-semibold text-cu-pink">Admin</span>
                                    @elseif ($user->role === User::ROLE_COMPLIANCE_OFFICER)
                                        <span class="inline-flex items-center rounded-full bg-cu-purple/20 px-2 py-0.5 text-xs font-semibold text-cu-purple">Compliance</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-white/10 px-2 py-0.5 text-xs font-semibold text-cu-muted">Vendor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->is_active)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-semibold text-emerald-300">
                                            <span class="size-1.5 rounded-full bg-emerald-400"></span>
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/20 px-2 py-0.5 text-xs font-semibold text-zinc-300">
                                            <span class="size-1.5 rounded-full bg-zinc-400"></span>
                                            Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-cu-muted">
                                    {{ $user->created_at?->format('M d, Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($canEdit)
                                            <a href="{{ route('admin.users.edit', $user) }}" wire:navigate
                                               class="inline-flex items-center gap-1 rounded-lg border border-white/10 bg-cu-bg px-2.5 py-1.5 text-xs font-medium text-white hover:border-cu-purple/40">
                                                <flux:icon icon="pencil-square" class="size-3.5" />
                                                Edit
                                            </a>
                                            <button wire:click="toggleActive({{ $user->id }})" type="button"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-white/10 bg-cu-bg px-2.5 py-1.5 text-xs font-medium text-white hover:border-cu-purple/40">
                                                @if ($user->is_active)
                                                    Deactivate
                                                @else
                                                    Activate
                                                @endif
                                            </button>
                                        @endif
                                        @if ($canDelete)
                                            <button
                                                x-data
                                                        x-on:click.prevent="
                                                            if (confirm('Delete user {{ $user->email }}?\\n\\nThis action cannot be undone.')) {
                                                                $wire.deleteUser({{ $user->id }});
                                                            }
                                                        "
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-lg border border-rose-500/30 bg-rose-500/10 px-2.5 py-1.5 text-xs font-medium text-rose-300 hover:bg-rose-500/20">
                                                <flux:icon icon="trash" class="size-3.5" />
                                                Delete
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-sm text-cu-muted">
                                    No users match your filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-white/5 bg-white/2 px-4 py-3">
                    {{ $users->links() }}
                </div>
            @endif
        </div>
    </div>
</x-page>
