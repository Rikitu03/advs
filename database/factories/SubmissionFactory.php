<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'status' => Submission::STATUS_PROCESSING,
            'composite_risk_score' => null,
            'risk_level' => null,
        ];
    }
}
