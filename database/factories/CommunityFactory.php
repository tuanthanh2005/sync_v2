<?php

namespace Database\Factories;

use App\Enums\PostCategory;
use App\Models\Community;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = rtrim(fake()->unique()->sentence(2), '.');

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999),
            'category' => fake()->randomElement(PostCategory::cases()),
            'description' => fake()->sentence(12),
            'rules' => [
                'Share helpful, constructive insights relevant to community topics.',
                'Respect fellow members and avoid spam or misleading promotions.',
            ],
            'visibility' => 'public',
            'is_verified_badge' => false,
            'members_count' => 1,
            'posts_count' => 0,
        ];
    }
}
