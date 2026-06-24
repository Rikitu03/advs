<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Thin admin-only controller for managing user accounts. All authorisation
 * is delegated to {@see UserPolicy} (registered in
 * AppServiceProvider) and the `role:admin` middleware on every route.
 *
 * Heavy lifting (search, pagination, role filtering) lives in the Volt
 * component at admin/users/index so the listing page stays reactive.
 */
class UserController extends Controller
{
    /**
     * Render the user-management dashboard. The actual listing / search /
     * pagination is handled by the admin.users.index Volt component which
     * is mounted as a full-page route.
     */
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $roleCounts = User::query()
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        return view('admin.users.index', [
            'roleCounts' => $roleCounts,
            'activeCount' => User::query()->where('is_active', true)->count(),
            'totalCount' => User::query()->count(),
        ]);
    }

    /**
     * Render the create-user form. The actual form is the admin.users.create
     * Volt component.
     */
    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create');
    }

    /**
     * Persist a new user account created by an admin.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        // Authorize() is already enforced by the FormRequest.
        $data = $request->validated();

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? true,
                'email_verified_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Admin user creation failed', [
                'actor_id' => $request->user('web')?->id,
                'email' => $data['email'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['email' => 'Could not create user — please try again.']);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->email} ({$user->role}) created.");
    }

    /**
     * Render the edit-user form. The actual form is the admin.users.edit
     * Volt component.
     */
    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', [
            'user' => $user,
        ]);
    }

    /**
     * Update an existing user. Optional password is ignored when blank so
     * the stored hash isn't blanked.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        // Authorize() already enforced by UpdateUserRequest.
        $data = $request->validated();

        // If the user is being demoted out of admin, ensure at least one
        // admin remains active so we never lock the system out.
        if (
            $user->hasRole(User::ROLE_ADMIN)
            && ($data['role'] ?? null) !== User::ROLE_ADMIN
            && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1
        ) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'Cannot demote the last remaining admin.']);
        }

        try {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            if (! empty($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $user->save();
        } catch (Throwable $e) {
            Log::error('Admin user update failed', [
                'actor_id' => $request->user('web')?->id,
                'target_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['email' => 'Could not update user — please try again.']);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->email} updated.");
    }

    /**
     * Update a user's role only — used by the row-action dropdown on the
     * user listing.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if (
            $user->hasRole(User::ROLE_ADMIN)
            && $data['role'] !== User::ROLE_ADMIN
            && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1
        ) {
            return back()->withErrors(['role' => 'Cannot demote the last remaining admin.']);
        }

        $user->role = $data['role'];
        $user->save();

        return back()->with('status', "Role updated to {$user->role} for {$user->email}.");
    }

    /**
     * Delete a user. Requires the admin to type the user's email into the
     * confirmation field (validated by DestroyUserRequest). The policy
     * prevents self-deletion and deleting the last remaining admin.
     */
    public function destroy(DestroyUserRequest $request, User $user): RedirectResponse
    {
        // Authorize() is already enforced by DestroyUserRequest.

        $email = $user->email;

        try {
            $user->delete();
        } catch (Throwable $e) {
            Log::error('Admin user deletion failed', [
                'actor_id' => $request->user('web')?->id,
                'target_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['email' => 'Could not delete user — please try again.']);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$email} deleted.");
    }
}
