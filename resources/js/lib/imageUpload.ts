import { i18n } from '@/i18n';

/**
 * SysNoxis Global Image Upload Policy Constants
 * 40 MB = 40,000,000 bytes (strict `<`: files >= 40,000,000 bytes are rejected).
 */
export const MAX_IMAGE_BYTES = 40_000_000;
export const MAX_POST_REQUEST_BYTES = 80_000_000;
export const ALLOWED_IMAGE_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
] as const;
const ALLOWED_IMAGE_MIME_ALIASES = new Set<string>([
    ...ALLOWED_IMAGE_MIMES,
    'image/jpg',
    'image/pjpeg',
    'image/x-png',
    'image/x-webp',
]);
const GENERIC_BINARY_MIMES = new Set<string>([
    'application/octet-stream',
    'binary/octet-stream',
]);
export const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'] as const;
export const IMAGE_ACCEPT_ATTR =
    'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp';

const VIDEO_EXTENSIONS = new Set([
    'mp4',
    'mov',
    'm4v',
    'webm',
    'avi',
    'mkv',
    'wmv',
    'flv',
    'mpeg',
    'mpg',
    '3gp',
    'ogv',
]);

export type ImageValidationResult = {
    valid: boolean;
    error?: string;
    code?: 'too_large' | 'unsupported_format' | 'video_unsupported' | 'empty';
};

export type ImageValidationOptions = {
    maxBytes?: number;
};

export function formatMegabytes(bytes: number): string {
    const mb = bytes / 1_000_000;

    return mb >= 10 ? String(Math.round(mb)) : mb.toFixed(1);
}

export function resolveMaxBytes(configuredBytes?: number): number {
    if (
        typeof configuredBytes === 'number' &&
        Number.isFinite(configuredBytes) &&
        configuredBytes > 0
    ) {
        return Math.min(MAX_IMAGE_BYTES, Math.floor(configuredBytes));
    }

    return MAX_IMAGE_BYTES;
}

export function validateImageFile(
    file: File,
    options: ImageValidationOptions = {},
): ImageValidationResult {
    const t = i18n.global.t;
    const maxBytes = resolveMaxBytes(options.maxBytes);
    const maxMb = Math.max(1, Math.round(maxBytes / 1_000_000));

    const ext = file.name.includes('.')
        ? (file.name.split('.').pop()?.trim().toLowerCase() ?? '')
        : '';
    const rawMime = (file.type || '').trim().toLowerCase();
    const mime = rawMime.split(';')[0]?.trim() ?? '';

    if (mime.startsWith('video/') || VIDEO_EXTENSIONS.has(ext)) {
        return {
            valid: false,
            code: 'video_unsupported',
            error: String(t('upload.errors.videoComingSoon')),
        };
    }

    if (file.size <= 0) {
        return {
            valid: false,
            code: 'empty',
            error: String(t('upload.errors.failed')),
        };
    }

    // Strict `<` comparison: files equal to or larger than 40,000,000 bytes are rejected.
    if (file.size >= maxBytes) {
        return {
            valid: false,
            code: 'too_large',
            error: String(
                t('upload.errors.fileTooLargeWithSize', {
                    size: formatMegabytes(file.size),
                    max: maxMb,
                }),
            ),
        };
    }

    const mimeAllowed = mime !== '' && ALLOWED_IMAGE_MIME_ALIASES.has(mime);
    const mimeIsGenericBinary = mime !== '' && GENERIC_BINARY_MIMES.has(mime);
    const extAllowed =
        ext !== '' &&
        (ALLOWED_IMAGE_EXTENSIONS as readonly string[]).includes(ext);

    if (!mimeAllowed && !extAllowed) {
        return {
            valid: false,
            code: 'unsupported_format',
            error: String(t('upload.errors.unsupportedFormat')),
        };
    }

    if (mime !== '' && !mimeAllowed && !(mimeIsGenericBinary && extAllowed)) {
        return {
            valid: false,
            code: 'unsupported_format',
            error: String(t('upload.errors.unsupportedFormat')),
        };
    }

    if (ext !== '' && !extAllowed) {
        return {
            valid: false,
            code: 'unsupported_format',
            error: String(t('upload.errors.unsupportedFormat')),
        };
    }

    return { valid: true };
}

