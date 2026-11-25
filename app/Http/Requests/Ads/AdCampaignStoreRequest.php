<?php

namespace App\Http\Requests\Ads;

use App\Enums\AdBudgetType;
use App\Enums\AdObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdCampaignStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minDaily = (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000);
        $maxDaily = (int) config('syncviet.ad_pricing.max_daily_budget_vnd', 10_000_000);
        $minTotal = (int) config('syncviet.ad_pricing.min_total_budget_vnd', 100_000);
        $maxTotal = (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000);

        return [
            'post_id' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:140'],
            'objective' => ['required', 'string', Rule::enum(AdObjective::class)],
            'target_topics' => ['nullable', 'array', 'max:5'],
            'target_topics.*' => ['required', 'string', 'min:1', 'max:40'],
            'target_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'schedule_mode' => ['required', 'string', Rule::in(['immediate', 'scheduled'])],
            'starts_at' => ['nullable', 'date', 'required_if:schedule_mode,scheduled'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'budget_type' => ['required', 'string', Rule::enum(AdBudgetType::class)],
            'daily_budget' => ['nullable', 'integer', "min:{$minDaily}", "max:{$maxDaily}", 'required_if:budget_type,daily'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'total_budget' => ['nullable', 'integer', "min:{$minTotal}", "max:{$maxTotal}", 'required_if:budget_type,total'],
        ];
    }
}
