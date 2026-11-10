<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatMoney } from '@/lib/money';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type BadgeStatus = 'paid' | 'pending' | 'partial' | 'overdue' | 'draft' | 'cancelled' | 'up_to_date';

interface Stats {
    total_sales: number;
    sales_change_pct: number | null;
    payments_received: number;
    outstanding: number;
    outstanding_invoices: number;
    overdue: number;
    overdue_invoices: number;
}
interface RevenuePoint { month: string; value: number }
interface Insight { title: string; body: string }
interface RecentInvoice { id: number; number: string; customer_name: string; total_amount: number; status: BadgeStatus }
interface Transaction { id: number; label: string; amount: number; failed: boolean }
interface TopSeller { name: string; sold: number }
interface LowStockItem { id: number; product: string; left: number }

// Everything here is sent by DashboardController. With no data it shows empty states.
const props = withDefaults(
    defineProps<{
        stats?: Stats;
        revenue?: RevenuePoint[];
        insights?: Insight[];
        invoices?: RecentInvoice[];
        transactions?: Transaction[];
        topSellers?: TopSeller[];
        lowStock?: LowStockItem[];
    }>(),
    {
        stats: () => ({
            total_sales: 0,
            sales_change_pct: null,
            payments_received: 0,
            outstanding: 0,
            outstanding_invoices: 0,
            overdue: 0,
            overdue_invoices: 0,
        }),
        revenue: () => [],
        insights: () => [],
        invoices: () => [],
        transactions: () => [],
        topSellers: () => [],
        lowStock: () => [],
    },
);

// Greeting uses the logged-in user's first name.
const page = usePage();
const firstName = computed(() => {
    const user = (page.props as unknown as { auth?: { user?: { name?: string } } }).auth?.user;
    return user?.name?.split(' ')[0] ?? '';
});

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

const summary = computed(() => {
    const s = props.stats;
    if (s.outstanding_invoices === 0) return 'Nothing is waiting on payment right now.';
    const base = `${formatMoney(s.outstanding)} is waiting on ${plural(s.outstanding_invoices, 'invoice')}.`;
    if (s.overdue_invoices === 0) return base;
    return `${base} ${s.overdue_invoices === 1 ? 'One is' : `${s.overdue_invoices} are`} overdue.`;
});

const monthName = new Date().toLocaleDateString('en-GB', { month: 'long' });

const kpis = computed(() => {
    const s = props.stats;
    const change =
        s.sales_change_pct === null
            ? 'No earlier month to compare'
            : `${s.sales_change_pct > 0 ? '+' : ''}${s.sales_change_pct}% on last month`;
    return [
        { label: `Total sales · ${monthName}`, value: formatMoney(s.total_sales), note: change, variant: 'featured' },
        { label: 'Payments received', value: formatMoney(s.payments_received), note: 'Verified via PayPal', variant: 'default' },
        { label: 'Outstanding', value: formatMoney(s.outstanding), note: plural(s.outstanding_invoices, 'invoice'), variant: 'default' },
        { label: 'Overdue', value: formatMoney(s.overdue), note: plural(s.overdue_invoices, 'invoice'), variant: 'danger' },
    ];
});

