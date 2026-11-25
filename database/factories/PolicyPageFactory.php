<?php

namespace Database\Factories;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\PolicyPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PolicyPage>
 */
class PolicyPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(3), '.');

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(100, 999),
            'title_vi' => $title,
            'title_en' => $title,
            'summary_vi' => fake()->sentence(),
            'summary_en' => fake()->sentence(),
            'content_vi' => '<p>'.fake()->paragraph().'</p>',
            'content_en' => '<p>'.fake()->paragraph().'</p>',
            'status' => PolicyStatus::Draft,
            'is_visible' => false,
            'footer_group' => FooterGroup::Legal,
            'sort_order' => 10,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PolicyStatus::Published,
            'is_visible' => true,
            'published_at' => now(),
        ]);
    }
}
