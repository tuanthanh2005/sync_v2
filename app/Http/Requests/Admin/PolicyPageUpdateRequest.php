<?php

namespace App\Http\Requests\Admin;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\PolicyPage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicyPageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policyPage');

        return $policy instanceof PolicyPage && ($this->user()?->can('update', $policy) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title_vi' => ['required', 'string', 'max:160'],
            'title_en' => ['required', 'string', 'max:160'],
            'summary_vi' => ['nullable', 'string', 'max:500'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'content_vi' => ['nullable', 'string', 'max:60000'],
            'content_en' => ['nullable', 'string', 'max:60000'],
            'status' => ['required', 'string', Rule::in(PolicyStatus::values())],
            'is_visible' => ['required', 'boolean'],
            'footer_group' => ['required', 'string', Rule::in(FooterGroup::values())],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
