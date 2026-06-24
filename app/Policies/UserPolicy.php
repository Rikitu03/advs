<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization rules for admin user-management actions.
 *
 * Only administrators can manage users. The authenticated user is always
 * prohibited from deleting or demoting their own account so an admin cannot
 * accidentally lock themselves out (or escalate by handing the role away).
 */
class UserPolicy
{
    /**
     * Only admins may list users (the admin user management index).
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Viewing a single user record is restricted to admins.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may create new user accounts from the back office.
     */
    public function create(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may update users, and they cannot edit their own role to
     * prevent an admin demoting themselves by accident.
     */
    public function update(User $actor, User $target): bool
    {
        if (! $actor->hasRole(User::ROLE_ADMIN)) {
            return false;
        }

        // Disallow self-edit through the admin form to keep Fortify's own
        // profile/settings flows as the single source of truth for one's
        // own account. Admins still edit their own profile via settings.
        return $actor->id !== $target->id;
    }

    /**
     * Only admins may change another user's role. Admins cannot change their
     * own role.
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN) && $actor->id !== $target->id;
    }

    /**
     * Only admins may delete users, never their own account, and never the
     * last remaining admin (to avoid losing all admin access).
     */
    public function delete(User $actor, User $target): bool
    {
        if (! $actor->hasRole(User::ROLE_ADMIN)) {
            return false;
        }

        if ($actor->id === $target->id) {
            return false;
        }

        if ($target->hasRole(User::ROLE_ADMIN)) {
            return $this->remainingAdminCount() > 1;
        }

        return true;
    }

    /**
     * Helper: number of admin accounts in the system (used by delete()).
     */
    protected function remainingAdminCount(): int
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->count();
    }
}
