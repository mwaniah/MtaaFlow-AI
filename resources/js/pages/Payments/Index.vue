<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ICellRendererParams, ProcessCellForExportParams, RowClickedEvent } from 'ag-grid-community';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DataGrid from '@/components/DataGrid.vue';
import { esc } from '@/lib/grid';

type TxStatus = 'successful' | 'pending' | 'failed';
type Transaction = {
    id: number;
    paypal_id: string;
    customer: string;
    invoice: string;
    paid_at: string;
    amount: number | null;
    currency: string;
    amount_local?: number | null;
    status: TxStatus;
};

const props = withDefaults(
    defineProps<{
        stats?: { received: number; received_count: number; pending: number; pending_count: number; failed: number; failed_count: number; month: string };
        transactions?: Transaction[];
        currency_view?: { code: string; rate: number; as_of: string } | null;
    }>(),
    {
        stats: () => ({ received: 124300, received_count: 21, pending: 18500, pending_count: 4, failed: 2300, failed_count: 1, month: 'October' }),
        currency_view: () => ({ code: 'KES', rate: 129.4, as_of: '2026-11-06T08:00:00Z' }),
        transactions: () => [
            { id: 1, paypal_id: '8XK2…91A', customer: 'Amina Hassan', invoice: 'INV-0041', paid_at: '29 Oct, 2:14 pm', amount: 12500, currency: 'USD', amount_local: 1617500, status: 'successful' },
            { id: 2, paypal_id: '2VM8…65K', customer: 'Daniel Kiprop', invoice: 'INV-0037', paid_at: '30 Oct, 9:02 am', amount: 3100, currency: 'USD', amount_local: 401140, status: 'pending' },
            { id: 3, paypal_id: '4TP7…20C', customer: 'Joseph Mutua', invoice: 'INV-0035', paid_at: '28 Oct, 11:40 am', amount: 4800, currency: 'USD', amount_local: 621120, status: 'successful' },
            { id: 4, paypal_id: '9RB3…44F', customer: 'Lucy Achieng', invoice: 'INV-0040', paid_at: '27 Oct, 6:31 pm', amount: 2300, currency: 'USD', amount_local: 297620, status: 'successful' },
            { id: 5, paypal_id: '6CD1…18Z', customer: 'Lucy Achieng', invoice: 'INV-0040', paid_at: '26 Oct, 5:48 pm', amount: 2300, currency: 'USD', amount_local: 297620, status: 'failed' },
            { id: 6, paypal_id: '7MN4…02B', customer: 'Brian Kamau', invoice: 'INV-0036', paid_at: '14 Oct, 1:05 pm', amount: 8400, currency: 'USD', amount_local: 1086960, status: 'successful' },
        ],
    },
);

type Filter = 'all' | TxStatus;
const filter = ref<Filter>('all');
const query = ref('');
const grid = ref<InstanceType<typeof DataGrid> | null>(null);
const isDev = import.meta.env.DEV;

const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'successful', label: 'Successful' },
    { key: 'pending', label: 'Pending' },
    { key: 'failed', label: 'Failed' },
];

const badge: Record<TxStatus, { label: string; cls: string }> = {
    successful: { label: 'Successful', cls: 'bg-[#e5f4ed] text-[#0d6b4a]' },
    pending: { label: 'Pending', cls: 'bg-[#fbf0da] text-[#b7791f]' },
    failed: { label: 'Failed', cls: 'bg-[#f9e4df] text-[#b4432f]' },
};

const usd = (n: number, currency = 'USD') => `${currency} ${n.toLocaleString()}`;
const local = (t: Transaction) =>
    props.currency_view && t.amount_local != null ? `≈ ${props.currency_view.code} ${t.amount_local.toLocaleString()}` : null;

// Local copy of the rows: Laravel's props replace it, and the grid applies only the differences.
const data = ref<Transaction[]>([...props.transactions]);
watch(() => props.transactions, (t) => (data.value = [...t]));

const rowId = (t: Transaction) => String(t.id);
const statusPredicate = computed(() => (filter.value === 'all' ? null : (t: Transaction) => t.status === filter.value));

// Rows that survive the grid's own search and filter, reported back by DataGrid.
const displayed = ref<Transaction[]>([]);
const onDisplayed = (rows: unknown[]) => (displayed.value = rows as Transaction[]);

