export function formatVnd(amount: number, locale: 'vi' | 'en' = 'vi'): string {
    const safeAmount = Math.max(
        0,
        Math.trunc(Number.isFinite(amount) ? amount : 0),
    );
    const formatted = new Intl.NumberFormat(
        locale === 'vi' ? 'vi-VN' : 'en-US',
    ).format(safeAmount);

    return `${formatted}đ`;
}
