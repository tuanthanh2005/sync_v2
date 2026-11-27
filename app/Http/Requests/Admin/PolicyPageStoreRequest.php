<?php

namespace App\Http\Requests\Admin;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\PolicyPage;
use App\Rules\ValidUploadedImage;
use App\Services\Policy\PolicyManagementService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicyPageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PolicyPage::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:policy_pages,slug'],
            'page_type' => ['required', 'string', Rule::in(PolicyManagementService::PAGE_TYPES)],
            'title_vi' => ['required', 'string', 'max:160'],
            'title_en' => ['required', 'string', 'max:160'],
            'summary_vi' => ['nullable', 'string', 'max:500'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'content_vi' => ['nullable', 'string', 'max:120000'],
            'content_en' => ['nullable', 'string', 'max:120000'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'og_image' => ['nullable', 'file', new ValidUploadedImage('og_image')],
            'index_eligible' => ['sometimes', 'boolean'],
            'status' => ['required', 'string', Rule::in(PolicyStatus::values())],
            'is_visible' => ['required', 'boolean'],
            'footer_group' => ['required', 'string', Rule::in(FooterGroup::values())],
            'placement' => ['sometimes', 'string', Rule::in(['main', 'more'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
