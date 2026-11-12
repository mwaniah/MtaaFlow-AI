export const supportedLocales = ['en', 'fr', 'de', 'es'] as const;
export type Locale = (typeof supportedLocales)[number];

export function isLocale(value: unknown): value is Locale {
    return (
        typeof value === 'string' && supportedLocales.includes(value as Locale)
    );
}

export function detectLocale(
    saved: unknown,
    languages: readonly string[],
): Locale {
    if (isLocale(saved)) return saved;
    if (typeof saved === 'string' && saved.length > 0) return 'en';
    for (const language of languages) {
        const base = language.toLowerCase().split(/[-_]/)[0];
        if (isLocale(base)) return base;
    }
    return 'en';
}

export function resolveLocale(
    account: unknown,
    saved: unknown,
    languages: readonly string[],
): Locale {
    return detectLocale(account ?? saved, languages);
}

export function formatMoney(
    value: string | number,
    currency: string,
    locale: Locale,
): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
    }).format(Number(value));
}

export function formatDate(value: string, locale: Locale): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(value.length === 10 ? `${value}T00:00:00Z` : value));
}
