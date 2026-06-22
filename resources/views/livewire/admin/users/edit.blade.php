<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin "Edit User" form. Renders the form and delegates the actual write
 * to the controller so validation lives in the FormRequest. Password is
 * optional on update.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public int $userId;

    public User $user;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $user = User::findOrFail($userId);

        // Mirror the controller-level guard so a non-admin (or the user
        // themselves) cannot mount this page.
        if (! auth()->user()?->can('update', $user)) {
            abort(403);
        }

        $this->user = $user;
    }

    public function render(): View
    {
        return view('livewire.admin.users.edit');
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6">

        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('admin.users.index') }}" wire:navigate>User Management</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $user->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-white">Edit User</h1>
                    <p class="text-sm text-cu-muted">{{ $user->email }}</p>
                </div>
                <a href="{{ route('admin.users.index') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-cu-surface px-3 py-2 text-sm font-medium text-cu-muted hover:text-white">
                    <flux:icon icon="arrow-left" class="size-4" />
                    Back
                </a>
            </div>
        </div>

        {{-- Edit form --}}
        <form
            action="{{ route('admin.users.update', $user) }}"
            method="POST"
            class="cu-animate-in flex flex-col gap-5 rounded-2xl border border-white/5 bg-cu-surface p-6"
            style="animation-delay: 60ms"
        >
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <flux:label for="name">Full name</flux:label>
                <flux:input id="name" name="name" type="text" required value="{{ old('name', $user->name) }}" />
            </div>

            <div>
                <flux:label for="email">Email</flux:label>
                <flux:input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}" />
            </div>

            <div>
                <flux:label for="role">Role</flux:label>
                <flux:select id="role" name="role" required>
                    <option value="{{ User::ROLE_VENDOR }}" @selected(old('role', $user->role) === User::ROLE_VENDOR)>Vendor</option>
                    <option value="{{ User::ROLE_COMPLIANCE_OFFICER }}" @selected(old('role', $user->role) === User::ROLE_COMPLIANCE_OFFICER)>Compliance officer</option>
                    <option value="{{ User::ROLE_ADMIN }}" @selected(old('role', $user->role) === User::ROLE_ADMIN)>Admin</option>
                </flux:select>
                @if ($user->id === auth()->id())
                    <p class="mt-1 text-xs text-amber-300">You cannot change your own role here.</p>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <flux:label for="password">New password (optional)</flux:label>
                    <flux:input id="password" name="password" type="password" autocomplete="new-password" />
                </div>
                <div>
                    <flux:label for="password_confirmation">Confirm new password</flux:label>
                    <flux:input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                </div>
            </div>

            <label class="inline-flex items-center gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $user->is_active))
                       class="size-4 rounded border-white/20 bg-cu-bg text-cu-purple focus:ring-cu-purple">
                <span class="text-sm text-white">Active</span>
            </label>

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('admin.users.index') }}" wire:navigate
                   class="inline-flex items-center rounded-xl border border-white/10 bg-cu-bg px-4 py-2.5 text-sm font-medium text-cu-muted hover:text-white">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                    <flux:icon icon="check" class="size-4" />
                    Save Changes
                </button>
            </div>
        </form>

        {{-- Delete (separate form, with confirmation prompt) --}}
        <div class="cu-animate-in rounded-2xl border border-rose-500/20 bg-rose-500/5 p-6" style="animation-delay: 120ms">
            <h2 class="text-base font-semibold text-white">Danger zone</h2>
            <p class="mt-1 text-sm text-cu-muted">
                Deleting a user is permanent. You will be asked to type their email address to confirm.
            </p>

            <form
                x-data="{ open: false, typed: '' }"
                x-on:submit.prevent="if (typed === @js($user->email)) { $el.submit(); } else { alert('Email confirmation does not match.'); }"
                action="{{ route('admin.users.destroy', $user) }}"
                method="POST"
                class="mt-4 flex flex-col gap-4"
            >
                @csrf
                @method('DELETE')

                <button
                    x-show="!open"
                    x-on:click.prevent="open = true"
                    type="button"
                    class="self-start inline-flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-500/20">
                    <flux:icon icon="trash" class="size-4" />
                    Delete this user
                </button>

                <div x-show="open" x-transition class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4">
                    <p class="text-sm text-rose-200">
                        Type <span class="font-mono font-semibold text-white">{{ $user->email }}</span> to confirm:
                    </p>
                    <input
                        x-model="typed"
                        type="email"
                        class="mt-3 w-full rounded-xl border border-rose-500/30 bg-cu-bg px-3 py-2 text-sm text-white placeholder:text-cu-muted focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-500/30"
                        placeholder="{{ $user->email }}">
                    <div class="mt-3 flex items-center justify-end gap-2">
                        <button x-on:click.prevent="open = false; typed = ''" type="button"
                                class="rounded-lg border border-white/10 bg-cu-bg px-3 py-1.5 text-sm text-cu-muted hover:text-white">
                            Cancel
                        </button>
                        <button
                            x-bind:disabled="typed !== @js($user->email)"
                            type="submit"
                            class="rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Delete permanently
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-page>
