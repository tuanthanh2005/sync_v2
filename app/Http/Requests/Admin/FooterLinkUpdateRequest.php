<?php

namespace App\Http\Requests\Admin;

use App\Enums\FooterGroup;
use App\Models\PolicyPage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FooterLinkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PolicyPage::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label_vi' => ['required', 'string', 'max:120'],
            'label_en' => ['required', 'string', 'max:120'],
            'translations' => ['nullable', 'array'],
            'translations.*' => ['nullable', 'string', 'max:120'],
            'url' => [
                'nullable',
                'string',
                'max:500',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    $trimmed = trim($value);
                    $isRelative = str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '//');
                    $scheme = parse_url($trimmed, PHP_URL_SCHEME);
                    $isHttp = is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);

                    if (! $isRelative && ! $isHttp) {
                        $fail(__('URL must be an internal path starting with / or a valid http/https link.'));
                    }
                },
            ],
            'footer_group' => ['required', 'string', Rule::in(FooterGroup::values())],
            'placement' => ['sometimes', 'string', Rule::in(['main', 'more'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['required', 'boolean'],
            'open_in_new_tab' => ['sometimes', 'boolean'],
        ];
    }
}
