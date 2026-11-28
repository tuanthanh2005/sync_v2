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
            'category' => ['nullable', 'string', Rule::enum(PostCategory::class)],
            'visibility' => ['nullable', 'string', Rule::in([PostVisibility::Public->value, PostVisibility::Community->value, PostVisibility::Private->value])],
            'link_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'link_synced' => ['nullable', 'boolean'],
            'media_synced' => ['nullable', 'boolean'],
            'existing_media' => ['nullable', 'array', 'max:'.$maxImages],
            'existing_media.*.id' => ['required', 'integer', 'min:1'],
            'existing_media.*.alt_text' => ['nullable', 'string', 'max:250'],
            'images' => ['nullable', 'array', 'max:'.$maxImages],
            'images.*' => ['file', new ValidUploadedImage('post')],
            'image_alts' => ['nullable', 'array', 'max:'.$maxImages],
            'image_alts.*' => ['nullable', 'string', 'max:250'],
            'expected_updated_at' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $limits = $user !== null
                    ? app(TrustLevelResolver::class)->limitsFor($user)
                    : null;
                $maxImages = $limits !== null
                    ? max(1, min(10, (int) $limits['max_images_per_post']))
                    : max(1, min(10, (int) app(SystemSettingService::class)->get('content_post_max_images', 4)));

                $existingMedia = $this->input('existing_media', []);
                $existingCount = is_array($existingMedia) ? count($existingMedia) : 0;
                $images = $this->file('images');
                $newCount = is_array($images) ? count($images) : 0;

                if ($existingCount + $newCount > $maxImages) {
                    $validator->errors()->add('images', __('You have exceeded the maximum number of :max images allowed.', ['max' => $maxImages]));

                    return;
                }

                if (! is_array($images) || $images === []) {
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