export function validateImageBatch(
    existingFiles: File[],
    incomingFiles: File[],
    options: {
        maxFiles: number;
        maxBytes?: number;
        maxTotalBytes?: number;
    },
): {
    accepted: File[];
    errors: string[];
} {
    const t = i18n.global.t;
    const maxBytes = resolveMaxBytes(options.maxBytes);
    const maxTotalBytes = options.maxTotalBytes ?? MAX_POST_REQUEST_BYTES;
    const accepted: File[] = [];
    const errors: string[] = [];

    let currentCount = existingFiles.length;
    let currentTotalBytes = existingFiles.reduce((acc, f) => acc + f.size, 0);

    for (const file of incomingFiles) {
        if (currentCount >= options.maxFiles) {
            errors.push(
                String(
                    t('upload.errors.tooManyFiles', { max: options.maxFiles }),
                ),
            );
            break;
        }

        const check = validateImageFile(file, { maxBytes });
        if (!check.valid && check.error) {
            errors.push(check.error);
            continue;
        }

        if (currentTotalBytes + file.size >= maxTotalBytes) {
            errors.push(
                String(
                    t('upload.errors.totalSizeExceeded', {
                        max: Math.round(maxTotalBytes / 1_000_000),
                    }),
                ),
            );
            continue;
        }

        accepted.push(file);
        currentCount++;
        currentTotalBytes += file.size;
    }

    return { accepted, errors: Array.from(new Set(errors)) };
}

/**
 * Map raw HTTP / server / technical upload errors into user-friendly localized messages.
 * Never exposes HTTP 413, Payload Too Large, PHP Upload Error, or Stack Traces.
 */
export function mapUploadErrorMessage(
    rawError: unknown,
    statusCode?: number,
    maxMb = 40,
): string {
    const t = i18n.global.t;

    if (statusCode === 413) {
        return String(t('upload.errors.maxSize', { max: maxMb }));
    }

    const message =
        typeof rawError === 'string'
            ? rawError
            : rawError instanceof Error
              ? rawError.message
              : '';

    const lower = message.toLowerCase();

    if (
        lower.includes('413') ||
        lower.includes('payload too large') ||
        lower.includes('posttoolarge') ||
        lower.includes('upload_max_filesize') ||
        lower.includes('post_max_size')
    ) {
        return String(t('upload.errors.maxSize', { max: maxMb }));
    }

    if (
        lower.includes('internal server error') ||
        lower.includes('validation exception') ||
        lower.includes('php upload error') ||
        lower.includes('stack trace') ||
        lower.includes('sqlstate') ||
        message.trim() === ''
    ) {
        return String(t('upload.errors.failed'));
    }

    return message;
}

export type CanvasCropOptions = {
    outputWidth: number;
    outputHeight: number;
    zoom: number;
    offsetXPercent: number;
    offsetYPercent: number;
    fileName?: string;
};

/**
 * Pure client-side Canvas 2D cropper (zero extra dependencies) for 1:1 Avatar and 3:1 Cover images.
 */
export async function renderCroppedImageFile(
    imageUrl: string,
    options: CanvasCropOptions,
): Promise<File> {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image();
        el.decoding = 'async';
        el.onload = () => resolve(el);
        el.onerror = () => reject(new Error('Image load failed'));
        el.src = imageUrl;
    });

    if (typeof img.decode === 'function') {
        await img.decode().catch(() => undefined);
    }

    const canvas = document.createElement('canvas');
    canvas.width = options.outputWidth;
    canvas.height = options.outputHeight;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        throw new Error('Canvas unavailable');
    }

    const srcRatio = img.naturalWidth / Math.max(1, img.naturalHeight);
    const dstRatio = options.outputWidth / Math.max(1, options.outputHeight);

    let baseDrawWidth = options.outputWidth;
    let baseDrawHeight = options.outputHeight;

    if (srcRatio > dstRatio) {
        baseDrawHeight = options.outputHeight;
        baseDrawWidth = baseDrawHeight * srcRatio;
    } else {
        baseDrawWidth = options.outputWidth;
        baseDrawHeight = baseDrawWidth / srcRatio;
    }

    const zoom = Math.max(1, Math.min(3, options.zoom));
    const drawWidth = baseDrawWidth * zoom;
    const drawHeight = baseDrawHeight * zoom;

    const maxPanX = Math.max(0, (drawWidth - options.outputWidth) / 2);
    const maxPanY = Math.max(0, (drawHeight - options.outputHeight) / 2);

    const dx =
        (options.outputWidth - drawWidth) / 2 +
        (options.offsetXPercent / 100) * maxPanX;
    const dy =
        (options.outputHeight - drawHeight) / 2 +
        (options.offsetYPercent / 100) * maxPanY;

    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, dx, dy, drawWidth, drawHeight);

    const preferredMime = 'image/webp';
    const blob = await new Promise<Blob | null>((resolve) => {
        canvas.toBlob(
            (result) => {
                if (result) {
                    resolve(result);
                    return;
                }
                canvas.toBlob(
                    (fallback) => resolve(fallback),
                    'image/jpeg',
                    0.9,
                );
            },
            preferredMime,
            0.88,
        );
    });

    if (!blob) {
        throw new Error('Unable to export cropped image');
    }

    const ext = blob.type === 'image/webp' ? 'webp' : 'jpg';
    const baseName = (options.fileName ?? 'image').replace(/\.[^.]+$/, '');

    return new File([blob], `${baseName}.${ext}`, {
        type: blob.type || 'image/jpeg',
        lastModified: Date.now(),
    });
}
