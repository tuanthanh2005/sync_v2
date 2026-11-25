<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SafeImageUploadService
{
    /**
     * @var array<string, string>
     */
    private const ALLOWED_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5_242_880;

    private const MAX_INPUT_PIXELS = 5000;

    private const TARGET_MAX_DIMENSION = 1600;

    /**
     * Validate actual file magic bytes, strip EXIF/metadata via GD re-encoding, and store on configured disk.
     *
     * @return array{
     *     disk: string,
     *     path: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     size_bytes: int
     * }
     */
    public function storeImage(UploadedFile $file, string $directory = 'posts'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'images' => [__('The uploaded image file is invalid or incomplete.')],
            ]);
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'images' => [__('The uploaded image file could not be read.')],
            ]);
        }

        $rawBytes = file_get_contents($realPath);

        if ($rawBytes === false || $rawBytes === '' || strlen($rawBytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'images' => [__('The image must be a valid file no larger than 5 MB.')],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($rawBytes);

        if (! is_string($detectedMime) || ! array_key_exists($detectedMime, self::ALLOWED_MIME_MAP)) {
            throw ValidationException::withMessages([
                'images' => [__('Only JPEG, PNG, and WebP images are permitted.')],
            ]);
        }

        $imageInfo = @getimagesizefromstring($rawBytes);

        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'images' => [__('The uploaded image is corrupted or unreadable.')],
            ]);
        }

        $srcWidth = (int) $imageInfo[0];
        $srcHeight = (int) $imageInfo[1];
        $imageMime = (string) $imageInfo['mime'];

        if (
            $srcWidth < 1
            || $srcHeight < 1
            || $srcWidth > self::MAX_INPUT_PIXELS
            || $srcHeight > self::MAX_INPUT_PIXELS
            || ! array_key_exists($imageMime, self::ALLOWED_MIME_MAP)
        ) {
            throw ValidationException::withMessages([
                'images' => [__('Image dimensions exceed safe processing limits.')],
            ]);
        }

        $source = @imagecreatefromstring($rawBytes);

        if ($source === false) {
            throw ValidationException::withMessages([
                'images' => [__('The image could not be decoded safely.')],
            ]);
        }

        [$targetWidth, $targetHeight] = $this->calculateTargetDimensions($srcWidth, $srcHeight);

        $canvas = imagecreatetruecolor(max(1, $targetWidth), max(1, $targetHeight));

        if ($canvas === false) {
            imagedestroy($source);

            throw ValidationException::withMessages([
                'images' => [__('Unable to allocate image processing buffer.')],
            ]);
        }

        if ($imageMime === 'image/png' || $imageMime === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

            if ($transparent !== false) {
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
            }
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $srcWidth,
            $srcHeight,
        );

        imagedestroy($source);

        $outputBytes = $this->encodeCleanBuffer($canvas, $imageMime);
        imagedestroy($canvas);

        $disk = (string) config('syncviet.media_disk', 'public');
        $extension = self::ALLOWED_MIME_MAP[$imageMime];
        $cleanDirectory = trim($directory, '/');
        $path = sprintf('%s/%s/%s.%s', $cleanDirectory, now()->format('Y/m'), (string) Str::uuid(), $extension);

        Storage::disk($disk)->put($path, $outputBytes, ['visibility' => 'public']);

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $imageMime,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'size_bytes' => strlen($outputBytes),
        ];
    }

    public function deleteIfExists(?string $disk, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $targetDisk = $disk ?: (string) config('syncviet.media_disk', 'public');
        Storage::disk($targetDisk)->delete($path);
    }

    /**
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    private function calculateTargetDimensions(int $width, int $height): array
    {
        $maxSide = max($width, $height);

        if ($maxSide <= self::TARGET_MAX_DIMENSION) {
            return [max(1, $width), max(1, $height)];
        }

        $ratio = self::TARGET_MAX_DIMENSION / $maxSide;

        return [
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        ];
    }

    private function encodeCleanBuffer(\GdImage $canvas, string $mimeType): string
    {
        ob_start();

        $encoded = match ($mimeType) {
            'image/png' => imagepng($canvas, null, 6),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($canvas, null, 84)
                : imagejpeg($canvas, null, 85),
            default => imagejpeg($canvas, null, 85),
        };

        $contents = ob_get_clean();

        if (! $encoded || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                'images' => [__('Unable to encode processed image.')],
            ]);
        }

        return $contents;
    }
}
