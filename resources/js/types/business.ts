import { i18n } from '@/lib/i18n';
import { formatDate } from '@/lib/locale';
export type CustomerRecord = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    created_at?: string;
};
export type PaymentRecord = {
    id: number;
    amount: string;
    currency: string;
    status: string;
    paid_at: string | null;
    created_at?: string;
    paypal_order_id?: string | null;
    idempotency_key?: string;
    paypal_approval_url?: string | null;
    invoice?: InvoiceRecord;
};
export type InvoiceRecord = {
    id: number;
    invoice_number: string;
    customer_id: number;
    currency: string;
    subtotal: string;
    total: string;
    status: string;
    issue_date: string;
    due_date: string | null;
    created_at?: string;
    customer?: CustomerRecord;
    items?: {
        description: string;
        quantity: string | number;
        unit_price: string;
        amount: string;
    }[];
    payments?: PaymentRecord[];
};
export type CurrencyTotal = {
    currency: string;
    total: string | number;
    paymentCount?: number;
};
export const money = (amount: string | number, currency: string) =>
    new Intl.NumberFormat(i18n.global.locale.value, {
        style: 'currency',
        currency,
        currencyDisplay: 'code',
        maximumFractionDigits: 4,
    }).format(Number(amount));
export const date = (value: string | null | undefined) =>
    value
        ? formatDate(value, i18n.global.locale.value)
        : i18n.global.t('common.notSet');
const units = (value: string | number): bigint => {
    const [whole, fraction = ''] = String(value).split('.');
    return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0').slice(0, 4));
};
const decimal = (value: bigint): string =>
    `${value / 10000n}.${String(value % 10000n).padStart(4, '0')}`;
export function balance(invoice: InvoiceRecord): string {
    const paid = (invoice.payments ?? [])
        .filter(
            (p) => p.status === 'completed' && p.currency === invoice.currency,
        )
        .reduce((sum, p) => sum + units(p.amount), 0n);
    const remaining = units(invoice.total) - paid;
    return decimal(remaining > 0n ? remaining : 0n);
}
export function totals(invoices: InvoiceRecord[]): CurrencyTotal[] {
    const grouped = new Map<string, bigint>();
    for (const invoice of invoices)
        grouped.set(
            invoice.currency,
            (grouped.get(invoice.currency) ?? 0n) + units(invoice.total),
        );
    return [...grouped].map(([currency, total]) => ({
        currency,
        total: decimal(total),
    }));
}

export const sumAmounts = (values: (string | number)[]): string =>
    decimal(values.reduce<bigint>((sum, value) => sum + units(value), 0n));
