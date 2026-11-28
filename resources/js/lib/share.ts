import { appToast } from '@/lib/toast';

export function resolveShareOrigin(canonicalOriginProp?: unknown): string {
    if (
        typeof canonicalOriginProp === 'string' &&
        /^https?:\/\/[^\s/]+$/i.test(canonicalOriginProp.trim())
    ) {
        if (
            typeof window !== 'undefined' &&
            (window.location.hostname === 'localhost' ||
                window.location.hostname === '127.0.0.1')
        ) {
            return window.location.origin;
        }

        return canonicalOriginProp.trim().replace(/\/+$/, '');
    }

    if (typeof window !== 'undefined') {
        const origin = window.location.origin;
        if (/(^|\.)syncviet\.com$/i.test(window.location.hostname)) {
            return 'https://sysnoxis.com';
        }

        return origin.replace(/\/+$/, '');
    }

    return 'https://sysnoxis.com';
}

export function localeUrlPrefix(locale: string): string {
    return locale && locale !== 'en' ? `/${encodeURIComponent(locale)}` : '';
}

export function buildLocalizedHomeUrl(
    locale: string,
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    return locale && locale !== 'en'
        ? `${origin}/${encodeURIComponent(locale)}`
        : `${origin}/`;
}

export function buildLocalizedPostShareUrl(
    params: {
        postId: number;
        slug?: string | null;
        languageCode?: string | null;
        alternates?: Array<{ hreflang: string; href: string }>;
        currentLocale: string;
    },
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    const activeLocale = params.currentLocale || 'en';
    const postLang = params.languageCode || 'en';

    const matchedAlt = params.alternates?.find(
        (alt) => alt.hreflang === activeLocale && alt.href,
    );
    if (matchedAlt) {
        try {
            const parsed = new URL(matchedAlt.href, origin);
            return `${origin}${parsed.pathname}${parsed.search}`;
        } catch {
            // Fall through cleanly
        }
    }

    const cleanSlug = (params.slug || '').trim();
    if (activeLocale === postLang && cleanSlug !== '') {
        return `${origin}/${encodeURIComponent(activeLocale)}/posts/${params.postId}-${encodeURIComponent(cleanSlug)}`;
    }

    if (activeLocale !== postLang) {
        return `${origin}/posts/${params.postId}?lang=${encodeURIComponent(activeLocale)}`;
    }

    return `${origin}/posts/${params.postId}`;
}

export function buildLocalizedCommunityShareUrl(
    slug: string,
    locale: string,
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    return `${origin}${localeUrlPrefix(locale)}/communities/${encodeURIComponent(slug)}`;
}

export function buildLocalizedProfileShareUrl(
    username: string,
    locale: string,
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    return `${origin}${localeUrlPrefix(locale)}/u/${encodeURIComponent(username)}`;
}

export function buildLocalizedReferralShareUrl(
    code: string,
    locale: string,
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    return `${origin}${localeUrlPrefix(locale)}/r/${encodeURIComponent(code)}`;
}

export function buildLocalizedPremiumShareUrl(
    locale: string,
    canonicalOriginProp?: unknown,
): string {
    const origin = resolveShareOrigin(canonicalOriginProp);
    return `${origin}${localeUrlPrefix(locale)}/premium`;
}

export async function copyToClipboard(text: string): Promise<boolean> {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Fallback to temporary textarea below
        }
    }

    if (typeof document === 'undefined') {
        return false;
    }

    try {
        const el = document.createElement('textarea');
        el.value = text;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '-9999px';
        document.body.appendChild(el);
        el.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(el);
        return ok;
    } catch {
        return false;
    }
}

export async function shareOrCopyLink(options: {
    url: string;
    title: string;
    text?: string;
    copiedMessage: string;
    failedMessage?: string;
    preferNativeShare?: boolean;
}): Promise<'shared' | 'copied' | 'cancelled' | 'failed'> {
    if (
        options.preferNativeShare &&
        typeof navigator !== 'undefined' &&
        typeof navigator.share === 'function'
    ) {
        try {
            await navigator.share({
                title: options.title,
                text: options.text,
                url: options.url,
            });
            return 'shared';
        } catch (err) {
            if ((err as Error)?.name === 'AbortError') {
                return 'cancelled';
            }
        }
    }

    const copied = await copyToClipboard(options.url);
    if (copied) {
        appToast.success(options.copiedMessage);
        return 'copied';
    }

    if (options.failedMessage) {
        appToast.error(options.failedMessage);
    }
    return 'failed';
}
