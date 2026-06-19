<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Only system administrators may view or modify tunable thresholds.
 * Mirrors the convention used by {@see UserPolicy}.
 */
class SystemSettingPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    public function view(User $actor, SystemSetting $setting): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    public function update(User $actor, SystemSetting $setting): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }
}
