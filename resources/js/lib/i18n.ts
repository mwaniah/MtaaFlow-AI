import { createI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import de from '@/locales/de.json';
import es from '@/locales/es.json';
import { resolveLocale, isLocale, type Locale } from './locale';

export const i18n = createI18n({
    legacy: false,
    flatJson: true,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, fr, de, es },
    missingWarn: false,
    fallbackWarn: false,
});

export const translate = (
    key: string,
    values: Record<string, string | number> = {},
): string => i18n.global.t(key, values);

export function statusLabel(status: string): string {
    const key = `status.${status}`;
    return i18n.global.te(key)
        ? translate(key)
        : translate('common.unavailable');
}

export function dateTime(value: string): string {
    return new Intl.DateTimeFormat(i18n.global.locale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function currencyName(currency: string): string {
    return (
        new Intl.DisplayNames([i18n.global.locale.value], {
            type: 'currency',
        }).of(currency) ?? currency
    );
}

export function fieldLabel(field: string): string {
    const keys: Record<string, string> = {
        name: 'common.name',
        email: 'common.email',
        phone: 'common.phone',
        address: 'common.address',
        description: 'common.description',
        quantity: 'common.quantity',
        currency: 'common.currency',
        total: 'common.total',
        subtotal: 'invoices.subtotal',
        amount: 'common.amount',
        date: 'common.date',
        customer: 'invoices.customer',
        customer_id: 'invoices.customer',
        customer_name: 'invoices.customer',
        product: 'interface.product',
        product_id: 'interface.product',
        product_name: 'interface.product',
        customers: 'nav.customers',
        products: 'nav.inventory',
        invoices: 'nav.invoices',
        payments: 'nav.payments',
        reminders: 'reminders.title',
        items: 'invoices.items',
        unit_price: 'invoices.unitPrice',
        issue_date: 'invoices.issueDate',
        due_date: 'invoices.dueDate',
        invoice_number: 'invoices.number',
        remaining_balance: 'invoices.balance',
        paid: 'status.paid',
        balance_due: 'fields.balance_due',
        quantity_before: 'fields.quantity_before',
        quantity_after: 'fields.quantity_after',
        quantity_change: 'fields.quantity_change',
        notes: 'inventory.notes',
        sku: 'inventory.sku',
        low_stock_threshold: 'inventory.threshold',
        low_stock_products: 'inventory.lowStock',
        status: 'common.status',
        message: 'reminders.message',
        total_invoices: 'interface.total_invoices',
        paid_invoices: 'status.paid',
        approved_invoices: 'status.approved',
        totals_by_currency: 'common.currency',
        count: 'common.total',
        prepared_at: 'common.date',
        sent_at: 'common.date',
    };
    return translate(keys[field] ?? 'common.unavailable');
}

export function setLocale(locale: Locale, persist = false): void {
    i18n.global.locale.value = locale;
    if (typeof document === 'undefined') return;
    document.documentElement.lang = locale;
    if (persist) {
        try {
            localStorage.setItem('mtaaflow.locale', locale);
        } catch {
            /* Storage may be disabled. */
        }
    }
    document.cookie = `locale=${locale};path=/;max-age=31536000;SameSite=Lax${location.protocol === 'https:' ? ';Secure' : ''}`;
}

export function initializeLocale(): void {
    if (typeof document === 'undefined') return;
    const accountLocale =
        document.querySelector<HTMLMetaElement>('meta[name="account-locale"]')
            ?.content || undefined;
    let saved: unknown;
    try {
        saved = localStorage.getItem('mtaaflow.locale');
    } catch {
        /* Storage may be disabled. */
    }
    saved ??= document.cookie?.match(/(?:^|;\s*)locale=([^;]+)/)?.[1];
    setLocale(
        resolveLocale(accountLocale, saved, navigator.languages),
        isLocale(accountLocale),
    );
    let activeAccountId =
        document.querySelector<HTMLMetaElement>('meta[name="account-id"]')
            ?.content ?? '';
    router.on('navigate', (event) => {
        const user = event.detail.page.props.auth?.user as
            | { id: number; locale?: string }
            | undefined;
        const accountId = String(user?.id ?? '');
        if (accountId !== activeAccountId && isLocale(user?.locale))
            setLocale(user.locale, true);
        activeAccountId = accountId;
    });
}