// Pinned total row: verified (successful) payments among the visible rows only.
const pinned = computed(() => {
    const verified = displayed.value.filter((t) => t.status === 'successful');
    const mixed = new Set(verified.map((t) => t.currency)).size > 1;
    const sum = mixed ? null : verified.reduce((s, t) => s + (t.amount ?? 0), 0);
    const hasLocal = !mixed && verified.length > 0 && verified.every((t) => t.amount_local != null);
    return [
        {
            id: -1,
            paypal_id: '',
            customer: `Verified total · ${verified.length} of ${displayed.value.length} shown${mixed ? ' · mixed currencies' : ''}`,
            invoice: '',
            paid_at: '',
            amount: sum,
            currency: verified[0]?.currency ?? 'USD',
            amount_local: hasLocal ? verified.reduce((s, t) => s + (t.amount_local ?? 0), 0) : null,
        },
    ] as unknown as Transaction[];
});

const colDefs = computed<ColDef<Transaction>[]>(() => [
    { field: 'paypal_id', headerName: 'Transaction ID', width: 150 },
    { field: 'customer', headerName: 'Customer', flex: 1, minWidth: 200, cellClass: 'font-semibold' },
    { field: 'invoice', headerName: 'Invoice', width: 110 },
    { field: 'paid_at', headerName: 'Date', width: 160, sortable: false },
    {
        headerName: 'Method',
        width: 120,
        sortable: false,
        cellRenderer: (p: ICellRendererParams<Transaction>) =>
            p.node?.rowPinned
                ? ''
                : '<span class="inline-flex items-center gap-1.5 rounded-md bg-[#eceff5] px-2 py-0.5 text-xs text-[#2a5f8f]"><span class="size-[7px] rounded-full bg-[#2a5f8f]"></span>PayPal</span>',
    },
    {
        field: 'amount',
        headerName: 'Amount',
        width: 180,
        headerClass: 'ag-right-aligned-header',
        cellClass: '!flex flex-col items-end justify-center',
        cellRenderer: (p: ICellRendererParams<Transaction>) => {
            const t = p.data;
            if (!t) return '';
            if (t.amount == null) return '<div class="text-[#6b7773]">—</div>';
            const tone = t.status === 'failed' ? 'text-[#b4432f]' : 'text-[#202a27]';
            const main = `<div class="leading-tight font-semibold ${tone}">${esc(usd(t.amount, t.currency))}</div>`;
            const l = local(t);
            return l ? main + `<div class="text-[11px] leading-tight text-[#6b7773]">${esc(l)} (estimate)</div>` : main;
        },
    },
    {
        field: 'status',
        headerName: 'Status',
        width: 130,
        cellRenderer: (p: ICellRendererParams<Transaction>) => {
            const b = p.data?.status ? badge[p.data.status] : null;
            return b ? `<span class="rounded-full px-2.5 py-[3px] text-xs font-semibold ${b.cls}">${esc(b.label)}</span>` : '';
        },
    },
]);

// CSV: amounts carry their currency and the estimate label; text that could be read as a formula is neutralised.
function exportCell(p: ProcessCellForExportParams): string {
    const t = p.node?.data as Transaction | undefined;
    let v = String(p.value ?? '');
    if (p.column.getColId() === 'amount' && t) {
        v = t.amount == null ? '' : usd(t.amount, t.currency);
        const l = local(t);
        if (l) v += ` | ${l} (estimate)`;
    }
    return /^[=+\-@]/.test(v) ? `'${v}` : v;
}

function openTransaction(e: RowClickedEvent<Transaction>) {
    if (e.data && !e.node.rowPinned) router.visit(`/payments/${e.data.id}`);
}

const latestFailed = computed(() => data.value.find((t) => t.status === 'failed'));

function sendNewLink(t: Transaction) {
    // TODO: POST to the backend endpoint that issues a fresh PayPal checkout link.
    console.info('send new link for', t.invoice);
}

// While anything is pending, re-ask Laravel every 5s. Laravel only flips a row after verifying with PayPal.
const hasPending = computed(() => data.value.some((t) => t.status === 'pending'));
let timer: number | undefined;
watch(
    hasPending,
    (pending) => {
        window.clearInterval(timer);
        timer = pending
            ? window.setInterval(() => {
                  if (!document.hidden) router.reload({ only: ['transactions', 'stats'] });
              }, 5000)
            : undefined;
    },
    { immediate: true },
);
onBeforeUnmount(() => window.clearInterval(timer));

