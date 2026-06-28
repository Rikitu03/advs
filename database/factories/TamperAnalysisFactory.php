<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\TamperAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TamperAnalysis>
 */
class TamperAnalysisFactory extends Factory
{
    protected $model = TamperAnalysis::class;

    /**
     * Default state — a clean document (no tampering evidence).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = Document::factory();

        return [
            'document_id' => $document,
            'submission_id' => fn (array $attrs): int => Document::find($attrs['document_id'])->submission_id,
            'tamper_score' => 0.05,
            'tamper_authenticity' => 0.95,
            'tamper_confidence' => 0.05,
            'hard_flag' => false,
            'tamper_passed' => true,
            'metadata_result' => ['score' => 1.0, 'pass' => true, 'flags' => []],
            'ela_result' => ['score' => 1.0, 'pass' => true, 'flags' => []],
            'copy_move_result' => ['score' => 1.0, 'pass' => true, 'flags' => []],
            'font_result' => ['score' => 1.0, 'pass' => true, 'flags' => []],
            'cross_reference_result' => ['score' => 1.0, 'pass' => true, 'flags' => []],
            'flags' => [],
        ];
    }

    /**
     * A tampered document with a strong single-technique signal (hard flag).
     */
    public function tampered(): static
    {
        return $this->state(fn (): array => [
            'tamper_score' => 0.62,
            'tamper_authenticity' => 0.38,
            'tamper_confidence' => 0.85,
            'hard_flag' => true,
            'tamper_passed' => false,
            'copy_move_result' => ['score' => 0.15, 'pass' => false, 'flags' => ['Copy-move: 48 cloned keypoints']],
            'flags' => ['Copy-move: 48 cloned keypoints'],
        ]);
    }
}
