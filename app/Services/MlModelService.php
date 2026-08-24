<?php

namespace App\Services;

use App\Livewire\Admin\Audit\Index;
use App\Models\AuditLog;
use App\Models\MlModel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Application-layer facade for the "ML Model Management" panel.
 *
 * Wraps three concerns so call sites stay thin:
 *  1. **Sync** — re-probe every {@see MlModel}'s file on disk through
 *     {@see MlModelScanner} and refresh size / mtime / hash / status.
 *  2. **Mutate** — change a model's status / version / notes with input
 *     validation and audit logging in a single transaction.
 *  3. **Audit** — every state-changing action records an {@see AuditLog}
 *     row tagged with `entity_type = MlModel::class` so the
 *     {@see Index} page filters it correctly.
 */
class MlModelService
{
    public function __construct(protected MlModelScanner $scanner) {}

    /**
     * Sync every registered model against the filesystem.
     *
     * For each row:
     *   - probe the file via {@see MlModelScanner::probe()}
     *   - update size / mtime / hash / last_synced_at
     *   - if the file vanished, flip status to `missing`
     *     (unless the operator had explicitly marked it deprecated)
     *
     * Returns a small summary so the UI can flash a useful toast.
     *
     * @return array{scanned:int, found:int, missing:int, hashed:int}
     */
    public function syncAll(bool $withHash = false): array
    {
        $scanned = 0;
        $found = 0;
        $missing = 0;
        $hashed = 0;

        DB::transaction(function () use ($withHash, &$scanned, &$found, &$missing, &$hashed): void {
            MlModel::query()
                ->orderBy('id')
                ->each(function (MlModel $model) use ($withHash, &$scanned, &$found, &$missing, &$hashed): void {
                    $scanned++;

                    $probe = $this->scanner->probe($model, $withHash);

                    $payload = [
                        'file_size_bytes' => $probe['file_size_bytes'],
                        'last_trained_at' => $probe['last_trained_at'],
                        'last_synced_at' => Carbon::now(),
                    ];

                    if ($probe['checksum_sha256'] !== null) {
                        $payload['checksum_sha256'] = $probe['checksum_sha256'];
                        $hashed++;
                    }

                    if (! $probe['exists']) {
                        $missing++;

                        if ($model->status !== 'missing' && $model->status !== 'deprecated') {
                            $payload['status'] = 'missing';
                        }
                    } else {
                        $found++;
                        // If a previously-missing file has come back, restore
                        // the previous status (active / standby) rather than
                        // guessing — the operator may have intentionally
                        // marked it standby.
                        if ($model->status === 'missing') {
                            $payload['status'] = 'standby';
                        }
                    }

                    $model->forceFill($payload)->save();
                });
        });

        return [
            'scanned' => $scanned,
            'found' => $found,
            'missing' => $missing,
            'hashed' => $hashed,
        ];
    }

