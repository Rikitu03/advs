<?php

namespace App\Policies;

use App\Models\RetentionPolicy;
use App\Models\User;

class RetentionPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function view(User $user, RetentionPolicy $retentionPolicy): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function update(User $user, RetentionPolicy $retentionPolicy): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function delete(User $user, RetentionPolicy $retentionPolicy): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function restore(User $user, RetentionPolicy $retentionPolicy): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function forceDelete(User $user, RetentionPolicy $retentionPolicy): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }
}
