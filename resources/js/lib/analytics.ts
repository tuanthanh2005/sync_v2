declare global {
    interface Window {
        __SYNCVIET_GA4_ID?: string;
        gtag?: (...args: unknown[]) => void;
    }
}

let lastTrackedPath = '';

/**
 * Privacy-safe GA4 Inertia SPA page_view tracker.
 * - Fires ONLY when Admin enabled a valid GA4 Measurement ID (zero raw JS).
 * - Deduplicates repeated navigations to the exact same path.
 * - Never sends Admin pages, email addresses, or user PII.
 */
export function trackGa4PageView(url?: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    const measurementId = window.__SYNCVIET_GA4_ID;
    if (!measurementId || typeof window.gtag !== 'function') {
        return;
    }

    const path = (url || window.location.pathname).split('?')[0] || '/';
    if (path.startsWith('/admin') || path === lastTrackedPath) {
        return;
    }

    lastTrackedPath = path;

    window.gtag('event', 'page_view', {
        page_path: path,
        page_title: document.title,
        send_to: measurementId,
    });
}