    /**
     * Sync a single row. Used by the per-row "Re-check" button. Writes an
     * audit log on every invocation so admins can see who triggered each
     * sync.
     */
    public function syncOne(MlModel $model, ?User $actor = null, bool $withHash = false): array
    {
        $probe = $this->scanner->probe($model, $withHash);

        $payload = [
            'file_size_bytes' => $probe['file_size_bytes'],
            'last_trained_at' => $probe['last_trained_at'],
            'last_synced_at' => Carbon::now(),
        ];

        if ($probe['checksum_sha256'] !== null) {
            $payload['checksum_sha256'] = $probe['checksum_sha256'];
        }

        if (! $probe['exists']) {
            if ($model->status !== 'missing' && $model->status !== 'deprecated') {
                $payload['status'] = 'missing';
            }
        } elseif ($model->status === 'missing') {
            $payload['status'] = 'standby';
        }

        DB::transaction(function () use ($model, $payload, $actor, $probe): void {
            $model->forceFill($payload)->save();

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => $probe['exists'] ? 'ml_model.synced' : 'ml_model.missing',
                'entity_type' => MlModel::class,
                'entity_id' => $model->id,
                'details' => [
                    'name' => $model->name,
                    'storage_path' => $model->storage_path,
                    'resolved_path' => $probe['resolved_path'],
                    'file_size_bytes' => $probe['file_size_bytes'],
                    'status_after' => $model->status,
                ],
                'ip_address' => request()?->ip(),
            ]);
        });

        Log::info('ml_model.sync', [
            'model_id' => $model->id,
            'name' => $model->name,
            'exists' => $probe['exists'],
            'status_after' => $model->status,
            'actor_id' => $actor?->id,
        ]);

        return [
            'exists' => $probe['exists'],
            'status_after' => $model->status,
            'file_size_bytes' => $probe['file_size_bytes'],
            'last_trained_at' => $probe['last_trained_at']?->toIso8601String(),
        ];
    }

    /**
     * Update the operator-editable fields on a row. Validates inputs
     * against the canonical enums on {@see MlModel::STATUSES} and
     * {@see MlModel::PURPOSES} and records an `ml_model.updated` audit
     * log capturing the before/after diff.
     *
     * @param  array{name?:string, purpose?:string, version?:string, status?:string, notes?:string|null, metrics?:array<string,mixed>|null}  $changes
     *
     * @throws \InvalidArgumentException When a value is not in the allowed enum.
     */
    public function update(MlModel $model, array $changes, ?User $actor = null): MlModel
    {
        $before = [
            'name' => $model->name,
            'purpose' => $model->purpose,
            'version' => $model->version,
            'status' => $model->status,
            'notes' => $model->notes,
            'metrics' => $model->metrics,
        ];

        DB::transaction(function () use ($model, $changes, $actor, $before): void {
            $dirty = [];

            if (array_key_exists('name', $changes) && $changes['name'] !== null) {
                $name = trim((string) $changes['name']);
                if ($name === '') {
                    throw new \InvalidArgumentException('Name may not be empty.');
                }
                $dirty['name'] = $name;
            }

            if (array_key_exists('purpose', $changes) && $changes['purpose'] !== null) {
                $purpose = (string) $changes['purpose'];
                if (! in_array($purpose, MlModel::PURPOSES, true)) {
                    throw new \InvalidArgumentException("Unknown purpose \"{$purpose}\".");
                }
                $dirty['purpose'] = $purpose;
            }

            if (array_key_exists('version', $changes) && $changes['version'] !== null) {
                $version = trim((string) $changes['version']);
                if ($version === '' || strlen($version) > 20) {
                    throw new \InvalidArgumentException('Version must be 1–20 characters.');
                }
                $dirty['version'] = $version;
            }

            if (array_key_exists('status', $changes) && $changes['status'] !== null) {
                $status = (string) $changes['status'];
                if (! in_array($status, MlModel::STATUSES, true)) {
                    throw new \InvalidArgumentException("Unknown status \"{$status}\".");
                }
                $dirty['status'] = $status;
            }

            if (array_key_exists('notes', $changes)) {
                $notes = $changes['notes'];
                $dirty['notes'] = $notes === null ? null : (string) $notes;
            }

            if (array_key_exists('metrics', $changes)) {
                $metrics = $changes['metrics'];
                if ($metrics !== null && ! is_array($metrics)) {
                    throw new \InvalidArgumentException('Metrics must be an object (key/value).');
                }
                $dirty['metrics'] = $metrics;
            }

            if ($dirty === []) {
                return;
            }

            $dirty['updated_by'] = $actor?->id;
            $model->forceFill($dirty)->save();

            // Slim diff — only the keys that actually changed.
            $diff = [];
            foreach ($dirty as $k => $v) {
                if ($k === 'updated_by') {
                    continue;
                }
                $diff[$k] = [
                    'before' => $before[$k] ?? null,
                    'after' => $v,
                ];
            }

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'ml_model.updated',
                'entity_type' => MlModel::class,
                'entity_id' => $model->id,
                'details' => [
                    'name' => $model->name,
                    'changes' => $diff,
                ],
                'ip_address' => request()?->ip(),
            ]);

            Log::info('ml_model.updated', [
                'model_id' => $model->id,
                'name' => $model->name,
                'fields' => array_keys($diff),
                'actor_id' => $actor?->id,
            ]);
        });

        return $model->refresh();
    }
}
