<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingSeeder extends Seeder
{
    /**
     * Seed all tunable parameters from the system reference (§9)
     * (mirrors ADVS_Final_Schema.sql §10 seed block).
     */
    public function run(): void
    {
        $now = now();

        $settings = [
            ['max_file_size_mb', '10', 'Maximum upload size per file in MB'],
            ['max_batch_size_mb', '50', 'Maximum total upload size per submission in MB'],
            ['accepted_formats', 'pdf,png,jpg,jpeg', 'Comma-separated list of allowed file extensions'],
            ['max_pdf_pages', '2', 'Number of PDF pages to extract and process'],
            ['pdf_dpi', '300', 'Resolution for PDF-to-PNG conversion'],
            ['resize_dimension', '512', 'Image resize target (pixels) for ResNet-50 input'],
            ['binarization_threshold', '150', 'Grayscale threshold for OpenCV binarization'],
            ['morph_kernel_size', '2', 'Kernel dimension for morphological opening'],
            ['classification_confidence_threshold', '0.70', 'Minimum ResNet-50 confidence to pass'],
            ['yolo_detection_confidence', '0.20', 'Minimum YOLOv8 detection confidence'],
            ['signature_distance_threshold', '1.243976', 'Maximum Euclidean distance for signature match'],
            ['stamp_similarity_threshold', '0.80', 'Minimum cosine similarity for stamp match (0-1)'],
            ['stamp_tamper_threshold', '0.50', 'Minimum genuine probability for the stamp texture classifier'],
            ['risk_weight_text', '0.20', 'Weight of text validation in composite risk score'],
            ['risk_weight_classification', '0.20', 'Weight of classification authenticity in composite risk score'],
            ['risk_weight_signature', '0.20', 'Weight of signature score in composite risk score'],
            ['risk_weight_stamp', '0.20', 'Weight of stamp score in composite risk score'],
            ['risk_weight_tamper', '0.20', 'Weight of Stage T forensic authenticity in composite risk score'],
            ['missing_component_penalty', '15', 'Additional risk points per missing component'],
            ['tamper_authenticity_threshold', '0.50', 'Minimum Stage T forensic authenticity'],
            ['tamper_hard_threshold', '0.80', 'Stage T confidence that forces High Risk'],
            ['tamper_weight_metadata', '0.20', 'Stage T metadata weight'],
            ['tamper_weight_ela', '0.25', 'Stage T error-level-analysis weight'],
            ['tamper_weight_copy_move', '0.25', 'Stage T copy-move weight'],
            ['tamper_weight_font', '0.15', 'Stage T font-anomaly weight'],
            ['tamper_weight_cross_reference', '0.15', 'Stage T cross-reference weight'],
            ['high_risk_threshold', '61', 'Composite score >= this = High Risk'],
            ['medium_risk_threshold', '31', 'Composite score >= this = Medium Risk'],
            ['data_retention_years', '5', 'Years before documents are eligible for archival'],
        ];

        DB::table('system_settings')->insertOrIgnore(array_map(fn (array $setting) => [
            'key' => $setting[0],
            'value' => $setting[1],
            'description' => $setting[2],
            'created_at' => $now,
            'updated_at' => $now,
        ], $settings));
    }
}
