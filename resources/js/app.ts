import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { i18n, syncI18nLocale } from '@/i18n';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'SyncViet';

function syncPageLocale(props: Record<string, unknown> | undefined): void {
    if (!props) {
        return;
    }

    syncI18nLocale(
        props.locale as string | undefined,
        props.isRtl as boolean | undefined,
        props.translationOverrides as Record<string, string> | undefined,
    );
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('policies/'):
                return PublicLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app, { page }) => {
        syncPageLocale(page.props as Record<string, unknown>);
        app.use(i18n);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4F46E5',
    },
});

router.on('navigate', (event) => {
    const page = (event as CustomEvent).detail?.page;
    syncPageLocale(page?.props as Record<string, unknown> | undefined);
});

router.on('success', (event) => {
    const page = (event as CustomEvent).detail?.page;
    syncPageLocale(page?.props as Record<string, unknown> | undefined);
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data and validation errors from the server...
initializeFlashToast();
