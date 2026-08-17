<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\PipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineRun>
 */
class PipelineRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = Document::factory();

        return [
            'document_id' => $document,
            'submission_id' => fn (array $attributes): int => Document::findOrFail($attributes['document_id'])->submission_id,
            'attempt' => 1,
            'status' => PipelineRun::STATUS_COMPLETED,
            'schema_version' => '1.0',
            'settings_snapshot' => [],
            'settings_hash' => hash('sha256', '{}'),
            'models' => [],
            'timings' => ['total_ms' => 1],
            'flags' => [],
            'error' => null,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
