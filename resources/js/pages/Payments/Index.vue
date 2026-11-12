<script setup lang="ts">
import { statusLabel, i18n, translate as t } from '@/lib/i18n';
import { Link, router } from '@inertiajs/vue3';
import type {
    ColDef,
    ICellRendererParams,
    ProcessCellForExportParams,
} from 'ag-grid-community';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DataGrid from '@/components/DataGrid.vue';
import { esc } from '@/lib/grid';
import { money as formatCurrency, sumAmounts } from '@/types/business';

type Payment = {
    id: number;
    invoice_id: number;
    paypal_id: string | null;
    customer: string | null;
    invoice: string | null;
    amount: string | null;
    currency: string;
    status: string;
    paid_at: string | null;
    created_at: string | null;
};
type Total = { status: string; currency: string; total: string; count: number };
type Group = 'successful' | 'pending' | 'failed' | 'other';
type Row = Payment & { group: Group | 'pinned' };

const props = withDefaults(
    defineProps<{ payments?: Payment[]; totals?: Total[] }>(),
    { payments: () => [], totals: () => [] },
);

const SUCCESS = ['completed'];
const PENDING = ['pending', 'created', 'approved'];
const FAILED = [
    'failed',
    'declined',
    'denied',
    'voided',
    'canceled',
    'cancelled',
    'error',
];
const groupOf = (status: string): Group =>
    SUCCESS.includes(status)
        ? 'successful'
        : PENDING.includes(status)
          ? 'pending'
          : FAILED.includes(status)
            ? 'failed'
            : 'other';

const rows = computed<Row[]>(() =>
    props.payments.map((p) => ({ ...p, group: groupOf(p.status) })),
);

type Filter = 'all' | 'successful' | 'pending' | 'failed';
const filter = ref<Filter>('all');
const query = ref('');
const grid = ref<InstanceType<typeof DataGrid> | null>(null);
const filters: { key: Filter; label: string }[] = [
    {
        key: 'all',
        get label() {
            return t('status.all');
        },
    },
    {
        key: 'successful',
        get label() {
            return t('interface.successful');
        },
    },
    {
        key: 'pending',
        get label() {
            return t('status.pending');
        },
    },
    {
        key: 'failed',
        get label() {
            return t('status.failed');
        },
    },
];
const predicate = computed(() =>
    filter.value === 'all' ? null : (r: Row) => r.group === filter.value,
);

