<?php

namespace App\Services;

use App\Models\RetentionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetentionPolicyService
{
    public function all(): array
    {
        $policies = RetentionPolicy::query()
            ->orderBy('data_type')
            ->orderBy('label')
            ->get()
            ->keyBy('key');
        $definitions = collect(RetentionPolicy::schema())->keyBy('key');

        return $definitions->map(function (array $definition, string $key) use ($policies): array {
            $policy = $policies->get($key);

            return [
                ...$definition,
                'persisted' => $policy,
                'value' => [
                    'retention_days' => (string) ($policy?->retention_days ?? $definition['retention_days']),
                    'archive_enabled' => (bool) ($policy?->archive_enabled ?? $definition['archive_enabled']),
                    'archive_after_days' => $policy?->archive_after_days !== null
                        ? (string) $policy->archive_after_days
                        : ($definition['archive_after_days'] === null ? '' : (string) $definition['archive_after_days']),
                    'deletion_enabled' => (bool) ($policy?->deletion_enabled ?? $definition['deletion_enabled']),
                    'deletion_after_days' => $policy?->deletion_after_days !== null
                        ? (string) $policy->deletion_after_days
                        : ($definition['deletion_after_days'] === null ? '' : (string) $definition['deletion_after_days']),
                    'enabled' => (bool) ($policy?->enabled ?? $definition['enabled']),
                    'notes' => (string) ($policy?->notes ?? $definition['notes']),
                    'rules' => $policy?->rules ?? $definition['rules'],
                ],
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload, ?User $actor = null): array
    {
        $definitions = collect(RetentionPolicy::schema())->keyBy('key');
        $errors = [];

        foreach ($payload as $key => $row) {
            if (! $definitions->has($key)) {
                continue;
            }

            $retentionDays = $this->toInt($row['retention_days'] ?? null);
            $archiveEnabled = filter_var($row['archive_enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $deletionEnabled = filter_var($row['deletion_enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $enabled = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $archiveAfterDays = $this->toNullableInt($row['archive_after_days'] ?? null);
            $deletionAfterDays = $this->toNullableInt($row['deletion_after_days'] ?? null);
            $notes = trim((string) ($row['notes'] ?? ''));

            if ($retentionDays === null || $retentionDays < 1) {
                $errors["policies.$key.retention_days"] = 'Retention days must be at least 1.';
            }

            if ($archiveEnabled && $archiveAfterDays === null) {
                $errors["policies.$key.archive_after_days"] = 'Archive after days is required when archival is enabled.';
            }

            if ($deletionEnabled && $deletionAfterDays === null) {
                $errors["policies.$key.deletion_after_days"] = 'Deletion after days is required when deletion is enabled.';
            }

            if ($archiveAfterDays !== null && $retentionDays !== null && $archiveAfterDays > $retentionDays) {
                $errors["policies.$key.archive_after_days"] = 'Archive timing must be less than or equal to the retention period.';
            }

            if ($deletionAfterDays !== null && $retentionDays !== null && $deletionAfterDays > $retentionDays) {
                $errors["policies.$key.deletion_after_days"] = 'Deletion timing must be less than or equal to the retention period.';
            }

            if ($archiveAfterDays !== null && $deletionAfterDays !== null && $archiveAfterDays > $deletionAfterDays) {
                $errors["policies.$key.archive_after_days"] = 'Archive timing must come before deletion timing.';
            }

            $payload[$key] = [
                'retention_days' => $retentionDays,
                'archive_enabled' => (bool) $archiveEnabled,
                'archive_after_days' => $archiveAfterDays,
                'deletion_enabled' => (bool) $deletionEnabled,
                'deletion_after_days' => $deletionAfterDays,
                'enabled' => (bool) $enabled,
                'notes' => $notes === '' ? null : $notes,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $saved = 0;

        DB::transaction(function () use ($payload, $actor, &$saved): void {
            foreach ($payload as $key => $row) {
                if (! collect(RetentionPolicy::schema())->pluck('key')->contains($key)) {
                    continue;
                }

                $definition = collect(RetentionPolicy::schema())->firstWhere('key', $key);
                if ($definition === null) {
                    continue;
                }

                RetentionPolicy::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'label' => $definition['label'],
                        'data_type' => $definition['data_type'],
                        'retention_days' => $row['retention_days'],
                        'archive_enabled' => $row['archive_enabled'],
                        'archive_after_days' => $row['archive_after_days'],
                        'deletion_enabled' => $row['deletion_enabled'],
                        'deletion_after_days' => $row['deletion_after_days'],
                        'enabled' => $row['enabled'],
                        'rules' => $definition['rules'],
                        'notes' => $row['notes'],
                        'updated_by' => $actor?->id,
                    ],
                );

                $saved++;
            }
        });

        return ['saved' => $saved];
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        $int = $this->toInt($value);

        return $int !== null && $int > 0 ? $int : null;
    }
}
