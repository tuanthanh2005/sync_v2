<?php

namespace App\Http\Requests\Social;

use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Rules\ValidUploadedImage;
use App\Services\Media\SafeImageUploadService;
use App\Services\Social\TrustLevelResolver;
use App\Services\System\SystemSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $user = $this->user();
        $limits = $user !== null
            ? app(TrustLevelResolver::class)->limitsFor($user)
            : null;
        $maxImages = $limits !== null
            ? max(1, min(10, (int) $limits['max_images_per_post']))
            : max(1, min(10, (int) app(SystemSettingService::class)->get('content_post_max_images', 4)));
        $maxLength = $limits !== null
            ? max(100, min(10_000, (int) $limits['max_post_length']))
            : max(100, min(10_000, (int) app(SystemSettingService::class)->get('content_post_max_length', 3000)));

        return [
            'title' => ['nullable', 'string', 'max:180'],
            'content' => ['required', 'string', 'min:1', 'max:'.$maxLength],
            'community_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', Rule::enum(PostCategory::class)],
            'visibility' => ['nullable', 'string', Rule::in([PostVisibility::Public->value, PostVisibility::Community->value, PostVisibility::Private->value])],
            'link_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'images' => ['nullable', 'array', 'max:'.$maxImages],
            'images.*' => ['file', new ValidUploadedImage('post')],
            'image_alts' => ['nullable', 'array', 'max:'.$maxImages],
            'image_alts.*' => ['nullable', 'string', 'max:250'],
            'poll_question' => ['nullable', 'string', 'max:255', 'required_with:poll_options'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:5', 'required_with:poll_question'],
            'poll_options.*' => ['required', 'string', 'min:1', 'max:120'],
            'poll_duration_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $images = $this->file('images');
                if (! is_array($images) || $images === []) {
                    return;
                }

                $user = $this->user();
                $limits = $user !== null
                    ? app(TrustLevelResolver::class)->limitsFor($user)
                    : null;
                $maxImages = $limits !== null
                    ? max(1, min(10, (int) $limits['max_images_per_post']))
                    : max(1, min(10, (int) app(SystemSettingService::class)->get('content_post_max_images', 4)));
                if (count($images) > $maxImages) {
                    $validator->errors()->add('images', __('You have exceeded the maximum number of :max images allowed.', ['max' => $maxImages]));

                    return;
                }

                $totalBytes = 0;
                foreach ($images as $file) {
                    if ($file->isValid()) {
                        $size = $file->getSize();
                        if (is_int($size) && $size > 0) {
                            $totalBytes += $size;
                        }
                    }
                }

                $maxTotalBytes = (int) config('syncviet.uploads.max_post_request_bytes', SafeImageUploadService::MAX_POST_REQUEST_BYTES);
                if ($totalBytes >= $maxTotalBytes) {
                    $validator->errors()->add('images', __('Total uploaded image size exceeds the :max MB request limit.', [
                        'max' => (int) round($maxTotalBytes / 1_000_000),
                    ]));
                }
            },
        ];
    }
}
