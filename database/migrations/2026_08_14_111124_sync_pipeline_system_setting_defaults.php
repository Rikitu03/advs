<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceDefault('signature_distance_threshold', '1.20', '1.243976');
        $this->replaceDefault('stamp_similarity_threshold', '0.85', '0.80');
        $this->replaceDefault('risk_weight_text', '0.25', '0.20');
        $this->replaceDefault('risk_weight_classification', '0.25', '0.20');
        $this->replaceDefault('risk_weight_signature', '0.25', '0.20');
        $this->replaceDefault('risk_weight_stamp', '0.25', '0.20');

        $this->insertMissing([
            'stamp_tamper_threshold' => ['0.50', 'Minimum genuine probability for the stamp texture classifier'],
            'risk_weight_tamper' => ['0.20', 'Weight of Stage T forensic authenticity in composite risk score'],
            'tamper_authenticity_threshold' => ['0.50', 'Minimum Stage T forensic authenticity'],
            'tamper_hard_threshold' => ['0.80', 'Stage T confidence that forces High Risk'],
            'tamper_weight_metadata' => ['0.20', 'Stage T metadata weight'],
            'tamper_weight_ela' => ['0.25', 'Stage T error-level-analysis weight'],
            'tamper_weight_copy_move' => ['0.25', 'Stage T copy-move weight'],
            'tamper_weight_font' => ['0.15', 'Stage T font-anomaly weight'],
            'tamper_weight_cross_reference' => ['0.15', 'Stage T cross-reference weight'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'stamp_tamper_threshold',
            'risk_weight_tamper',
            'tamper_authenticity_threshold',
            'tamper_hard_threshold',
            'tamper_weight_metadata',
            'tamper_weight_ela',
            'tamper_weight_copy_move',
            'tamper_weight_font',
            'tamper_weight_cross_reference',
        ])->delete();

        $this->replaceDefault('signature_distance_threshold', '1.243976', '1.20');
        $this->replaceDefault('stamp_similarity_threshold', '0.80', '0.85');
        $this->replaceDefault('risk_weight_text', '0.20', '0.25');
        $this->replaceDefault('risk_weight_classification', '0.20', '0.25');
        $this->replaceDefault('risk_weight_signature', '0.20', '0.25');
        $this->replaceDefault('risk_weight_stamp', '0.20', '0.25');
    }

    private function replaceDefault(string $key, string $oldValue, string $newValue): void
    {
        DB::table('system_settings')
            ->where('key', $key)
            ->where('value', $oldValue)
            ->update(['value' => $newValue, 'updated_at' => now()]);
    }

    /** @param array<string, array{0: string, 1: string}> $settings */
    private function insertMissing(array $settings): void
    {
        foreach ($settings as $key => [$value, $description]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
