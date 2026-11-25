<?php

namespace Database\Factories;

use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'community_id' => null,
            'category' => fake()->randomElement(PostCategory::cases()),
            'title' => rtrim(fake()->sentence(6), '.'),
            'content' => fake()->paragraphs(2, true),
            'link_url' => null,
            'link_meta' => null,
            'visibility' => PostVisibility::Public,
            'is_pinned' => false,
            'moderation_status' => ModerationStatus::Approved,
            'reactions_count' => 0,
            'comments_count' => 0,
            'shares_count' => 0,
            'published_at' => now(),
        ];
    }
}
