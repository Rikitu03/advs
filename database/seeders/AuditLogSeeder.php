<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed a handful of realistic audit-log rows so the
 * {@see /admin/audit} page has something to display on a fresh database.
 *
 * The seeded rows exercise every action the UI knows about and span a
 * few days so the date-range filter has data to work with.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@advs.test')->firstOrFail();
        $officer = User::where('email', 'officer@advs.test')->firstOrFail();
        $officer2 = User::where('email', 'officer2@advs.test')->firstOrFail();
        $vendor = User::where('email', 'vendor@advs.test')->firstOrFail();

        $rows = [
            [
                'user' => $admin,
                'action' => 'auth.login',
                'entity' => [User::class, $admin->id],
                'details' => ['guard' => 'web', 'ip' => '127.0.0.1'],
                'when' => now()->subDays(2),
            ],
            [
                'user' => $admin,
                'action' => 'user.created',
                'entity' => [User::class, $officer2->id],
                'details' => [
                    'before' => null,
                    'after' => ['name' => $officer2->name, 'email' => $officer2->email, 'role' => $officer2->role],
                ],
                'when' => now()->subDays(2)->addMinutes(15),
            ],
            [
                'user' => $admin,
                'action' => 'user.role_changed',
                'entity' => [User::class, $officer2->id],
                'details' => [
                    'before' => ['role' => User::ROLE_VENDOR],
                    'after' => ['role' => User::ROLE_COMPLIANCE_OFFICER],
                ],
                'when' => now()->subDays(2)->addMinutes(20),
            ],
            [
                'user' => $admin,
                'action' => 'system_setting.updated',
                'entity' => [SystemSetting::class, null],
                'details' => [
                    'key' => 'risk_threshold_high',
                    'before' => '70',
                    'after' => '75',
                ],
                'when' => now()->subDay(),
            ],
            [
                'user' => $officer,
                'action' => 'auth.login',
                'entity' => [User::class, $officer->id],
                'details' => ['guard' => 'web'],
                'when' => now()->subHours(20),
            ],
            [
                'user' => $officer,
                'action' => 'submission.decided',
                'entity' => [SystemSetting::class, null],
                'details' => [
                    'before' => ['decision' => 'pending'],
                    'after' => ['decision' => 'approved', 'notes' => 'All checks passed.'],
                    'submission_id' => 1,
                ],
                'when' => now()->subHours(18),
            ],
            [
                'user' => $officer2,
                'action' => 'submission.flagged',
                'entity' => [SystemSetting::class, null],
                'details' => [
                    'risk_score' => 82.5,
                    'reasons' => ['signature_mismatch', 'low_classification_confidence'],
                    'submission_id' => 2,
                ],
                'when' => now()->subHours(8),
            ],
            [
                'user' => $vendor,
                'action' => 'auth.login',
                'entity' => [User::class, $vendor->id],
                'details' => ['guard' => 'web'],
                'when' => now()->subHours(3),
            ],
            [
                'user' => $admin,
                'action' => 'system_setting.reset',
                'entity' => [SystemSetting::class, null],
                'details' => ['key' => 'risk_threshold_high', 'restored_to' => '70'],
                'when' => now()->subHour(),
            ],
            [
                'user' => $admin,
                'action' => 'auth.logout',
                'entity' => [User::class, $admin->id],
                'details' => ['guard' => 'web'],
                'when' => now()->subMinutes(15),
            ],
        ];

        foreach ($rows as $row) {
            [$type, $id] = $row['entity'];

            AuditLog::factory()
                ->forUser($row['user'])
                ->action($row['action'])
                ->forEntity($type, $id, $row['details'])
                ->at($row['when'])
                ->create(['ip_address' => '127.0.0.1']);
        }
    }
}
