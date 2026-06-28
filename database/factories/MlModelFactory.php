<?php

namespace Database\Factories;

use App\Models\MlModel;
use App\Models\User;
use Database\Seeders\MlModelSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlModel>
 */
class MlModelFactory extends Factory
{
    protected $model = MlModel::class;

    /**
     * Canonical ADVS model identities (name → purpose) so test fixtures
     * line up with the seeder shipped in {@see MlModelSeeder}.
     *
     * @var array<string, string>
     */
    public const FIXTURES = [
        'Document Classifier' => 'classification',
        'Signature Detector' => 'detection',
        'Signature Verifier' => 'signature',
        'Stamp Verifier' => 'stamp_logo',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->randomElement(array_keys(self::FIXTURES));

        return [
            // Use `uniqid` so concurrent factories don't collide on the unique
            // `name` column even though they share a fixture base name.
            'name' => $name.' test '.$this->faker->unique()->bothify('##??##'),
            'purpose' => self::FIXTURES[$name],
            'version' => '1.0.'.$this->faker->numberBetween(0, 9),
            'status' => 'standby',
            'storage_path' => 'python/models/test-'.$this->faker->word().'.h5',
            'file_size_bytes' => $this->faker->numberBetween(1_000_000, 250_000_000),
            'checksum_sha256' => null,
            'notes' => $this->faker->sentence(),
            'metrics' => ['top1_accuracy' => round($this->faker->randomFloat(3, 0.7, 0.99), 3)],
            'last_trained_at' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
            'last_synced_at' => now(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * Convenience state: a row in `missing` state (file not on disk).
     */
    public function missing(): static
    {
        return $this->state(fn (): array => [
            'status' => 'missing',
            'storage_path' => 'python/models/does-not-exist-'.$this->faker->word().'.h5',
            'file_size_bytes' => null,
            'last_trained_at' => null,
        ]);
    }

    /**
     * Convenience state: a row in `active` state.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => 'active',
        ]);
    }
}
