<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'headline' => fake()->jobTitle(),
            'bio' => fake()->sentence(12),
            'location' => fake()->city(),
            'website_url' => null,
            'is_verified_badge' => false,
            'followers_count' => 0,
            'following_count' => 0,
        ];
    }
}
