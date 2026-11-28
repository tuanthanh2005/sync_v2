<?php

namespace App\Http\Requests\Social;

use App\Enums\PostCategory;
use App\Services\Social\TrustLevelResolver;
use App\Services\System\SystemSettingService;
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
        $user = $this->user();
        $maxLength = $user !== null
            ? max(100, min(10_000, (int) app(TrustLevelResolver::class)->limitsFor($user)['max_post_length']))
            : max(100, min(10_000, (int) app(SystemSettingService::class)->get('content_post_max_length', 3000)));

        return [
            'title' => ['nullable', 'string', 'max:180'],
            'content' => ['required', 'string', 'min:1', 'max:'.$maxLength],
            'category' => ['nullable', 'string', Rule::enum(PostCategory::class)],
        ];
    }
}