// Dev-only: rehearse the "payment verified" moment without a backend.
function simulateVerification() {
    const t = data.value.find((x) => x.status === 'pending');
    if (t) data.value = data.value.map((x) => (x.id === t.id ? { ...x, status: 'successful' } : x));
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <header class="flex items-center gap-3">
            <div class="flex-1">
                <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">Payments &amp; Transactions</h1>
                <p class="text-sm text-[#6b7773]">Payments are confirmed by PayPal before they appear here.</p>
            </div>
            <button v-if="isDev" class="rounded-xl border border-dashed border-[#b7791f] px-3 py-2 text-xs text-[#b7791f]" @click="simulateVerification">Dev: simulate verification</button>
            <button class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32]" @click="grid?.exportCsv('payments.csv')">Export CSV</button>
        </header>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="flex items-center gap-2 text-xs text-[#6b7773]"><span class="size-2.5 rounded-full bg-[#15966b]" />Payments received</p>
                <p class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">{{ usd(stats.received) }}</p>
                <p class="text-xs text-[#6b7773]">{{ stats.received_count }} verified payments · {{ stats.month }}</p>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="flex items-center gap-2 text-xs text-[#6b7773]"><span class="size-2.5 rounded-full bg-[#b7791f]" />Pending payments</p>
                <p class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">{{ usd(stats.pending) }}</p>
                <p class="text-xs text-[#6b7773]">{{ stats.pending_count }} invoices awaiting payment</p>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="flex items-center gap-2 text-xs text-[#6b7773]"><span class="size-2.5 rounded-full bg-[#b4432f]" />Failed payments</p>
                <p class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">{{ usd(stats.failed) }}</p>
                <p class="text-xs text-[#6b7773]">{{ stats.failed_count }} needs a retry</p>
            </div>
        </section>

        <section v-if="currency_view" class="flex flex-col gap-1 rounded-[10px] bg-[#e8f7f5] px-[18px] py-[11px]">
            <p class="text-xs text-[#14665c]">Local currency view · Preferred currency: {{ currency_view.code }} · Rate as of {{ new Date(currency_view.as_of).toLocaleString() }}</p>
            <p class="text-[13px] text-[#2e424d]">Original PayPal amounts remain in USD · Estimated {{ currency_view.code }} equivalent available for each payment</p>
            <p class="text-[10px] text-[#61757a]">Estimate only · Exchange rates change · Actual PayPal payout may differ</p>
        </section>

        <section v-if="latestFailed" class="flex items-center gap-3 rounded-[14px] border-l-4 border-[#b4432f] bg-[#f9e4df] px-4 py-3">
            <div class="flex-1">
                <p class="text-sm font-semibold text-[#b4432f]">{{ latestFailed.customer }}’s payment of {{ usd(latestFailed.amount ?? 0, latestFailed.currency) }} failed</p>
                <p class="text-xs text-[#6b7773]">PayPal declined the card. Send her a fresh checkout link to try again.</p>
            </div>
            <button class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32]" @click="sendNewLink(latestFailed)">Send new link</button>
        </section>

        <section class="flex flex-col gap-3.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <div class="flex flex-wrap items-center gap-2.5">
                <input v-model="query" type="search" placeholder="Search by transaction ID or customer" aria-label="Search transactions"
                    class="h-[38px] w-[320px] rounded-xl border border-[#e6e4dc] px-3.5 text-sm outline-none focus:border-[#15966b]" />
                <button v-for="f in filters" :key="f.key" @click="filter = f.key"
                    :class="filter === f.key ? 'border-[#123d32] bg-[#123d32] text-white' : 'border-[#e6e4dc] bg-white text-[#202a27]'"
                    class="rounded-full border px-3.5 py-[7px] text-sm font-medium">{{ f.label }}</button>
            </div>

            <DataGrid
                ref="grid"
                :row-data="data"
                :column-defs="colDefs"
                :row-height="52"
                :get-row-id="rowId"
                :quick-filter="query"
                :external-filter="statusPredicate"
                :pinned-bottom-row-data="pinned"
                :export-cell="exportCell"
                clickable
                empty-text="No transactions match. Clear the search or pick another filter."
                @row-clicked="openTransaction"
                @displayed-rows="onDisplayed"
            />
        </section>
    </div>
</template>
