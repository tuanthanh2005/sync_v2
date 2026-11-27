<?php

namespace App\Http\Requests\Community;

use App\Enums\PostCategory;
use App\Rules\ValidUploadedImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommunityStoreRequest extends FormRequest
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
            'visibility' => ['nullable', 'string', Rule::in(['public', 'private'])],
            'rules' => ['nullable', 'array', 'max:10'],
            'rules.*' => ['nullable', 'string', 'max:240'],
            'avatar' => ['nullable', 'file', new ValidUploadedImage('community_avatar')],
            'cover' => ['nullable', 'file', new ValidUploadedImage('community_cover')],
        ];
    }
}
