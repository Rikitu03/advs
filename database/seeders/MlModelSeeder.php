<?php

namespace Database\Seeders;

use App\Models\MlModel;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Registers the four ML model identities that the ADVS pipeline consumes
 * (see ADVS_System_Reference.md §5 and CLAUDE.md §6):
 *
 *   - Document Classifier  (ResNet-50)   → python/models/resnet50_authenticity.h5
 *   - Signature Detector   (YOLOv8)      → python/models/yolov8_document.pt
 *   - Signature Verifier   (Siamese CNN) → python/models/siamese_signature.h5
 *   - Stamp Verifier       (EfficientNet)→ python/models/efficientnet_stamp.h5
 *
 * On a fresh checkout the weight files are not present (gitignored; see
 * AGENTS.md §5). The seeder still writes the rows so the panel can render
 * them in their `missing` state — the operator then drops the actual
 * `.h5`/`.pt` files into `python/models/` and runs `Sync` from the panel.
 *
 * `firstOrCreate` is keyed on `name` so re-running the seeder does not
 * duplicate rows.
 */
class MlModelSeeder extends Seeder
{
    /**
     * The four canonical model identities shipped with the system.
     *
     * @var array<int, array{name:string, purpose:string, version:string, status:string, storage_path:string, notes:string, metrics:array<string,mixed>}>
     */
    public const ENTRIES = [
        [
            'name' => 'Document Classifier',
            'purpose' => 'classification',
            'version' => '1.0.0',
            'status' => 'active',
            'storage_path' => 'python/models/resnet50_best.keras',
            'notes' => 'ResNet-50 backbone fine-tuned on the BIR / SEC / business-permit dataset. Drives the "classification" stage of the validation pipeline (see ADVS_System_Reference.md §5).',
            'metrics' => [
                'classes' => ['bir_certificate', 'business_permit', 'dti_registration', 'fake'],
                'input_shape' => [512, 512, 3],
            ],
        ],
        [
            'name' => 'Signature Detector',
            'purpose' => 'detection',
            'version' => '1.0.0',
            'status' => 'active',
            'storage_path' => 'python/models/yolov8_nano_moredata_best.pt',
            'notes' => 'YOLOv8 detector that locates signature / stamp bounding boxes inside the preprocessed document image. Used by both the signature and stamp verification stages.',
            'metrics' => [
                'classes' => ['signature', 'stamp', 'logo'],
            ],
        ],
        [
            'name' => 'Signature Verifier',
            'purpose' => 'signature',
            'version' => '1.0.0',
            'status' => 'active',
            'storage_path' => 'python/models/siamese_encoder.h5',
            'notes' => 'Siamese CNN producing a 128-D embedding per signature crop. Compared against the vendor reference embedding enrolled at registration via Euclidean distance.',
            'metrics' => [
                'eer' => 0.032,
                'far' => 0.027,
                'frr' => 0.036,
                'embedding_dim' => 128,
                'distance_threshold' => 1.243976,
            ],
        ],
        [
            'name' => 'Stamp Verifier',
            'purpose' => 'stamp_logo',
            'version' => '1.0.0',
            'status' => 'active',
            'storage_path' => 'python/models/efficientnet_feature_extractor.h5',
            'notes' => 'EfficientNet feature extractor for stamp / logo / seal verification. Cosine similarity is compared against the issuer reference logo (national type, or per-city for LGU types).',
            'metrics' => [
                'classifier_accuracy' => 0.90,
                'similarity_threshold' => 0.80,
                'texture_classifier' => 'python/models/stamp_classifier.pkl',
            ],
        ],
    ];

    public function run(): void
    {
        // Stamp `updated_by` on the first admin we find so the audit panel
        // can render "Last changed by". Nullable on the schema so a fresh
        // database without seeded users still works.
        $actor = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        foreach (self::ENTRIES as $entry) {
            MlModel::query()->updateOrCreate(
                ['name' => $entry['name']],
                array_merge($entry, [
                    'updated_by' => $actor?->id,
                ]),
            );
        }
    }
}
