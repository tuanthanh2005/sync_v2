import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { SUPPORTED_LOCALES } from '@/i18n';

const STACK_STORAGE_KEY = 'sysnoxis.mobile_nav_stack.v1';
const MAX_STACK_ITEMS = 40;

type NavStackEntry = {
    url: string;
    component: string;
};

const ROOT_COMPONENTS = new Set<string>([
    'Welcome',
    'Search',
    'communities/Index',
    'notifications/Index',
    'Dashboard',
]);

let stackInitialized = false;
let listenerRegistered = false;
let poppingBackTransition = false;
let historyStack: NavStackEntry[] = [];

function normalizeInternalUrl(rawUrl: string): string | null {
    const trimmed = rawUrl.trim();
    if (trimmed === '' || trimmed.startsWith('//')) {
        return null;
    }

    if (trimmed.startsWith('/')) {
        return trimmed;
    }

    if (typeof window !== 'undefined') {
        try {
            const parsed = new URL(trimmed, window.location.origin);
            if (parsed.origin === window.location.origin) {
                return `${parsed.pathname}${parsed.search}`;
            }
        } catch {
            return null;
        }
    }

    return null;
}

function stripLocaleFromPath(pathname: string): {
    locale: string | null;
    cleanPath: string;
} {
    const segments = pathname.split('/').filter(Boolean);
    if (
        segments.length > 0 &&
        (SUPPORTED_LOCALES as readonly string[]).includes(segments[0])
    ) {
        return {
            locale: segments[0],
            cleanPath: `/${segments.slice(1).join('/')}`,
        };
    }

    return {
        locale: null,
        cleanPath: pathname || '/',
    };
}

function saveStack(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(
            STACK_STORAGE_KEY,
            JSON.stringify(historyStack),
        );
    } catch {
        // Ignore restricted sessionStorage contexts safely
    }
}

function loadStackOnce(currentUrl: string, currentComponent: string): void {
    if (stackInitialized) {
        return;
    }

    stackInitialized = true;

    if (typeof window !== 'undefined') {
        try {
            const raw = window.sessionStorage.getItem(STACK_STORAGE_KEY);
            if (raw) {
                const parsed = JSON.parse(raw) as unknown;
                if (Array.isArray(parsed)) {
                    historyStack = parsed
                        .filter(
                            (item): item is NavStackEntry =>
                                typeof item === 'object' &&
                                item !== null &&
                                typeof (item as NavStackEntry).url === 'string' &&
                                typeof (item as NavStackEntry).component ===
                                    'string',
                        )
                        .slice(-MAX_STACK_ITEMS);
                }
            }
        } catch {
            historyStack = [];
        }
    }

    const normalized = normalizeInternalUrl(currentUrl);
    if (normalized) {
        const last = historyStack[historyStack.length - 1];
        if (!last || last.url !== normalized) {
            historyStack.push({ url: normalized, component: currentComponent });
            if (historyStack.length > MAX_STACK_ITEMS) {
                historyStack = historyStack.slice(-MAX_STACK_ITEMS);
            }
            saveStack();
        }
    }
}

function registerNavigationListener(): void {
    if (listenerRegistered || typeof window === 'undefined') {
        return;
    }

    listenerRegistered = true;

    router.on('navigate', (event) => {
        const normalized = normalizeInternalUrl(event.detail.page.url);
        const component = event.detail.page.component;

        if (!normalized) {
            return;
        }

        if (poppingBackTransition) {
            poppingBackTransition = false;
            const existingIndex = historyStack.findIndex(
                (entry) => entry.url === normalized,
            );
            if (existingIndex >= 0) {
                historyStack = historyStack.slice(0, existingIndex + 1);
                saveStack();
                return;
            }
        }

        const last = historyStack[historyStack.length - 1];
        if (!last || last.url !== normalized) {
            historyStack.push({ url: normalized, component });
            if (historyStack.length > MAX_STACK_ITEMS) {
                historyStack = historyStack.slice(-MAX_STACK_ITEMS);
            }
            saveStack();
        }
    });
}

function computeSafeFallbackUrl(
    component: string,
    currentUrl: string,
    activeLocale: string,
): string {
    const pathname = currentUrl.split('?')[0] || '/';
    const { locale: pathLocale } = stripLocaleFromPath(pathname);
    const effectiveLocale = pathLocale || activeLocale || 'en';
    const prefix =
        effectiveLocale !== '' && effectiveLocale !== 'en'
            ? `/${effectiveLocale}`
            : '';

    if (component === 'communities/Show') {
        return prefix !== '' ? `${prefix}/communities` : '/communities';
    }

    if (component === 'seo/TopicShow') {
        return prefix !== '' ? `${prefix}/topics` : '/topics';
    }

    if (component === 'messages/Index') {
        return '/messages';
    }

    return prefix !== '' ? prefix : '/';
}

/**
 * Route-aware mobile header navigation context:
 * - Root feeds/lists display Hamburger Menu.
 * - Detail/subpages display Back Arrow using bounded internal history or safe localized fallback.
 */
export function useMobileNavigation() {
    const page = usePage();

    registerNavigationListener();
    loadStackOnce(page.url, page.component);

    const isRootMobileRoute = computed<boolean>(() => {
        const component = page.component;
        const pathname = (page.url.split('?')[0] || '/').replace(/\/+$/, '') || '/';

        if (component === 'messages/Index') {
            const { cleanPath } = stripLocaleFromPath(pathname);
            return !/^\/messages\/[^/]+/.test(cleanPath);
        }

        return ROOT_COMPONENTS.has(component);
    });

    function goBack(): void {
        const currentNormalized = normalizeInternalUrl(page.url);
        const currentLocale = String(page.props.locale || 'en');

        const previousEntry =
            historyStack.length >= 2
                ? historyStack[historyStack.length - 2]
                : null;

        if (
            previousEntry &&
            previousEntry.url !== currentNormalized &&
            normalizeInternalUrl(previousEntry.url) !== null
        ) {
            historyStack = historyStack.slice(0, -1);
            saveStack();
            poppingBackTransition = true;

            window.setTimeout(() => {
                poppingBackTransition = false;
            }, 800);

            if (typeof window !== 'undefined' && window.history.length > 1) {
                window.history.back();
                return;
            }

            router.visit(previousEntry.url);
            return;
        }

        const fallbackUrl = computeSafeFallbackUrl(
            page.component,
            page.url,
            currentLocale,
        );

        historyStack = [{ url: fallbackUrl, component: 'Welcome' }];
        saveStack();
        router.visit(fallbackUrl);
    }

    return {
        isRootMobileRoute,
        goBack,
    };
}
