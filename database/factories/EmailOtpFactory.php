<?php

namespace Database\Factories;

use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailOtp>
 */
class EmailOtpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'challenge_id' => fake()->unique()->regexify('[a-f0-9]{64}'),
            'code' => bcrypt('123456'),
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(EmailOtpService::EXPIRES_IN_MINUTES),
            'sent_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subSecond(),
        ]);
    }
}
