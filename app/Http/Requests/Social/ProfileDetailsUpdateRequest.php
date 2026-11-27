<?php

namespace App\Http\Requests\Social;

use App\Rules\ValidUploadedImage;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDetailsUpdateRequest extends FormRequest
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
            'headline' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:100'],
            'website_url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'avatar' => ['nullable', 'file', new ValidUploadedImage('avatar')],
            'cover' => ['nullable', 'file', new ValidUploadedImage('cover')],
            'remove_avatar' => ['sometimes', 'boolean'],
            'remove_cover' => ['sometimes', 'boolean'],
        ];
    }
}
