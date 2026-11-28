<?php

namespace App\Services\Media;

use App\Services\System\SystemSettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SafeImageUploadService
{
    /**
     * Global maximum file size in decimal megabytes: strictly < 40,000,000 bytes.
     * Files with size >= 40,000,000 bytes are always rejected.
     */
    public const MAX_IMAGE_BYTES = 40_000_000;

    public const MAX_POST_REQUEST_BYTES = 80_000_000;

    /**
     * @var array<string, string>
     */
    private const ALLOWED_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Normalized MIME aliases emitted across different OS/host libmagic databases.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME_ALIASES = [
        'image/jpeg' => 'image/jpeg',
        'image/jpg' => 'image/jpeg',
        'image/jpe' => 'image/jpeg',
        'image/pjpe' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/jfif' => 'image/jpeg',
        'image/x-citrix-jpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/x-png' => 'image/png',
        'image/apng' => 'image/png',
        'image/vnd.mozilla.apng' => 'image/png',
        'image/x-citrix-png' => 'image/png',
        'image/webp' => 'image/webp',
        'image/x-webp' => 'image/webp',
    ];

    /**
     * Generic MIME strings returned by some hosts when finfo cannot classify binary temp files.
     * Final authorization still requires matching magic bytes, getimagesize(), and GD re-encoding.
     *
     * @var list<string>
     */
    private const GENERIC_BINARY_MIMES = [
        'application/octet-stream',
        'binary/octet-stream',
        'application/unknown',
        'application/x-unknown',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @var list<string>
     */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv', 'wmv', 'flv', 'mpeg', 'mpg', '3gp', 'ogv'];

    private const MAX_INPUT_PIXELS = 8000;

    private const MAX_TOTAL_PIXELS = 25_000_000;

    private const DEFAULT_TARGET_MAX_DIMENSION = 1920;

    private const THUMBNAIL_MAX_DIMENSION = 600;

    /**
     * Resolve the active maximum allowed bytes for a given upload context.
     * Admin may configure a smaller limit per context, but can NEVER exceed 40,000,000 bytes.
     */
    public function maxBytesForContext(string $context = 'post'): int
    {
        $hardCeiling = min(self::MAX_IMAGE_BYTES, (int) config('syncviet.uploads.max_image_bytes', self::MAX_IMAGE_BYTES));
        $contexts = (array) config('syncviet.uploads.contexts', []);
        $contextCfg = is_array($contexts[$context] ?? null) ? $contexts[$context] : [];

        $contextCeiling = isset($contextCfg['max_bytes']) ? min($hardCeiling, (int) $contextCfg['max_bytes']) : $hardCeiling;

        try {
            $settings = app(SystemSettingService::class);
            $globalMb = (int) $settings->get('content_upload_max_mb', 40);
            $settingKey = is_string($contextCfg['setting_key'] ?? null) ? $contextCfg['setting_key'] : null;
            $contextMb = $settingKey !== null ? (int) $settings->get($settingKey, $globalMb) : $globalMb;

            $configuredMb = max(1, min(40, min($globalMb, $contextMb)));
            $configuredBytes = $configuredMb * 1_000_000;

            return min($contextCeiling, $configuredBytes);
        } catch (\Throwable) {
            return $contextCeiling;
        }
    }

    public function maxDimensionForContext(string $context = 'post'): int
    {
        $contexts = (array) config('syncviet.uploads.contexts', []);
        $contextCfg = is_array($contexts[$context] ?? null) ? $contexts[$context] : [];

        return max(128, min(2400, (int) ($contextCfg['max_dimension'] ?? self::DEFAULT_TARGET_MAX_DIMENSION)));
    }

    /**
     * Inspect live PHP runtime limits honestly so Admin & frontend never advertise an impossible limit.
     *
     * @return array{
     *     policy_max_bytes: int,
     *     policy_max_mb: int,
     *     max_post_request_bytes: int,
     *     php_upload_max_raw: string,
     *     php_upload_max_bytes: int,
     *     php_post_max_raw: string,
     *     php_post_max_bytes: int,
     *     php_memory_limit_raw: string,
     *     php_memory_limit_bytes: int,
     *     effective_max_bytes: int,
     *     effective_max_mb: int,
     *     infrastructure_ready: bool,
     *     gd_available: bool,
     *     webp_available: bool,
     *     exif_available: bool
     * }
     */
    public function runtimeLimits(): array
    {
        $policyMaxBytes = $this->maxBytesForContext('post');
        $uploadRaw = (string) ini_get('upload_max_filesize');
        $postRaw = (string) ini_get('post_max_size');
        $memoryRaw = (string) ini_get('memory_limit');

        $phpUploadBytes = self::parseIniBytes($uploadRaw);
        $phpPostBytes = self::parseIniBytes($postRaw);
        $phpMemoryBytes = self::parseIniBytes($memoryRaw);

        $serverCap = $policyMaxBytes;
        if ($phpUploadBytes > 0) {
            $serverCap = min($serverCap, $phpUploadBytes);
        }
        if ($phpPostBytes > 0) {
            $serverCap = min($serverCap, $phpPostBytes);
        }

        $effectiveMaxBytes = max(1_000_000, min($policyMaxBytes, $serverCap));
        $infrastructureReady = ($phpUploadBytes <= 0 || $phpUploadBytes >= $policyMaxBytes)
            && ($phpPostBytes <= 0 || $phpPostBytes >= $policyMaxBytes);

        return [
            'policy_max_bytes' => $policyMaxBytes,
            'policy_max_mb' => (int) round($policyMaxBytes / 1_000_000),
            'max_post_request_bytes' => (int) config('syncviet.uploads.max_post_request_bytes', self::MAX_POST_REQUEST_BYTES),
            'php_upload_max_raw' => $uploadRaw !== '' ? $uploadRaw : 'unknown',
            'php_upload_max_bytes' => $phpUploadBytes,
            'php_post_max_raw' => $postRaw !== '' ? $postRaw : 'unknown',
            'php_post_max_bytes' => $phpPostBytes,
            'php_memory_limit_raw' => $memoryRaw !== '' ? $memoryRaw : 'unknown',
            'php_memory_limit_bytes' => $phpMemoryBytes,
            'effective_max_bytes' => $effectiveMaxBytes,
            'effective_max_mb' => max(1, (int) floor($effectiveMaxBytes / 1_000_000)),
            'infrastructure_ready' => $infrastructureReady,
            'gd_available' => extension_loaded('gd'),
            'webp_available' => function_exists('imagewebp'),
            'exif_available' => function_exists('exif_read_data'),
        ];
    }

    /**
     * Validate an uploaded file without loading the entire payload into RAM.
     * Returns a localized user-friendly error message or null when valid.
     */
    public function validateUploadedFile(UploadedFile $file, string $context = 'post'): ?string
    {
        $maxBytes = $this->maxBytesForContext($context);
        $maxMb = (int) round($maxBytes / 1_000_000);

        if (! $file->isValid()) {
            $errorCode = $file->getError();

            if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
                return __('Image file size must be smaller than :max MB.', ['max' => $maxMb]);
            }

            return __('Unable to upload the image. Please try again.');
        }

        $clientExtension = strtolower(trim($file->getClientOriginalExtension()));
        $clientMime = strtolower(trim((string) $file->getClientMimeType()));

        if (str_starts_with($clientMime, 'video/') || in_array($clientExtension, self::VIDEO_EXTENSIONS, true)) {
            return __('Video posting is currently under development. Please upload a JPG, PNG, or WebP image instead.');
        }

        if ($clientExtension !== '' && ! in_array($clientExtension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->rejectUnsupportedFormat('extension', $file, $context);
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            return __('Unable to upload the image. Please try again.');
        }

        $fileSize = @filesize($realPath);
        if (! is_int($fileSize) || $fileSize <= 0) {
            return __('The uploaded image file is empty or unreadable.');
        }

        // Strict `<` boundary: 40 MB = 40,000,000 bytes; files >= maxBytes are rejected.
        if ($fileSize >= $maxBytes) {
            $actualMb = round($fileSize / 1_000_000, 1);

            return __('The selected file is :size MB. Please choose an image smaller than :max MB.', [
                'size' => $actualMb,
                'max' => $maxMb,
            ]);
        }

        // Inspect leading file bytes (max 512 bytes) for magic signature and active script payloads.
        $handle = @fopen($realPath, 'rb');
        if ($handle === false) {
            return __('Unable to upload the image. Please try again.');
        }

        $header = (string) fread($handle, 512);
        fclose($handle);

        if ($header === '') {
            return __('The uploaded image file is empty or unreadable.');
        }

        $signatureMime = $this->detectMagicSignatureMime($header);

        if ($this->containsDisguisedExecutableOrSvg($header, $signatureMime)) {
            return $this->rejectUnsupportedFormat('disguised_payload', $file, $context, $header, $signatureMime);
        }

        $rawFinfoMime = $this->detectFinfoMimeType($realPath);

        if ($signatureMime === null) {
            if ($rawFinfoMime !== null && str_starts_with($rawFinfoMime, 'video/')) {
                return __('Video posting is currently under development. Please upload a JPG, PNG, or WebP image instead.');
            }

            return $this->rejectUnsupportedFormat('magic_signature', $file, $context, $header, null, $rawFinfoMime);
        }

        $finfoError = $this->validateDetectedFinfoMime($rawFinfoMime, $signatureMime);
        if ($finfoError !== null) {
            if (str_contains($finfoError, 'Unsupported image format') || $finfoError === __('Unsupported image format. Only JPG, PNG, and WebP images are allowed.')) {
                return $this->rejectUnsupportedFormat('finfo_mismatch', $file, $context, $header, $signatureMime, $rawFinfoMime);
            }

            return $finfoError;
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            return $this->rejectCorruptedImage('getimagesize_false', $file, $context, $header, $signatureMime, $rawFinfoMime);
        }

        $srcWidth = (int) $imageInfo[0];
        $srcHeight = (int) $imageInfo[1];
        $imageMime = $this->resolveImageInfoMime($imageInfo);

        if ($imageMime === null || $imageMime !== $signatureMime) {
            return $this->rejectUnsupportedFormat(
                'getimagesize_mismatch',
                $file,
                $context,
                $header,
                $signatureMime,
                $rawFinfoMime,
                $imageMime,
            );
        }

        $maxDim = (int) config('syncviet.uploads.max_input_dimension', self::MAX_INPUT_PIXELS);
        $maxPixels = (int) config('syncviet.uploads.max_total_pixels', self::MAX_TOTAL_PIXELS);

        if (
            $srcWidth < 1
            || $srcHeight < 1
            || $srcWidth > $maxDim
            || $srcHeight > $maxDim
            || ($srcWidth * $srcHeight) > $maxPixels
        ) {
            return __('Image dimensions exceed safe processing limits.');
        }

        return null;
    }

    /**
     * Validate actual file signature, fix JPEG orientation, strip EXIF/GPS via GD re-encoding,
     * resize/compress cleanly (converting to WebP when supported), and store on configured disk.
     *
     * @return array{
     *     disk: string,
     *     path: string,
     *     thumbnail_path: string|null,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     size_bytes: int
     * }
     */
    public function storeImage(
        UploadedFile $file,
        string $directory = 'posts',
        string $context = 'post',
        string $errorField = 'images',
        bool $generateThumbnail = false,
    ): array {
        $validationError = $this->validateUploadedFile($file, $context);
        if ($validationError !== null) {
            throw ValidationException::withMessages([
                $errorField => [$validationError],
            ]);
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                $errorField => [__('Unable to upload the image. Please try again.')],
            ]);
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                $errorField => [__('The uploaded image is corrupted or unreadable.')],
            ]);
        }

        $imageMime = $this->resolveImageInfoMime($imageInfo);
        if ($imageMime === null) {
            throw ValidationException::withMessages([
                $errorField => [__('Unsupported image format. Only JPG, PNG, and WebP images are allowed.')],
            ]);
        }

        $source = $this->decodeFromFile($realPath, $imageMime);

        if ($source === false) {
            throw ValidationException::withMessages([
                $errorField => [__('The image could not be decoded safely.')],
            ]);
        }

        $canvas = null;
        $thumbCanvas = null;

        try {
            if ($imageMime === 'image/jpeg') {
                $source = $this->normalizeJpegOrientation($source, $realPath);
            }

            $srcWidth = imagesx($source);
            $srcHeight = imagesy($source);
            $maxTargetDim = $this->maxDimensionForContext($context);

            [$targetWidth, $targetHeight] = $this->calculateTargetDimensions($srcWidth, $srcHeight, $maxTargetDim);

            $preferWebp = (bool) config('syncviet.uploads.convert_to_webp', true) && function_exists('imagewebp');
            $outputMime = $preferWebp ? 'image/webp' : $imageMime;

            $canvas = $this->resampleImage($source, $srcWidth, $srcHeight, $targetWidth, $targetHeight, $outputMime, $errorField);
            $outputBytes = $this->encodeCleanBuffer($canvas, $outputMime, $errorField);

            $disk = (string) config('syncviet.media_disk', 'public');
            $extension = self::ALLOWED_MIME_MAP[$outputMime] ?? 'webp';
            $cleanDirectory = trim($directory, '/');
            $datePrefix = now()->format('Y/m');
            $uuid = (string) Str::uuid();
            $path = sprintf('%s/%s/%s.%s', $cleanDirectory, $datePrefix, $uuid, $extension);

            Storage::disk($disk)->put($path, $outputBytes, ['visibility' => 'public']);

            $thumbnailPath = null;
            if ($generateThumbnail && max($targetWidth, $targetHeight) > self::THUMBNAIL_MAX_DIMENSION) {
                [$thumbW, $thumbH] = $this->calculateTargetDimensions($targetWidth, $targetHeight, self::THUMBNAIL_MAX_DIMENSION);
                $thumbCanvas = $this->resampleImage($canvas, $targetWidth, $targetHeight, $thumbW, $thumbH, $outputMime, $errorField);
                $thumbBytes = $this->encodeCleanBuffer($thumbCanvas, $outputMime, $errorField);
                $thumbnailPath = sprintf('%s/%s/thumbs/%s.%s', $cleanDirectory, $datePrefix, $uuid, $extension);
                Storage::disk($disk)->put($thumbnailPath, $thumbBytes, ['visibility' => 'public']);
            }

            return [
                'disk' => $disk,
                'path' => $path,
                'thumbnail_path' => $thumbnailPath,
                'mime_type' => $outputMime,
                'width' => $targetWidth,
                'height' => $targetHeight,
                'size_bytes' => strlen($outputBytes),
            ];
        } finally {
            imagedestroy($source);
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
            if ($thumbCanvas instanceof \GdImage) {
                imagedestroy($thumbCanvas);
            }
        }
    }

    public function deleteIfExists(?string $disk, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $targetDisk = $disk ?: (string) config('syncviet.media_disk', 'public');
        Storage::disk($targetDisk)->delete($path);
    }

    public static function parseIniBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-1') {
            return 0;
        }

        $unit = strtolower(substr($trimmed, -1));
        $number = (float) $trimmed;

        return match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round($number),
        };
    }

    protected function detectFinfoMimeType(string $realPath): ?string
    {
        if (! class_exists(\finfo::class)) {
            return null;
        }

        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = @$finfo->file($realPath);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($detected)) {
            return null;
        }

        // Strip optional MIME parameters such as "; charset=binary".
        $baseMime = strtolower(trim(explode(';', $detected, 2)[0]));

        return $baseMime !== '' ? $baseMime : null;
    }

    private function validateDetectedFinfoMime(?string $rawFinfoMime, string $signatureMime): ?string
    {
        if ($rawFinfoMime === null) {
            return null;
        }

        $normalizedFinfoMime = $this->normalizeImageMime($rawFinfoMime);
        if ($normalizedFinfoMime !== null) {
            if ($normalizedFinfoMime !== $signatureMime) {
                return __('Unsupported image format. Only JPG, PNG, and WebP images are allowed.');
            }

            return null;
        }

        $isRiffAliasForWebp = $signatureMime === 'image/webp'
            && in_array($rawFinfoMime, ['audio/x-riff', 'application/x-riff', 'video/x-msvideo', 'image/riff', 'image/x-riff'], true);

        if ($isRiffAliasForWebp || in_array($rawFinfoMime, self::GENERIC_BINARY_MIMES, true)) {
            return null;
        }

        if (str_starts_with($rawFinfoMime, 'video/')) {
            return __('Video posting is currently under development. Please upload a JPG, PNG, or WebP image instead.');
        }

        $isDisallowedConcreteMime = str_starts_with($rawFinfoMime, 'image/')
            || str_contains($rawFinfoMime, 'php')
            || str_contains($rawFinfoMime, 'html')
            || str_contains($rawFinfoMime, 'xml')
            || str_contains($rawFinfoMime, 'javascript')
            || in_array($rawFinfoMime, [
                'application/pdf',
                'application/zip',
                'application/x-gzip',
                'application/x-tar',
                'application/x-executable',
                'application/x-sharedlib',
            ], true);

        if (! $isDisallowedConcreteMime) {
            return null;
        }

        return __('Unsupported image format. Only JPG, PNG, and WebP images are allowed.');
    }

    private function rejectUnsupportedFormat(
        string $reason,
        UploadedFile $file,
        string $context,
        string $header = '',
        ?string $signatureMime = null,
        ?string $finfoMime = null,
        ?string $imageInfoMime = null,
    ): string {
        $this->logUploadRejection($reason, $file, $context, $header, $signatureMime, $finfoMime, $imageInfoMime);

        return __('Unsupported image format. Only JPG, PNG, and WebP images are allowed.');
    }

    private function rejectCorruptedImage(
        string $reason,
        UploadedFile $file,
        string $context,
        string $header = '',
        ?string $signatureMime = null,
        ?string $finfoMime = null,
    ): string {
        $this->logUploadRejection($reason, $file, $context, $header, $signatureMime, $finfoMime, null);

        return __('The uploaded image is corrupted or unreadable.');
    }

    private function logUploadRejection(
        string $reason,
        UploadedFile $file,
        string $context,
        string $header,
        ?string $signatureMime,
        ?string $finfoMime,
        ?string $imageInfoMime,
    ): void {
        try {
            $realPath = $file->getRealPath();
            $phpMime = is_string($realPath) && $realPath !== '' && is_file($realPath)
                ? @mime_content_type($realPath)
                : null;

            Log::error('Image upload rejected by SafeImageUploadService', [
                'reason' => $reason,
                'context' => $context,
                'sapi' => PHP_SAPI,
                'is_valid' => $file->isValid(),
                'upload_error' => $file->getError(),
                'client_extension' => strtolower(trim($file->getClientOriginalExtension())),
                'client_mime' => strtolower(trim((string) $file->getClientMimeType())),
                'size_bytes' => $file->getSize(),
                'signature_mime' => $signatureMime,
                'finfo_mime' => $finfoMime,
                'php_mime' => is_string($phpMime) ? $phpMime : null,
                'imageinfo_mime' => $imageInfoMime,
                'header_hex' => $header !== '' ? bin2hex(substr($header, 0, 16)) : '',
            ]);
        } catch (\Throwable) {
            // Do not mask validation errors if logging fails.
        }
    }

    private function normalizeImageMime(?string $mime): ?string
    {
        if (! is_string($mime) || trim($mime) === '') {
            return null;
        }

        $baseMime = strtolower(trim(explode(';', $mime, 2)[0]));

        return self::ALLOWED_MIME_ALIASES[$baseMime] ?? null;
    }

    /**
     * Resolve canonical allowed image MIME type from PHP's native getimagesize() output.
     *
     * @param  array<int|string, mixed>  $imageInfo
     */
    private function resolveImageInfoMime(array $imageInfo): ?string
    {
        $imageType = isset($imageInfo[2]) ? (int) $imageInfo[2] : 0;

        $byType = match ($imageType) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };

        if ($byType !== null) {
            return $byType;
        }

        $rawMime = isset($imageInfo['mime']) && is_string($imageInfo['mime'])
            ? $imageInfo['mime']
            : null;

        return $this->normalizeImageMime($rawMime);
    }

    private function detectMagicSignatureMime(string $header): ?string
    {
        if (strlen($header) >= 3 && substr($header, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }

        if (strlen($header) >= 8 && substr($header, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'image/png';
        }

        if (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    private function containsDisguisedExecutableOrSvg(string $header, ?string $signatureMime = null): bool
    {
        $lower = strtolower($header);

        if (
            str_contains($lower, '<?php')
            || str_contains($lower, '<script')
            || str_contains($lower, '<!doctype html')
        ) {
            return true;
        }

        // Authentic raster images (JPEG/PNG/WebP magic header) may carry <svg> / XML inside
        // C2PA Content Credentials (e.g. Gemini caBX c2pa.icon) or XMP metadata; GD re-encoding
        // strips all ancillary chunks cleanly while disguised SVG/XML files lack raster signatures.
        if ($signatureMime !== null) {
            return false;
        }

        return str_contains($lower, '<svg')
            || str_starts_with(ltrim($lower), '<?xml');
    }

    private function decodeFromFile(string $realPath, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($realPath),
            'image/png' => @imagecreatefrompng($realPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($realPath) : false,
            default => false,
        };
    }

    private function normalizeJpegOrientation(\GdImage $image, string $realPath): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($realPath);
        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];
        $rotated = match ($orientation) {
            3 => @imagerotate($image, 180, 0),
            6 => @imagerotate($image, -90, 0),
            8 => @imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * @param  int<1, max>  $targetWidth
     * @param  int<1, max>  $targetHeight
     */
    private function resampleImage(
        \GdImage $source,
        int $srcWidth,
        int $srcHeight,
        int $targetWidth,
        int $targetHeight,
        string $outputMime,
        string $errorField,
    ): \GdImage {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            throw ValidationException::withMessages([
                $errorField => [__('Unable to allocate image processing buffer.')],
            ]);
        }

        if ($outputMime === 'image/png' || $outputMime === 'image/webp') {
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

        return $canvas;
    }

    /**
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    private function calculateTargetDimensions(int $width, int $height, int $maxDimension): array
    {
        $maxSide = max($width, $height);

        if ($maxSide <= $maxDimension) {
            return [max(1, $width), max(1, $height)];
        }

        $ratio = $maxDimension / $maxSide;

        return [
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        ];
    }

    private function encodeCleanBuffer(\GdImage $canvas, string $mimeType, string $errorField): string
    {
        ob_start();

        $encoded = match ($mimeType) {
            'image/png' => imagepng($canvas, null, 6),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($canvas, null, 82)
                : imagejpeg($canvas, null, 84),
            default => imagejpeg($canvas, null, 84),
        };

        $contents = ob_get_clean();

        if (! $encoded || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                $errorField => [__('Unable to encode processed image.')],
            ]);
        }

        return $contents;
    }
}
