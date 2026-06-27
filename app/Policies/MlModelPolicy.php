<?php

namespace App\Policies;

use App\Models\MlModel;
use App\Models\User;

/**
 * Authorization rules for the admin "ML Model Management" panel.
 *
 * ML model metadata discloses where the pipeline's weight files live and
 * who is registering versions — sensitive enough that we gate it the
 * same way as {@see SystemSettingPolicy} and {@see AuditLogPolicy}:
 * **system administrators only**. Compliance officers and vendors are
 * explicitly denied.
 */
class MlModelPolicy
{
    /**
     * Only admins may browse the ML-model index.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may view a single ML-model detail row.
     */
    public function view(User $actor, MlModel $model): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may mutate a model's configuration (status, notes,
     * version, …). The route middleware additionally enforces `role:admin`
     * so a stale policy can't be exploited via a direct controller call.
     */
    public function update(User $actor, MlModel $model): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may register a new ML-model row.
     */
    public function create(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may deregister / remove an ML-model row.
     */
    public function delete(User $actor, MlModel $model): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MlModel $mlModel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MlModel $mlModel): bool
    {
        return false;
    }
}
