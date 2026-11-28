import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeAutoHideScrollbars } from '@/composables/useAutoHideScrollbar';
import { initializePwa } from '@/composables/usePwa';
import { i18n, syncI18nLocale } from '@/i18n';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AppProviders from '@/layouts/AppProviders.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { trackGa4PageView } from '@/lib/analytics';
import { initializeFlashToast } from '@/lib/flashToast';

let appName = import.meta.env.VITE_APP_NAME || 'SysNoxis';

function syncRuntimeBrand(props: Record<string, unknown> | undefined): void {
    if (!props) {
        return;
    }

    const systemSettings = props.systemSettings as
        | Record<string, unknown>
        | undefined;
    const runtimeName =
        (typeof systemSettings?.siteName === 'string' &&
            systemSettings.siteName.trim()) ||
        (typeof props.name === 'string' && props.name.trim()) ||
        '';

    if (runtimeName) {
        appName = runtimeName;
    }
}

function syncPageLocale(props: Record<string, unknown> | undefined): void {
    if (!props) {
        return;
    }

    syncRuntimeBrand(props);

    void syncI18nLocale(
        props.locale as string | undefined,
        props.isRtl as boolean | undefined,
        props.translationOverrides as Record<string, string> | undefined,
    );
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Persistent layouts: AppProviders + the same shell component stay mounted
    // across Inertia visits, so Header/Sidebar never remount or flash. Service
    // pages reuse AppLayout (ConnectHubLayout switches to its wide 2-column mode
    // by page component) instead of a separate layout that would remount the shell.
    layout: (name) => {
        switch (true) {
            case name.startsWith('admin/'):
                return [AppProviders, AdminLayout];
            case name.startsWith('auth/'):
                return [AppProviders, AuthLayout];
            case name.startsWith('policies/'):
                return [AppProviders, PublicLayout];
            case name.startsWith('settings/'):
                return [AppProviders, AppLayout, SettingsLayout];
            default:
                return [AppProviders, AppLayout];
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
    trackGa4PageView(typeof page?.url === 'string' ? page.url : undefined);
});

router.on('success', (event) => {
    const page = (event as CustomEvent).detail?.page;
    syncPageLocale(page?.props as Record<string, unknown> | undefined);
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data and validation errors from the server...
initializeFlashToast();

// Register safe PWA Service Worker & non-intrusive install/update handlers...
initializePwa();

// Enable lightweight global macOS-style auto-hide scrollbars (3000ms fade-out)...
initializeAutoHideScrollbars(3000);
