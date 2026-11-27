<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'hashtag' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', Rule::in(['all', 'posts', 'users', 'communities', 'topics'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
