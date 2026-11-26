const LOCALE_BCP47_MAP: Record<string, string> = {
    en: 'en-US',
    vi: 'vi-VN',
    es: 'es-ES',
    'pt-BR': 'pt-BR',
    id: 'id-ID',
    hi: 'hi-IN',
    th: 'th-TH',
    fil: 'fil-PH',
    fr: 'fr-FR',
    de: 'de-DE',
    ja: 'ja-JP',
    ko: 'ko-KR',
    'zh-CN': 'zh-CN',
    ar: 'ar-SA',
    tr: 'tr-TR',
};

export function resolveIntlLocale(locale: string): string {
    return LOCALE_BCP47_MAP[locale] ?? locale ?? 'en-US';
}

export function formatNumber(value: number, locale = 'en'): string {
    try {
        return new Intl.NumberFormat(resolveIntlLocale(locale)).format(value);
    } catch {
        return String(value);
    }
}

export function formatCurrencyVnd(amount: number, locale = 'en'): string {
    try {
        return new Intl.NumberFormat(resolveIntlLocale(locale), {
            style: 'currency',
            currency: 'VND',
            maximumFractionDigits: 0,
        }).format(amount);
    } catch {
        return `${amount.toLocaleString()} VND`;
    }
}

export function formatDate(
    isoString: string | null | undefined,
    locale = 'en',
    options?: Intl.DateTimeFormatOptions,
): string {
    if (!isoString) {
        return '—';
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat(
            resolveIntlLocale(locale),
            options ?? {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            },
        ).format(date);
    } catch {
        return date.toLocaleDateString();
    }
}

export function formatDateTime(
    isoString: string | null | undefined,
    locale = 'en',
): string {
    return formatDate(isoString, locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatPercent(
    value: number,
    locale = 'en',
    fractionDigits = 1,
): string {
    try {
        return new Intl.NumberFormat(resolveIntlLocale(locale), {
            style: 'percent',
            minimumFractionDigits: 0,
            maximumFractionDigits: fractionDigits,
        }).format(value / 100);
    } catch {
        return `${value.toFixed(fractionDigits)}%`;
    }
}

export function formatRelativeTime(
    isoString: string | null | undefined,
    locale = 'en',
): string {
    if (!isoString) {
        return '—';
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const diffSeconds = Math.round((date.getTime() - Date.now()) / 1000);
    const absSeconds = Math.abs(diffSeconds);

    try {
        const rtf = new Intl.RelativeTimeFormat(resolveIntlLocale(locale), {
            numeric: 'auto',
        });

        if (absSeconds < 60) {
            return rtf.format(diffSeconds, 'second');
        }
        const diffMinutes = Math.round(diffSeconds / 60);
        if (Math.abs(diffMinutes) < 60) {
            return rtf.format(diffMinutes, 'minute');
        }
        const diffHours = Math.round(diffSeconds / 3600);
        if (Math.abs(diffHours) < 24) {
            return rtf.format(diffHours, 'hour');
        }
        const diffDays = Math.round(diffSeconds / 86400);
        if (Math.abs(diffDays) < 30) {
            return rtf.format(diffDays, 'day');
        }
        const diffMonths = Math.round(diffDays / 30);
        if (Math.abs(diffMonths) < 12) {
            return rtf.format(diffMonths, 'month');
        }

        return rtf.format(Math.round(diffDays / 365), 'year');
    } catch {
        return formatDate(isoString, locale);
    }
}

export function formatBytes(bytes: number, locale = 'en'): string {
    if (bytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / Math.pow(1024, exponent);

    return `${formatNumber(Number(value.toFixed(exponent === 0 ? 0 : 1)), locale)} ${units[exponent]}`;
}
