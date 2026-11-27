import { router } from '@inertiajs/vue3';
import { i18n } from '@/i18n';
import { appToast } from '@/lib/toast';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data || !data.message) {
            return;
        }

        const kind = data.type ?? 'info';
        appToast[kind](data.message, {
            description: data.description,
            duration: data.duration,
            persistent: data.persistent,
            action:
                data.actionUrl && data.actionLabel
                    ? {
                          label: data.actionLabel,
                          onClick: () => router.visit(data.actionUrl!),
                      }
                    : undefined,
        });
    });

    router.on('error', (event) => {
        const errors = (event as CustomEvent).detail?.errors as
            | Record<string, string>
            | undefined;

        if (!errors) {
            return;
        }

        const firstMessage = Object.values(errors).find(
            (msg) => typeof msg === 'string' && msg.trim() !== '',
        );

        if (firstMessage) {
            appToast.error(firstMessage);
        }
    });

    router.on('httpException', (event) => {
        const status = (event as CustomEvent).detail?.response?.status as
            | number
            | undefined;

        if (status === 413) {
            event.preventDefault();
            appToast.error(
                String(i18n.global.t('upload.errors.maxSize', { max: 40 })),
            );
        } else if (status === 403) {
            appToast.error(String(i18n.global.t('toast.forbidden')));
        } else if (status === 429) {
            appToast.warning(String(i18n.global.t('toast.rateLimited')));
        } else if (status === 419) {
            appToast.warning(String(i18n.global.t('toast.sessionExpired')));
        } else if (status && status >= 500) {
            appToast.error(String(i18n.global.t('toast.unexpectedError')));
        }
    });

    router.on('networkError', () => {
        appToast.error(String(i18n.global.t('toast.networkError')));
    });
}
