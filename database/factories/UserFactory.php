<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_VENDOR,
            // Like email_verified_at, factory users default to having completed
            // onboarding (signature enrollment); use unenrolled() to test the gate.
            'signature_enrolled_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the vendor has not completed signature enrollment.
     */
    public function unenrolled(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_path' => null,
            'signature_enrolled_at' => null,
        ]);
    }

    /**
     * Indicate that the user should have the given role.
     */
    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
