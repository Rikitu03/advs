<?php

namespace App\Services\Signature;

use Illuminate\Http\UploadedFile;

/**
 * Decides whether an uploaded signature photo is an authentic capture or a
 * software-edited image, before it is enrolled as a vendor's reference.
 *
 * ── MOCK IMPLEMENTATION ──────────────────────────────────────────────────
 * Real forgery detection (EXIF/metadata inspection, Error-Level Analysis, and
 * a CNN classifier) is part of the planned Python forensics pipeline
 * (ADVS_System_Reference.md §5–§6, `python/verify_signature.py`). Until that
 * lands, this service SIMULATES the verdict:
 *
 *   - authentic by default, so normal uploads pass;
 *   - rejected when the original filename matches {@see self::EDITED_PATTERN},
 *     a documented hook that makes the rejection / re-upload flow demonstrable
 *     and testable (e.g. upload "signature-edited.jpg").
 *
 * The public contract (`verify(): array`) is what the real detector will also
 * return, so the registration flow does not change when the mock is replaced.
 */
class SignatureAuthenticityService
{
    /**
     * Placeholder trigger for the "software-edited" verdict. Replace with real
     * forensic analysis when the Python pipeline is wired in.
     */
    private const EDITED_PATTERN = '/edited|forged|fake|tampered/i';

    /**
     * Verify a signature photo.
     *
     * @return array{authentic: bool, score: float, reasons: list<string>}
     */
    public function verify(UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();

        if (preg_match(self::EDITED_PATTERN, $name) === 1) {
            return [
                'authentic' => false,
                'score' => 0.18,
                'reasons' => [
                    'Editing-software metadata was detected in the image.',
                    'Compression artifacts are inconsistent with a single original capture.',
                ],
            ];
        }

        return [
            'authentic' => true,
            'score' => 0.97,
            'reasons' => [],
        ];
    }
}
