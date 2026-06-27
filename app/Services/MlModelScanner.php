<?php

namespace App\Services;

use App\Models\MlModel;
use Illuminate\Support\Carbon;

/**
 * Filesystem scanner for ML-model weight files.
 *
 * The ADVS validation pipeline consumes four model files that live under
 * `python/models/` (see ADVS_System_Reference.md §5 and CLAUDE.md §6):
 *
 *   - resnet50_authenticity.h5     → Document Classifier  (classification)
 *   - yolov8_document.pt           → Signature Detector   (detection)
 *   - siamese_signature.h5         → Signature Verifier   (signature)
 *   - efficientnet_stamp.h5        → Stamp Verifier       (stamp_logo)
 *
 * This service does two things:
 *
 *  1. **Resolve a stored path** — accept either a relative path
 *     (interpreted against the project root) or an absolute path, and
 *     return the canonical absolute filesystem path, or `null` if the
 *     input is empty / unsafe.
 *  2. **Probe a row** — given an {@see MlModel}, return a structured
 *     snapshot of the file's on-disk state: size, mtime, optional SHA-256
 *     hash, and a bool telling the caller whether the row's status should
 *     flip to `missing`.
 *
 * The scanner intentionally does NOT mutate any database row — that's
 * {@see MlModelService}'s job, so this class stays trivially unit-testable.
 */
class MlModelScanner
{
    /**
     * Base directory for relative model paths. Defaults to the project root
     * so the typical `python/models/foo.h5` storage path resolves correctly.
     */
    public function basePath(): string
    {
        return base_path();
    }

    /**
     * Resolve a stored storage_path to an absolute filesystem path.
     *
     * Returns `null` for empty / unsafe input so the caller can render
     * "no path configured" without throwing.
     */
    public function resolvePath(?string $storagePath): ?string
    {
        if ($storagePath === null) {
            return null;
        }

        $storagePath = trim($storagePath);

        if ($storagePath === '') {
            return null;
        }

        // Absolute (Win + Unix) → trust the operator.
        if ($this->isAbsolute($storagePath)) {
            return $storagePath;
        }

        // Relative → resolve against the project root.
        return rtrim($this->basePath(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .ltrim($storagePath, DIRECTORY_SEPARATOR);
    }

    /**
     * Whether the scanner found the file at the resolved path. Convenience
     * helper used by the Blade view's status hint.
     */
    public function fileExists(?string $storagePath): bool
    {
        $path = $this->resolvePath($storagePath);

        return $path !== null && is_file($path);
    }

    /**
     * Probe an {@see MlModel} and return a snapshot describing what's
     * actually on disk right now.
     *
     * @return array{
     *     exists: bool,
     *     resolved_path: ?string,
     *     file_size_bytes: ?int,
     *     last_trained_at: ?Carbon,
     *     checksum_sha256: ?string,
     *     should_be_missing: bool
     * }
     */
    public function probe(MlModel $model, bool $withHash = false): array
    {
        $resolved = $this->resolvePath($model->storage_path);

        if ($resolved === null || ! is_file($resolved)) {
            return [
                'exists' => false,
                'resolved_path' => $resolved,
                'file_size_bytes' => null,
                'last_trained_at' => null,
                'checksum_sha256' => null,
                'should_be_missing' => true,
            ];
        }

        $stat = @stat($resolved);

        $size = is_array($stat) ? ($stat['size'] ?? null) : null;
        $mtime = is_array($stat) ? ($stat['mtime'] ?? null) : null;

        $hash = null;
        if ($withHash) {
            $hash = @hash_file('sha256', $resolved) ?: null;
        }

        return [
            'exists' => true,
            'resolved_path' => $resolved,
            'file_size_bytes' => $size !== null ? (int) $size : null,
            'last_trained_at' => $mtime !== null ? Carbon::createFromTimestamp($mtime) : null,
            'checksum_sha256' => $hash,
            // Only flip to `missing` if the *expected* status says it should
            // be available (active / standby / deprecated). We never override
            // an explicit `missing` flag when the file is found — that
            // direction of change is admin-driven via the Volt action.
            'should_be_missing' => $model->status !== 'missing' && $model->status !== 'deprecated',
        ];
    }

    /**
     * Cross-platform absolute-path check. `is_absolute_path()` doesn't
     * exist on every PHP build we ship to, so we inline the check.
     */
    protected function isAbsolute(string $path): bool
    {
        // Unix absolute
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute — drive letter, UNC path, or backslash-rooted.
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, '\\\\')) {
            return true;
        }

        return false;
    }
}
