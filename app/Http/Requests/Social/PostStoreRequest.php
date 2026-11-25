<?php

namespace App\Http\Requests\Social;

use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostStoreRequest extends FormRequest
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
        return [
            'title' => ['nullable', 'string', 'max:180'],
            'content' => ['required', 'string', 'min:1', 'max:10000'],
            'community_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', Rule::enum(PostCategory::class)],
            'visibility' => ['nullable', 'string', Rule::in([PostVisibility::Public->value, PostVisibility::Community->value, PostVisibility::Private->value])],
            'link_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'images' => ['nullable', 'array', 'max:4'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'poll_question' => ['nullable', 'string', 'max:255', 'required_with:poll_options'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:5', 'required_with:poll_question'],
            'poll_options.*' => ['required', 'string', 'min:1', 'max:120'],
            'poll_duration_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }
}