const badge: Record<Group, string> = {
    successful: 'bg-[#e5f4ed] text-[#0d6b4a]',
    pending: 'bg-[#fbf0da] text-[#b7791f]',
    failed: 'bg-[#f9e4df] text-[#b4432f]',
    other: 'bg-[#eceff5] text-[#2a5f8f]',
};
const tone: Record<Group, string> = {
    successful: 'text-[#202a27]',
    pending: 'text-[#202a27]',
    failed: 'text-[#b4432f]',
    other: 'text-[#202a27]',
};
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const money = formatCurrency;
const fmtDate = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString(i18n.global.locale.value, {
              day: 'numeric',
              month: 'short',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
const shortId = (id: string | null) =>
    !id ? '—' : id.length > 10 ? `${id.slice(0, 4)}…${id.slice(-3)}` : id;

const cardMeta = [
    {
        group: 'successful' as const,
        get label() {
            return t('interface.payments_received');
        },
        dot: 'bg-[#15966b]',
        get note() {
            return t('payments.verified');
        },
    },
    {
        group: 'pending' as const,
        get label() {
            return t('interface.pending_payments');
        },
        dot: 'bg-[#b7791f]',
        get note() {
            return t('payments.awaiting');
        },
    },
    {
        group: 'failed' as const,
        get label() {
            return t('interface.failed_payments');
        },
        dot: 'bg-[#b4432f]',
        get note() {
            return t('payments.retry');
        },
    },
];
const cards = computed(() =>
    cardMeta.map((m) => ({
        ...m,
        rows: props.totals.filter((t) => groupOf(t.status) === m.group),
    })),
);

// Rows left after the grid's own search and filter; the pinned total follows them.
const displayed = ref<Row[]>([]);
const onDisplayed = (list: unknown[]) => (displayed.value = list as Row[]);

const pinned = computed(() => {
    const ok = displayed.value.filter((r) => r.group === 'successful');
    const mixed = new Set(ok.map((r) => r.currency)).size > 1;
    const total = sumAmounts(ok.map((r) => r.amount ?? '0'));
    return [
        {
            id: -1,
            customer:
                t('payments.shownTotal', {
                    count: ok.length,
                    shown: displayed.value.length,
                }) + (mixed ? ' · ' + t('payments.mixedCurrencies') : ''),
            amount: mixed || !ok.length ? null : total,
            currency: ok[0]?.currency ?? '',
            status: '',
            group: 'pinned',
        },
    ] as unknown as Row[];
});

const colDefs: ColDef<Row>[] = [
    {
        field: 'paypal_id',
        get headerName() {
            return t('interface.transaction_id');
        },
        width: 150,
        valueFormatter: (p) => shortId(p.value),
        tooltipValueGetter: (p) => p.data?.paypal_id ?? undefined,
    },
    {
        field: 'customer',
        get headerName() {
            return t('invoices.customer');
        },
        flex: 1,
        minWidth: 220,
        cellClass: 'font-semibold',
        valueFormatter: (p) =>
            p.node?.rowPinned ? String(p.value ?? '') : (p.value ?? '—'),
    },
    {
        field: 'invoice',
        get headerName() {
            return t('interface.invoice');
        },
        width: 120,
        valueFormatter: (p) => p.value ?? '',
    },
    {
        colId: 'when',
        get headerName() {
            return t('common.date');
        },
        width: 170,
        sort: 'desc',
        valueGetter: (p) => p.data?.paid_at ?? p.data?.created_at ?? null,
        valueFormatter: (p) => (p.node?.rowPinned ? '' : fmtDate(p.value)),
    },
    {
        get headerName() {
            return t('interface.method');
        },
        width: 120,
        sortable: false,
        cellRenderer: (p: ICellRendererParams<Row>) =>
            p.node?.rowPinned
                ? ''
                : '<span class="inline-flex items-center gap-1.5 rounded-md bg-[#eceff5] px-2 py-0.5 text-xs text-[#2a5f8f]"><span class="size-[7px] rounded-full bg-[#2a5f8f]"></span>PayPal</span>',
    },
    {
        field: 'amount',
        colId: 'amount',
        get headerName() {
            return t('common.amount');
        },
        width: 170,
        headerClass: 'ag-right-aligned-header',
        cellClass: 'text-right',
        cellRenderer: (p: ICellRendererParams<Row>) => {
            const r = p.data;
            if (!r) return '';
            if (r.amount === null)
                return '<span class="text-[#6b7773]">—</span>';
            const t = r.group === 'pinned' ? 'text-[#202a27]' : tone[r.group];
            return `<span class="font-semibold ${t}">${esc(money(r.amount, r.currency))}</span>`;
        },
    },
    {
        field: 'status',
        colId: 'status',
        get headerName() {
            return t('common.status');
        },
        width: 140,
        cellRenderer: (p: ICellRendererParams<Row>) => {
            const r = p.data;
            if (!r || r.group === 'pinned') return '';
            const label = statusLabel(r.group === 'other' ? r.status : r.group);
            return `<span class="rounded-full px-2.5 py-[3px] text-xs font-semibold ${badge[r.group]}">${esc(label)}</span>`;
        },
    },
];

// CSV: money carries its currency, dates are readable, and text that could be read as a formula is neutralised.
function exportCell(p: ProcessCellForExportParams): string {
    const r = p.node?.data as Row | undefined;
    let v = String(p.value ?? '');
    const id = p.column.getColId();
    if (id === 'amount' && r)
        v = r.amount === null ? '' : `${r.currency} ${r.amount}`;
    if (id === 'when')
        v = p.node?.rowPinned
            ? ''
            : fmtDate((p.value as string | null) ?? null);
    return /^[=+\-@]/.test(v) ? `'${v}` : v;
}

const latestFailed = computed(() => {
    return rows.value.find((payment) => {
        if (payment.group !== 'failed') return false;

        const successfulPayment = rows.value.some(
            (other) =>
                other.invoice_id === payment.invoice_id &&
                other.group === 'successful' &&
                new Date(other.paid_at ?? other.created_at ?? 0).getTime() >
                    new Date(payment.created_at ?? 0).getTime(),
        );

        return !successfulPayment;
    });
});
// While a payment is pending, ask Laravel again every 5 seconds (at most 12 times). Laravel only changes a status after PayPal verifies it.
const hasPending = computed(() =>
    rows.value.some((r) => r.group === 'pending'),
);
let timer: number | undefined;
let ticks = 0;
watch(
    hasPending,
    (pending) => {
        if (typeof window === 'undefined') return;
        window.clearInterval(timer);
        ticks = 0;
        if (!pending) return;
        timer = window.setInterval(() => {
            if (document.hidden) return;
            if (++ticks > 12) {
                if (typeof window === 'undefined') return;
                window.clearInterval(timer);
                return;
            }
            router.reload({ only: ['payments', 'totals'] });
        }, 5000);
    },
    { immediate: true },
);
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div
        class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7"
    >
        <header class="flex items-center gap-3">
            <div class="flex-1">
                <h1
                    class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]"
                >
                    {{ t('interface.payments_transactions') }}
                </h1>
                <p class="text-sm text-[#6b7773]">
                    {{
                        t(
                            'interface.payments_are_confirmed_by_paypal_before_they_appear_as_received',
                        )
                    }}
                </p>
            </div>
            <button
                class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32] disabled:opacity-50"
                :disabled="!rows.length"
                @click="grid?.exportCsv('payments.csv')"
            >
                {{ t('interface.export_csv') }}
            </button>
        </header>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div
                v-for="c in cards"
                :key="c.group"
                class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"
            >
                <p class="flex items-center gap-2 text-xs text-[#6b7773]">
                    <span class="size-2.5 rounded-full" :class="c.dot" />{{
                        c.label
                    }}
                </p>
                <template v-if="c.rows.length">
                    <p
                        v-for="t in c.rows"
                        :key="t.status + t.currency"
                        class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]"
                    >
                        {{ money(t.total, t.currency) }}
                        <span
                            class="block font-sans text-xs font-normal tracking-normal text-[#6b7773]"
                            >{{ t.count }} {{ c.note }}</span
                        >
                    </p>
                </template>
                <p
                    v-else
                    class="font-display text-[28px] leading-[1.15] font-bold text-[#c9d1cd]"
                >
                    —
                </p>
            </div>
        </section>

        <section
            v-if="latestFailed"
            class="flex items-center gap-3 rounded-[14px] border-l-4 border-[#b4432f] bg-[#f9e4df] px-4 py-3"
            role="alert"
        >
            <div class="flex-1">
                <p class="text-sm font-semibold text-[#b4432f]">
                    {{ latestFailed.customer ?? t('interface.a_customer')
                    }}{{ t('interface.s_payment_of')
                    }}{{ money(latestFailed.amount ?? 0, latestFailed.currency)
                    }}{{ t('interface.did_not_go_through') }}
                </p>
                <p class="text-xs text-[#6b7773]">
                    {{
                        t(
                            'interface.open_the_invoice_to_review_it_and_ask_the_client_to_try_again',
                        )
                    }}
                </p>
            </div>
            <Link
                :href="`/invoices/${latestFailed.invoice_id}`"
                class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32]"
                >{{ t('interface.open_invoice') }}</Link
            >
        </section>

        <section
            class="flex flex-col gap-3.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"
        >
            <div class="flex flex-wrap items-center gap-2.5">
                <input
                    v-model="query"
                    type="search"
                    :placeholder="
                        t(
                            'interface.search_by_transaction_id_customer_or_invoice',
                        )
                    "
                    :aria-label="t('interface.search_payments')"
                    class="h-[38px] w-[340px] rounded-xl border border-[#e6e4dc] px-3.5 text-sm outline-none focus:border-[#15966b]"
                />
                <button
                    v-for="f in filters"
                    :key="f.key"
                    @click="filter = f.key"
                    :class="
                        filter === f.key
                            ? 'border-[#123d32] bg-[#123d32] text-white'
                            : 'border-[#e6e4dc] bg-white text-[#202a27]'
                    "
                    class="rounded-full border px-3.5 py-[7px] text-sm font-medium"
                >
                    {{ f.label }}
                </button>
            </div>

            <DataGrid
                ref="grid"
                :row-data="rows"
                :column-defs="colDefs"
                :row-height="52"
                :get-row-id="(r: Row) => String(r.id)"
                :quick-filter="query"
                :external-filter="predicate"
                :pinned-bottom-row-data="pinned"
                :export-cell="exportCell"
                :empty-text="
                    t(
                        'interface.no_payments_yet_they_appear_here_once_a_client_pays_an_invoice',
                    )
                "
                @displayed-rows="onDisplayed"
            />
        </section>
    </div>
</template>
