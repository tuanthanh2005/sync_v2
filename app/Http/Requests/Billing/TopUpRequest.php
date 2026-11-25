<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TopUpRequest extends FormRequest
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
        $minAmount = (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000);
        $maxAmount = (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000);

        return [
            'amount' => ['required', 'integer', "min:{$minAmount}", "max:{$maxAmount}"],
            'provider' => ['nullable', 'string', Rule::in(['sandbox', 'bank_transfer', 'vnpay', 'momo'])],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ];
    }
}
