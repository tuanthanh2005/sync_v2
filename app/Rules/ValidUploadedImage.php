<?php

namespace App\Rules;

use App\Services\Media\SafeImageUploadService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidUploadedImage implements ValidationRule
{
    public function __construct(
        private readonly string $context = 'post',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail(__('Please select a valid image file from your device.'));

            return;
        }

        $service = app(SafeImageUploadService::class);
        $error = $service->validateUploadedFile($value, $this->context);

        if ($error !== null) {
            $fail($error);
        }
    }
}
