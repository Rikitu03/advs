<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin "Create User" form.
 *
 * Posts through the normal Laravel Form Request pipeline (POST
 * /admin/users → Admin\UserController@store). We keep the form in Volt
 * for reactive validation feedback; the controller remains the single
 * authoritative writer.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $role = User::ROLE_VENDOR;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $is_active = true;

    /**
     * @var array<string, string>
     */
    protected $messages = [
        'name.required' => 'A name is required.',
        'email.required' => 'An email address is required.',
        'email.email' => 'Please enter a valid email address.',
        'email.unique' => 'A user with this email already exists.',
        'role.in' => 'The selected role must be vendor, compliance officer, or admin.',
        'password.required' => 'A password is required.',
        'password.confirmed' => 'Password confirmation does not match.',
    ];

    public function render(): View
    {
        return view('livewire.admin.users.create');
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6">

        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('admin.users.index') }}" wire:navigate>User Management</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Create User</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Create User</h1>
            <p class="text-sm text-cu-muted">
                Create a new account and assign a role. New users are pre-verified.
            </p>
        </div>

        <form
            action="{{ route('admin.users.store') }}"
            method="POST"
            class="cu-animate-in flex flex-col gap-5 rounded-2xl border border-white/5 bg-cu-surface p-6"
            style="animation-delay: 60ms"
        >
            @csrf

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
                <flux:input id="name" name="name" type="text" required value="{{ old('name') }}" placeholder="Jane Doe" />
            </div>

            <div>
                <flux:label for="email">Email</flux:label>
                <flux:input id="email" name="email" type="email" required value="{{ old('email') }}" placeholder="user@example.com" />
            </div>

            <div>
                <flux:label for="role">Role</flux:label>
                <flux:select id="role" name="role" required>
                    <option value="{{ User::ROLE_VENDOR }}" @selected(old('role', User::ROLE_VENDOR) === User::ROLE_VENDOR)>Vendor</option>
                    <option value="{{ User::ROLE_COMPLIANCE_OFFICER }}" @selected(old('role') === User::ROLE_COMPLIANCE_OFFICER)>Compliance officer</option>
                    <option value="{{ User::ROLE_ADMIN }}" @selected(old('role') === User::ROLE_ADMIN)>Admin</option>
                </flux:select>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <flux:label for="password">Password</flux:label>
                    <flux:input id="password" name="password" type="password" required autocomplete="new-password" />
                </div>
                <div>
                    <flux:label for="password_confirmation">Confirm password</flux:label>
                    <flux:input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
                </div>
            </div>

            <label class="inline-flex items-center gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" checked
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
                    <flux:icon icon="user-plus" class="size-4" />
                    Create User
                </button>
            </div>
        </form>
    </div>
</x-page>
