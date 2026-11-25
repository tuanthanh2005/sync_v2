import { createI18n } from 'vue-i18n';
import ar from '@/i18n/locales/ar.json';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import es from '@/i18n/locales/es.json';
import fil from '@/i18n/locales/fil.json';
import fr from '@/i18n/locales/fr.json';
import hi from '@/i18n/locales/hi.json';
import id from '@/i18n/locales/id.json';
import ja from '@/i18n/locales/ja.json';
import ko from '@/i18n/locales/ko.json';
import ptBR from '@/i18n/locales/pt-BR.json';
import th from '@/i18n/locales/th.json';
import tr from '@/i18n/locales/tr.json';
import vi from '@/i18n/locales/vi.json';
import zhCN from '@/i18n/locales/zh-CN.json';

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

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: {
        en,
        vi,
        es,
        'pt-BR': ptBR,
        id,
        hi,
        th,
        fil,
        fr,
        de,
        ja,
        ko,
        'zh-CN': zhCN,
        ar,
        tr,
    },
});

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

    cursor[segments[segments.length - 1]] = value;
}

export function isSupportedLocale(
    locale: string | undefined,
): locale is SupportedLocale {
    return (
        typeof locale === 'string' &&
        (SUPPORTED_LOCALES as readonly string[]).includes(locale)
    );
}

export function syncI18nLocale(
    locale: string | undefined,
    isRtl?: boolean,
    overrides?: Record<string, string>,
): void {
    const resolved: SupportedLocale = isSupportedLocale(locale) ? locale : 'en';
    i18n.global.locale.value = resolved;

    if (overrides && Object.keys(overrides).length > 0) {
        const nested: Record<string, unknown> = {};

        for (const [key, val] of Object.entries(overrides)) {
            setNestedKey(nested, key, val);
        }

        i18n.global.mergeLocaleMessage(resolved, nested);
    }

    if (typeof document !== 'undefined') {
        const rtl =
            typeof isRtl === 'boolean' ? isRtl : RTL_LOCALES.has(resolved);
        document.documentElement.lang = resolved;
        document.documentElement.dir = rtl ? 'rtl' : 'ltr';
    }
}
