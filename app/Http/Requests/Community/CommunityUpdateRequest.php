<?php

namespace App\Http\Requests\Community;

use App\Enums\PostCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommunityUpdateRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'category' => ['required', 'string', Rule::enum(PostCategory::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'rules' => ['nullable', 'array', 'max:10'],
            'rules.*' => ['nullable', 'string', 'max:240'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
