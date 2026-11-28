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
    'image/jpe',
    'image/pjpe',
    'image/pjpeg',
    'image/jfif',
    'image/x-citrix-jpeg',
    'image/x-png',
    'image/apng',
    'image/vnd.mozilla.apng',
    'image/x-citrix-png',
    'image/x-webp',
]);
const DISALLOWED_CONCRETE_MIMES = new Set<string>([
    'image/gif',
    'image/svg+xml',
    'image/bmp',
    'image/x-ms-bmp',
    'image/tiff',
    'image/x-icon',
    'image/vnd.microsoft.icon',
    'image/avif',
    'image/heic',
    'image/heif',
    'application/pdf',
    'application/zip',
    'application/x-zip-compressed',
    'application/x-rar-compressed',
    'application/x-7z-compressed',
    'application/x-gzip',
    'application/x-tar',
    'application/x-msdownload',
    'application/x-executable',
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

export function isConcreteDisallowedMime(mime: string): boolean {
    if (!mime || ALLOWED_IMAGE_MIME_ALIASES.has(mime)) {
        return false;
    }

    if (
        DISALLOWED_CONCRETE_MIMES.has(mime) ||
        mime.startsWith('image/') ||
        mime.startsWith('video/') ||
        mime.startsWith('audio/') ||
        mime.startsWith('text/')
    ) {
        return true;
    }

    return (
        mime.includes('php') ||
        mime.includes('html') ||
        mime.includes('xml') ||
        mime.includes('javascript') ||
        mime.includes('ecmascript') ||
        mime.includes('executable')
    );
}

export function validateImageFile(
    file: File,
    options: ImageValidationOptions = {},
): ImageValidationResult {
    const t = i18n.global.t;
    if (!(file instanceof Blob)) {
        return {
            valid: false,
            code: 'empty',
            error: String(t('upload.errors.failed')),
        };
    }

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

    if (ext !== '' && !extAllowed) {
        return {
            valid: false,
            code: 'unsupported_format',
            error: String(t('upload.errors.unsupportedFormat')),
        };
    }

    if (mime !== '' && !mimeAllowed) {
        if (!extAllowed || isConcreteDisallowedMime(mime)) {
            return {
                valid: false,
                code: 'unsupported_format',
                error: String(t('upload.errors.unsupportedFormat')),
            };
        }
    }

    return { valid: true };
}

function detectMagicSignatureFromBytes(
    bytes: Uint8Array,
): (typeof ALLOWED_IMAGE_MIMES)[number] | null {
    if (
        bytes.length >= 3 &&
        bytes[0] === 0xff &&
        bytes[1] === 0xd8 &&
        bytes[2] === 0xff
    ) {
        return 'image/jpeg';
    }

    if (
        bytes.length >= 8 &&
        bytes[0] === 0x89 &&
        bytes[1] === 0x50 &&
        bytes[2] === 0x4e &&
        bytes[3] === 0x47 &&
        bytes[4] === 0x0d &&
        bytes[5] === 0x0a &&
        bytes[6] === 0x1a &&
        bytes[7] === 0x0a
    ) {
        return 'image/png';
    }

    if (
        bytes.length >= 12 &&
        bytes[0] === 0x52 &&
        bytes[1] === 0x49 &&
        bytes[2] === 0x46 &&
        bytes[3] === 0x46 &&
        bytes[8] === 0x57 &&
        bytes[9] === 0x45 &&
        bytes[10] === 0x42 &&
        bytes[11] === 0x50
    ) {
        return 'image/webp';
    }

    return null;
}

function containsDisguisedPayloadInBytes(bytes: Uint8Array): boolean {
    if (bytes.length === 0) {
        return false;
    }

    let text = '';
    for (let i = 0; i < bytes.length; i++) {
        text += String.fromCharCode(bytes[i]);
    }
    const lower = text.toLowerCase();

    return (
        lower.includes('<?php') ||
        lower.includes('<script') ||
        lower.includes('<svg') ||
        lower.includes('<!doctype html') ||
        lower.trimStart().startsWith('<?xml')
    );
}

async function canBrowserDecodeImage(file: File): Promise<boolean> {
    if (typeof window === 'undefined') {
        return true;
    }

    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file);
            const valid = bitmap.width > 0 && bitmap.height > 0;
            bitmap.close();
            if (valid) {
                return true;
            }
        } catch {
            // Fall back to HTMLImageElement check below.
        }
    }

    if (
        typeof Image === 'undefined' ||
        typeof URL === 'undefined' ||
        typeof URL.createObjectURL !== 'function'
    ) {
        return true;
    }

    const objectUrl = URL.createObjectURL(file);
    try {
        return await new Promise<boolean>((resolve) => {
            const img = new Image();
            img.decoding = 'async';
            img.onload = () =>
                resolve(img.naturalWidth > 0 && img.naturalHeight > 0);
            img.onerror = () => resolve(false);
            img.src = objectUrl;
        });
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

/**
 * Inspect actual leading bytes (magic signature + executable/SVG trap) and browser decoding
 * so inaccurate/empty browser `file.type` never falsely blocks real JPG/PNG/WebP images, while
 * disguised scripts/SVG/corrupt files are caught cleanly before upload.
 */
export async function inspectImageFileContent(
    file: File,
): Promise<ImageValidationResult> {
    const t = i18n.global.t;

    if (!(file instanceof Blob) || file.size <= 0) {
        return {
            valid: false,
            code: 'empty',
            error: String(t('upload.errors.failed')),
        };
    }

    if (typeof file.slice === 'function') {
        try {
            const buffer = await file.slice(0, 512).arrayBuffer();
            const bytes = new Uint8Array(buffer);

            if (containsDisguisedPayloadInBytes(bytes)) {
                return {
                    valid: false,
                    code: 'unsupported_format',
                    error: String(t('upload.errors.unsupportedFormat')),
                };
            }

            if (detectMagicSignatureFromBytes(bytes) === null) {
                return {
                    valid: false,
                    code: 'unsupported_format',
                    error: String(t('upload.errors.unsupportedFormat')),
                };
            }
        } catch {
            // If FileReader/slice fails unexpectedly, defer cleanly to decode + backend validation.
        }
    }

    const decoded = await canBrowserDecodeImage(file);
    if (!decoded) {
        return {
            valid: false,
            code: 'empty',
            error: String(t('upload.errors.failed')),
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