const maxRevenue = computed(() => Math.max(1, ...props.revenue.map((r) => r.value)));
const maxSold = computed(() => Math.max(1, ...props.topSellers.map((p) => p.sold)));
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <!-- Greeting -->
        <div class="flex flex-col gap-1">
            <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">
                Habari{{ firstName ? `, ${firstName}` : '' }}
            </h1>
            <p class="text-sm text-[#6b7773]">{{ summary }}</p>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="flex flex-col gap-2.5 rounded-[18px] p-5"
                :class="
                    kpi.variant === 'featured'
                        ? 'bg-gradient-to-br from-[#123d32] to-[#1b5b49]'
                        : 'border border-[#e6e4dc] bg-white'
                "
            >
                <p class="text-xs" :class="kpi.variant === 'featured' ? 'text-[#a9cdbf]' : 'text-[#6b7773]'">
                    {{ kpi.label }}
                </p>
                <p
                    class="font-display text-[34px] leading-[1.15] font-bold tracking-[-0.02em]"
                    :class="{
                        'text-white': kpi.variant === 'featured',
                        'text-[#202a27]': kpi.variant === 'default',
                        'text-[#b4432f]': kpi.variant === 'danger',
                    }"
                >
                    {{ kpi.value }}
                </p>
                <p class="text-xs" :class="kpi.variant === 'featured' ? 'text-[#a9cdbf]' : 'text-[#6b7773]'">
                    {{ kpi.note }}
                </p>
            </div>
        </div>

        <!-- Revenue + AI insights -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-3">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">Revenue</h2>
                <p class="text-xs text-[#6b7773]">Last 6 months</p>
                <p v-if="revenue.length === 0" class="py-10 text-center text-sm text-[#6b7773]">
                    No revenue yet. It appears once invoices are paid.
                </p>
                <div v-else class="flex items-end gap-3.5 pt-3" style="height: 208px">
                    <div v-for="(r, i) in revenue" :key="r.month" class="flex flex-1 flex-col items-center gap-1.5">
                        <div
                            class="w-full rounded-t-[8px] rounded-b-[3px]"
                            :class="i === revenue.length - 1 ? 'bg-[#15966b]' : 'bg-[#e5f4ed]'"
                            :style="{ height: `${(r.value / maxRevenue) * 165.6}px` }"
                            role="img"
                            :aria-label="`${r.month}: ${formatMoney(r.value)}`"
                        />
                        <span class="text-xs text-[#6b7773]">{{ r.month }}</span>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-2">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    MtaaFlow noticed
                </h2>
                <p v-if="insights.length === 0" class="text-sm text-[#6b7773]">
                    Nothing to report yet. Tips appear as you add invoices.
                </p>
                <div
                    v-for="insight in insights"
                    :key="insight.title"
                    class="flex flex-col gap-[3px] rounded-[14px] border-l-4 border-[#15966b] bg-[#e5f4ed] px-3.5 py-3"
                >
                    <p class="text-sm font-semibold text-[#202a27]">{{ insight.title }}</p>
                    <p class="text-xs text-[#6b7773]">{{ insight.body }}</p>
                </div>
                <button
                    type="button"
                    class="mt-1 self-start rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32] hover:bg-[#e5f4ed] focus-visible:ring-2 focus-visible:ring-[#15966b] focus-visible:outline-none"
                >
                    Ask MtaaFlow
                </button>
            </section>
        </div>

        <!-- Invoices + Transactions -->
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    Recent invoices
                </h2>
                <div class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-xs text-[#6b7773]">
                    <span>No.</span>
                    <span class="flex-1">Customer</span>
                    <span>Amount</span>
                    <span>Status</span>
                </div>
                <p v-if="invoices.length === 0" class="py-4 text-center text-sm text-[#6b7773]">
                    No invoices yet.
                </p>
                <div
                    v-for="inv in invoices"
                    :key="inv.id"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm text-[#202a27]"
                >
                    <span>{{ inv.number }}</span>
                    <span class="flex-1">{{ inv.customer_name }}</span>
                    <span>{{ formatMoney(inv.total_amount) }}</span>
                    <StatusBadge :status="inv.status" />
                </div>
            </section>

            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    Recent transactions
                </h2>
                <div class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-xs text-[#6b7773]">
                    <span class="flex-1">Customer</span>
                    <span>Amount</span>
                </div>
                <p v-if="transactions.length === 0" class="py-4 text-center text-sm text-[#6b7773]">
                    No payments yet.
                </p>
                <div
                    v-for="t in transactions"
                    :key="t.id"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm"
                >
                    <span class="flex-1 text-[#202a27]">{{ t.label }}</span>
                    <span class="font-semibold" :class="t.failed ? 'text-[#b4432f]' : 'text-[#0d6b4a]'">
                        {{ t.failed ? '' : '+' }}{{ formatMoney(t.amount) }}
                    </span>
                </div>
            </section>
        </div>

        <!-- Top sellers + Low stock -->
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    Top sellers
                </h2>
                <p v-if="topSellers.length === 0" class="py-4 text-center text-sm text-[#6b7773]">
                    No sales data yet.
                </p>
                <div v-for="p in topSellers" :key="p.name" class="flex flex-col gap-[5px]">
                    <p class="text-sm text-[#202a27]">{{ p.name }} · {{ p.sold }} sold</p>
                    <div class="h-1.5 w-full overflow-hidden rounded-[9px] bg-[#e6e4dc]">
                        <div class="h-1.5 rounded-[9px] bg-[#15966b]" :style="{ width: `${(p.sold / maxSold) * 100}%` }" />
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    Low stock
                </h2>
                <div class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-xs text-[#6b7773]">
                    <span class="flex-1">Product</span>
                    <span>Stock</span>
                </div>
                <p v-if="lowStock.length === 0" class="py-4 text-center text-sm text-[#6b7773]">
                    Nothing is running low.
                </p>
                <div
                    v-for="item in lowStock"
                    :key="item.id"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm text-[#202a27]"
                >
                    <span class="flex-1">{{ item.product }}</span>
                    <span
                        class="inline-flex rounded-full px-2.5 py-[3px] text-xs font-semibold whitespace-nowrap"
                        :class="item.left <= 5 ? 'bg-[#f9e4df] text-[#b4432f]' : 'bg-[#fbf0da] text-[#b7791f]'"
                    >
                        {{ item.left }} left
                    </span>
                </div>
            </section>
        </div>
    </div>
</template>