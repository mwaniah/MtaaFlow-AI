<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
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

// Placeholder data taken from the Figma frame. Replace with Inertia props from DashboardController.
const greetingName = 'Wanjiru';
const summary = 'USD 18,500 is waiting on 4 invoices. One is overdue.';

const kpis = [
    { label: 'Total sales · October', value: 'USD 142,800', note: '+12% on September', variant: 'featured' },
    { label: 'Payments received', value: 'USD 124,300', note: 'Verified via PayPal', variant: 'default' },
    { label: 'Outstanding', value: 'USD 18,500', note: '4 invoices', variant: 'default' },
    { label: 'Overdue', value: 'USD 7,000', note: '1 invoice', variant: 'danger' },
];

const revenue = [
    { month: 'May', value: 75.6 },
    { month: 'Jun', value: 99 },
    { month: 'Jul', value: 86.4 },
    { month: 'Aug', value: 126 },
    { month: 'Sep', value: 140.4 },
    { month: 'Oct', value: 165.6 },
];
const maxRevenue = Math.max(...revenue.map((r) => r.value));

const insights = [
    { title: 'Hoodies drive 41% of sales.', body: 'Only 4 left. At this pace they sell out in 6 days.' },
    { title: 'Peter Otieno is 4 days late.', body: 'A friendly reminder is drafted and ready.' },
];

const invoices: { no: string; customer: string; amount: string; status: BadgeStatus }[] = [
    { no: 'INV-0043', customer: 'Brian Kamau', amount: 'USD 6,100', status: 'pending' },
    { no: 'INV-0041', customer: 'Amina Hassan', amount: 'USD 12,500', status: 'paid' },
    { no: 'INV-0039', customer: 'Peter Otieno', amount: 'USD 7,000', status: 'overdue' },
    { no: 'INV-0038', customer: 'Grace Njeri', amount: 'USD 3,200', status: 'draft' },
];

const transactions = [
    { label: 'Amina Hassan · PayPal 8XK2…91A', amount: '+USD 12,500', failed: false },
    { label: 'Joseph Mutua · PayPal 4TP7…20C', amount: '+USD 4,800', failed: false },
    { label: 'Lucy Achieng · PayPal failed, retry', amount: 'USD 2,300', failed: true },
];

const topSellers = [
    { name: 'Hoodies', sold: 58 },
    { name: 'Caps', sold: 41 },
    { name: 'Tote bags', sold: 22 },
];
const maxSold = Math.max(...topSellers.map((p) => p.sold));

const lowStock = [
    { product: 'Hoodie, black M', left: 4, tone: 'danger' },
    { product: 'Cap, navy', left: 9, tone: 'warn' },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <!-- Greeting -->
        <div class="flex flex-col gap-1">
            <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">
                Habari, {{ greetingName }}
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

        <!-- Local currency estimate -->
        <div class="flex flex-col gap-1 rounded-[10px] bg-[#e8f7f5] px-[18px] py-[11px]">
            <p class="text-xs text-[#14665c]">Local currency view · Preferred currency: KES ▾</p>
            <p class="text-[13px] text-[#2e424d]">
                Payments received: USD 124,300 · Estimated KES value shown using latest available rate
            </p>
            <p class="text-[10px] text-[#61757a]">
                Estimate only · Exchange rates change · Actual PayPal payout may differ
            </p>
        </div>

        <!-- Revenue + AI insights -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-3">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">Revenue</h2>
                <p class="text-xs text-[#6b7773]">Last 6 months, USD thousands</p>
                <div class="flex items-end gap-3.5 pt-3" style="height: 208px">
                    <div v-for="(r, i) in revenue" :key="r.month" class="flex flex-1 flex-col items-center gap-1.5">
                        <div
                            class="w-full rounded-t-[8px] rounded-b-[3px]"
                            :class="i === revenue.length - 1 ? 'bg-[#15966b]' : 'bg-[#e5f4ed]'"
                            :style="{ height: `${(r.value / maxRevenue) * 165.6}px` }"
                            role="img"
                            :aria-label="`${r.month}: ${r.value}k`"
                        />
                        <span class="text-xs text-[#6b7773]">{{ r.month }}</span>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-2">
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">
                    MtaaFlow noticed
                </h2>
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
                <div
                    v-for="inv in invoices"
                    :key="inv.no"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm text-[#202a27]"
                >
                    <span>{{ inv.no }}</span>
                    <span class="flex-1">{{ inv.customer }}</span>
                    <span>{{ inv.amount }}</span>
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
                <div
                    v-for="t in transactions"
                    :key="t.label"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm"
                >
                    <span class="flex-1 text-[#202a27]">{{ t.label }}</span>
                    <span class="font-semibold" :class="t.failed ? 'text-[#b4432f]' : 'text-[#0d6b4a]'">
                        {{ t.amount }}
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
                <div
                    v-for="item in lowStock"
                    :key="item.product"
                    class="flex items-center gap-3 border-b border-[#e6e4dc] py-[11px] text-sm text-[#202a27]"
                >
                    <span class="flex-1">{{ item.product }}</span>
                    <span
                        class="inline-flex rounded-full px-2.5 py-[3px] text-xs font-semibold whitespace-nowrap"
                        :class="item.tone === 'danger' ? 'bg-[#f9e4df] text-[#b4432f]' : 'bg-[#fbf0da] text-[#b7791f]'"
                    >
                        {{ item.left }} left
                    </span>
                </div>
            </section>
        </div>
    </div>
</template>