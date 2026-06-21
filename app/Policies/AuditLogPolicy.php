<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Authorization rules for the audit-trail viewer (see
 * {@see AuditLog}).
 *
 * The audit log is the most sensitive surface in the system — it discloses
 * every state change an admin/officer has ever performed — so it is gated to
 * the system-administrator role only. Compliance officers and vendors are
 * explicitly denied, mirroring the convention used by
 * {@see SystemSettingPolicy}.
 */
class AuditLogPolicy
{
    /**
     * Only admins may browse the audit log index.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Only admins may view a single audit-log entry (e.g. for the detail
     * drawer / "View full payload" route).
     */
    public function view(User $actor, AuditLog $log): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Audit rows are immutable; creation is reserved for the system itself
     * and is therefore never authorised from a user-driven route.
     */
    public function create(User $actor): bool
    {
        return false;
    }
}
