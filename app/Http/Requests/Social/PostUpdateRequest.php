<?php

namespace App\Http\Requests\Social;

use App\Enums\PostCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostUpdateRequest extends FormRequest
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
            'category' => ['nullable', 'string', Rule::enum(PostCategory::class)],
        ];
    }
}
