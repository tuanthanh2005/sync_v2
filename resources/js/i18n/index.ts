import { createI18n } from 'vue-i18n';
import en from '@/i18n/locales/en.json';
import vi from '@/i18n/locales/vi.json';

export type SupportedLocale = 'vi' | 'en';

export const i18n = createI18n({
    legacy: false,
    locale: 'vi',
    fallbackLocale: 'en',
    messages: {
        vi,
        en,
    },
});

export function syncI18nLocale(locale: string | undefined): void {
    if (locale === 'vi' || locale === 'en') {
        i18n.global.locale.value = locale;
    }
}
