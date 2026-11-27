import { toast } from 'vue-sonner';
import { i18n } from '@/i18n';

export type ToastKind = 'success' | 'error' | 'warning' | 'info' | 'loading';

export type AppToastOptions = {
    description?: string;
    duration?: number;
    persistent?: boolean;
    id?: string | number;
    action?: {
        label: string;
        onClick: () => void;
    };
};

const DEFAULT_DURATIONS: Record<ToastKind, number> = {
    success: 4500,
    info: 4500,
    warning: 6000,
    error: 7500,
    loading: Number.POSITIVE_INFINITY,
};

const recentMessages = new Map<string, number>();
const DEDUP_WINDOW_MS = 2500;

function sanitizeMessage(raw: string): string {
    const trimmed = raw.trim();
    const lower = trimmed.toLowerCase();

    if (
        lower.includes('payload too large') ||
        lower.includes('http 413') ||
        lower.includes('posttoolargeexception')
    ) {
        return String(i18n.global.t('upload.errors.maxSize', { max: 40 }));
    }

    if (
        trimmed === '' ||
        trimmed.includes('Stack trace:') ||
        trimmed.includes('SQLSTATE[') ||
        trimmed.includes('vendor/laravel/') ||
        trimmed.includes('vendor\\laravel\\') ||
        lower.includes('php upload error') ||
        lower.includes('internal server error') ||
        lower.includes('validation exception') ||
        trimmed.length > 320
    ) {
        return String(i18n.global.t('toast.unexpectedError'));
    }

    return trimmed;
}

function isDuplicate(kind: ToastKind, message: string): boolean {
    if (kind === 'loading') {
        return false;
    }

    const now = Date.now();
    const key = `${kind}:${message}`;
    const lastSeen = recentMessages.get(key);

    if (lastSeen !== undefined && now - lastSeen < DEDUP_WINDOW_MS) {
        return true;
    }

    recentMessages.set(key, now);

    if (recentMessages.size > 40) {
        for (const [entryKey, timestamp] of recentMessages.entries()) {
            if (now - timestamp > DEDUP_WINDOW_MS * 2) {
                recentMessages.delete(entryKey);
            }
        }
    }

    return false;
}

function show(
    kind: ToastKind,
    message: string,
    options: AppToastOptions = {},
): string | number | undefined {
    const safeMessage = sanitizeMessage(message);

    if (isDuplicate(kind, safeMessage)) {
        return undefined;
    }

    const duration = options.persistent
        ? Number.POSITIVE_INFINITY
        : (options.duration ?? DEFAULT_DURATIONS[kind]);

    const sonnerOptions: Record<string, unknown> = {
        description: options.description
            ? sanitizeMessage(options.description)
            : undefined,
        duration,
        id: options.id,
        closeButton: true,
        action: options.action,
        style: Number.isFinite(duration)
            ? { '--toast-duration': `${duration}ms` }
            : { '--toast-duration': '0ms' },
        class: Number.isFinite(duration)
            ? 'syncviet-toast-timed'
            : 'syncviet-toast-persistent',
    };

    switch (kind) {
        case 'success':
            return toast.success(safeMessage, sonnerOptions);
        case 'error':
            return toast.error(safeMessage, sonnerOptions);
        case 'warning':
            return toast.warning(safeMessage, sonnerOptions);
        case 'loading':
            return toast.loading(safeMessage, sonnerOptions);
        default:
            return toast.info(safeMessage, sonnerOptions);
    }
}

export const appToast = {
    success: (message: string, options?: AppToastOptions) =>
        show('success', message, options),
    error: (message: string, options?: AppToastOptions) =>
        show('error', message, options),
    warning: (message: string, options?: AppToastOptions) =>
        show('warning', message, options),
    info: (message: string, options?: AppToastOptions) =>
        show('info', message, options),
    loading: (message: string, options?: AppToastOptions) =>
        show('loading', message, options),
    action: (
        message: string,
        actionLabel: string,
        onClick: () => void,
        kind: Exclude<ToastKind, 'loading'> = 'info',
        options?: Omit<AppToastOptions, 'action'>,
    ) =>
        show(kind, message, {
            ...options,
            action: { label: actionLabel, onClick },
        }),
    dismiss: (id?: string | number) => toast.dismiss(id),
};
