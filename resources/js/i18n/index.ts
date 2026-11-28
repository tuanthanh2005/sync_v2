import { createI18n } from 'vue-i18n';
import en from '@/i18n/locales/en.json';

export const SUPPORTED_LOCALES = [
    'en',
    'vi',
    'es',
    'pt-BR',
    'id',
    'hi',
    'th',
    'fil',
    'fr',
    'de',
    'ja',
    'ko',
    'zh-CN',
    'ar',
    'tr',
] as const;

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

const RTL_LOCALES = new Set<string>(['ar']);
const loadedLocales = new Set<SupportedLocale>(['en']);

type LocaleModule = { default: Record<string, unknown> };

const localeLoaders: Record<
    Exclude<SupportedLocale, 'en'>,
    () => Promise<LocaleModule>
> = {
    vi: () => import('@/i18n/locales/vi.json') as Promise<LocaleModule>,
    es: () => import('@/i18n/locales/es.json') as Promise<LocaleModule>,
    'pt-BR': () => import('@/i18n/locales/pt-BR.json') as Promise<LocaleModule>,
    id: () => import('@/i18n/locales/id.json') as Promise<LocaleModule>,
    hi: () => import('@/i18n/locales/hi.json') as Promise<LocaleModule>,
    th: () => import('@/i18n/locales/th.json') as Promise<LocaleModule>,
    fil: () => import('@/i18n/locales/fil.json') as Promise<LocaleModule>,
    fr: () => import('@/i18n/locales/fr.json') as Promise<LocaleModule>,
    de: () => import('@/i18n/locales/de.json') as Promise<LocaleModule>,
    ja: () => import('@/i18n/locales/ja.json') as Promise<LocaleModule>,
    ko: () => import('@/i18n/locales/ko.json') as Promise<LocaleModule>,
    'zh-CN': () => import('@/i18n/locales/zh-CN.json') as Promise<LocaleModule>,
    ar: () => import('@/i18n/locales/ar.json') as Promise<LocaleModule>,
    tr: () => import('@/i18n/locales/tr.json') as Promise<LocaleModule>,
};

type MessageSchema = typeof en;

const missingKeysReported = new Set<string>();

export const i18n = createI18n<[MessageSchema], SupportedLocale, false>({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    warnHtmlMessage: false,
    missing: (locale, key) => {
        const signature = `${locale}:${key}`;
        if (!missingKeysReported.has(signature)) {
            missingKeysReported.add(signature);
            if (import.meta.env.DEV) {
                console.warn(
                    `[SysNoxis i18n] Missing translation key "${key}" for locale "${locale}" (fell back to English).`,
                );
            }
        }
    },
    messages: {
        en,
    } as Record<SupportedLocale, MessageSchema>,
});

function escapeVueI18nLiterals(value: string): string {
    if (!value.includes('@')) {
        return value;
    }

    return value.replace(/\{'@'\}\s*|\{"@"\}\s*|@/g, (match) =>
        match === '@' ? "{'@'}" : match,
    );
}

function setNestedKey(
    target: Record<string, unknown>,
    dottedKey: string,
    value: string,
): void {
    const segments = dottedKey.split('.');
    let cursor = target;

    for (let i = 0; i < segments.length - 1; i++) {
        const part = segments[i];
        const existing = cursor[part];

        if (typeof existing !== 'object' || existing === null) {
            cursor[part] = {};
        }

        cursor = cursor[part] as Record<string, unknown>;
    }

    cursor[segments[segments.length - 1]] = escapeVueI18nLiterals(value);
}

export function isSupportedLocale(
    locale: string | undefined,
): locale is SupportedLocale {
    return (
        typeof locale === 'string' &&
        (SUPPORTED_LOCALES as readonly string[]).includes(locale)
    );
}

async function ensureLocaleLoaded(locale: SupportedLocale): Promise<void> {
    if (locale === 'en' || loadedLocales.has(locale)) {
        return;
    }

    try {
        const mod = await localeLoaders[locale]();
        i18n.global.setLocaleMessage(locale, mod.default as typeof en);
        loadedLocales.add(locale);
    } catch {
        // Fallback safely to English if network chunk load fails while offline
    }
}

export async function syncI18nLocale(
    locale: string | undefined,
    isRtl?: boolean,
    overrides?: Record<string, string>,
): Promise<void> {
    const resolved: SupportedLocale = isSupportedLocale(locale) ? locale : 'en';

    if (typeof document !== 'undefined') {
        const rtl =
            typeof isRtl === 'boolean' ? isRtl : RTL_LOCALES.has(resolved);
        document.documentElement.lang = resolved;
        document.documentElement.dir = rtl ? 'rtl' : 'ltr';
    }

    await ensureLocaleLoaded(resolved);
    i18n.global.locale.value = resolved;

    if (overrides && Object.keys(overrides).length > 0) {
        const nested: Record<string, unknown> = {};

        for (const [key, val] of Object.entries(overrides)) {
            setNestedKey(nested, key, val);
        }

        i18n.global.mergeLocaleMessage(resolved, nested);
    }
}
