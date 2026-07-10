<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorRepresentative>
 */
class VendorRepresentativeFactory extends Factory
{
    protected $model = VendorRepresentative::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'last_name' => $this->faker->lastName(),
            'suffix' => null,
            'date_of_birth' => $this->faker->dateTimeBetween('-60 years', '-21 years')->format('Y-m-d'),
            'gender' => $this->faker->randomElement(array_keys(VendorRepresentative::GENDERS)),
            'contact_number' => $this->faker->numerify('+63 9## ### ####'),
            'government_id_type' => $this->faker->randomElement(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES)),
            'government_id_number' => $this->faker->numerify('############'),
            'home_address' => $this->faker->address(),
        ];
    }
}
