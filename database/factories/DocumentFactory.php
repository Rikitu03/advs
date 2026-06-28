<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Submission;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vendor = Vendor::factory();

        return [
            'submission_id' => Submission::factory()->for($vendor),
            'vendor_id' => $vendor,
            'document_type_id' => null,
            'original_filename' => $this->faker->word().'.jpg',
            'file_path' => 'documents/'.$this->faker->uuid().'.jpg',
            'converted_image_path' => null,
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => $this->faker->numberBetween(50_000, 5_000_000),
            'page_number' => 1,
            'processing_status' => Document::STATUS_QUEUED,
        ];
    }
}
