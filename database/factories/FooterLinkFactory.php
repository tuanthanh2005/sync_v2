<?php

namespace Database\Factories;

use App\Enums\FooterGroup;
use App\Models\FooterLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FooterLink>
 */
class FooterLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = rtrim(fake()->sentence(2), '.');

        return [
            'policy_page_id' => null,
            'label_vi' => $label,
            'label_en' => $label,
            'route_name' => null,
            'url' => '/',
            'footer_group' => FooterGroup::Support,
            'sort_order' => 10,
            'is_visible' => true,
            'open_in_new_tab' => false,
        ];
    }
}
