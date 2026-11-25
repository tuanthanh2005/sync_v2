import { resolveIntlLocale } from '@/lib/formatters';

export function formatVnd(amount: number, locale = 'en'): string {
    const safeAmount = Math.max(
        0,
        Math.trunc(Number.isFinite(amount) ? amount : 0),
    );
    const formatted = new Intl.NumberFormat(resolveIntlLocale(locale)).format(
        safeAmount,
    );

    return `${formatted}đ`;
}
