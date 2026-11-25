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

export function formatUsdCents(cents: number, locale = 'en'): string {
    const safeCents = Math.max(
        0,
        Math.trunc(Number.isFinite(cents) ? cents : 0),
    );
    const dollars = safeCents / 100;

    return new Intl.NumberFormat(resolveIntlLocale(locale), {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(dollars);
}

export function formatMoney(
    minorUnits: number,
    currency: string,
    locale = 'en',
): string {
    if (currency.toUpperCase() === 'USD') {
        return formatUsdCents(minorUnits, locale);
    }

    return formatVnd(minorUnits, locale);
}
