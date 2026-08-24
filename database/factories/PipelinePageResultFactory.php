<?php

namespace Database\Factories;

use App\Models\PipelinePageResult;
use App\Models\PipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelinePageResult>
 */
class PipelinePageResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $run = PipelineRun::factory();

        return [
            'pipeline_run_id' => $run,
            'document_id' => fn (array $attributes): int => PipelineRun::findOrFail($attributes['pipeline_run_id'])->document_id,
            'submission_id' => fn (array $attributes): int => PipelineRun::findOrFail($attributes['pipeline_run_id'])->submission_id,
            'page_index' => 1,
            'status' => 'completed',
            'stages' => [],
            'flags' => [],
            'timings' => ['total_ms' => 1],
        ];
    }
}
