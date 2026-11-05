<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps<{
  is_empty?: boolean
  currency?: string
  period_label?: string
  summary?: any
  detailed?: any
}>()

const currency = props.currency ?? 'KES'
const view = ref<'summary' | 'detailed'>('summary')
const granularity = ref<'daily' | 'weekly' | 'monthly'>('monthly')

const money = (n: number) => `${currency} ${Number(n ?? 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}`
const pct = (v: number, max: number) => (max ? (v / max) * 100 : 0)

// ---- sample data (replaced by backend props when provided) ----
const s = computed(() => props.summary ?? {
  compare: 'September',
  revenue: 142800, revenueChange: 12,
  invoices: 64, invoicesChange: 7,
  averageSale: 2231, averageChange: 3,
  collectedPct: 87,
  trend: [
    { label: 'May', value: 68 }, { label: 'Jun', value: 89 }, { label: 'Jul', value: 77 },
    { label: 'Aug', value: 113 }, { label: 'Sep', value: 127 }, { label: 'Oct', value: 143 },
  ],
  outstandingTotal: 18500, outstandingCount: 4,
  aging: [
    { label: 'Not yet due', amount: 11500, late: false },
    { label: '1–7 days late', amount: 7000, late: true },
    { label: '8+ days late', amount: 0, late: true },
  ],
  bestSellers: [
    { name: 'Hoodies', units: 58, revenue: 87000 },
    { name: 'Caps', units: 41, revenue: 32800 },
    { name: 'Tote bags', units: 22, revenue: 13200 },
  ],
  recommendations: [
    { title: 'Restock black M hoodies this week', body: 'They make up 41% of units sold and only 4 are left. Ordering 30 units covers about a month.', impact: 'Protects ~KES 57,000 a month' },
    { title: 'Remind Peter Otieno today', body: 'His KES 7,000 invoice is 4 days late and is your largest unpaid amount. A friendly message is ready.', impact: 'Could recover KES 7,000' },
    { title: 'Pair caps with hoodies', body: 'Most cap buyers this month also bought a hoodie. A bundle price could lift your average sale.', impact: 'Worth testing for a month' },
  ],
})

const d = computed(() => props.detailed ?? {
  billed: 142800, billedCount: 64, received: 124300, receivedPct: 87,
  outstanding: 18500, outstandingCount: 4, failed: 2300, failedCount: 1,
  bestDay: 'Thu 22 Oct, KES 12,500',
  daily: [4,5,6,3,7,5,4,6,7,5,4,6,8,5,4,7,6,5,7,9,6,5,12,7,6,4,8,7,6,9,5].map((v, i) => ({ day: i + 1, amount: v })),
  weekly: [
    { label: '1–7 Oct', billed: 29800, collected: 29800, outstanding: 0 },
    { label: '8–14 Oct', billed: 33400, collected: 26400, outstanding: 7000 },
    { label: '15–21 Oct', billed: 36100, collected: 30700, outstanding: 5400 },
    { label: '22–28 Oct', billed: 31900, collected: 31900, outstanding: 0 },
    { label: '29–31 Oct', billed: 11600, collected: 5500, outstanding: 6100 },
  ],
  pay: { received: 124300, pending: 18500, failed: 2300 },
  products: [
    { name: 'Hoodies', units: 58, revenue: 87000, share: 61 },
    { name: 'Caps', units: 41, revenue: 32800, share: 23 },
    { name: 'Tote bags', units: 22, revenue: 13200, share: 9 },
    { name: 'Other products', units: 20, revenue: 9800, share: 7 },
  ],
  customers: [
    { name: 'Amina Hassan', invoices: 6, billed: 31500, last: '29 Oct' },
    { name: 'Brian Kamau', invoices: 3, billed: 14500, last: '30 Oct' },
    { name: 'Joseph Mutua', invoices: 4, billed: 11200, last: '28 Oct' },
    { name: 'Peter Otieno', invoices: 2, billed: 9400, last: '12 Oct' },
  ],
})

const trendMax = computed(() => Math.max(...s.value.trend.map((t: any) => t.value), 1))
const agingTotal = computed(() => s.value.aging.reduce((a: number, x: any) => a + x.amount, 0) || 1)
const dailyMax = computed(() => Math.max(...d.value.daily.map((x: any) => x.amount), 1))
const payTotal = computed(() => d.value.pay.received + d.value.pay.pending + d.value.pay.failed || 1)
</script>

<template>
  <div class="flex flex-1 flex-col gap-5 p-7">
    <!-- Header -->
    <div class="flex items-center">
      <div class="flex-1">
        <h1 class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em] text-[#202a27]">Insights &amp; Reports</h1>
        <p class="text-sm text-[#6b7773]">
          {{ period_label ?? (view === 'summary' ? 'October 2026 · updated today at 9:00 am' : 'Monthly report · 1–31 October 2026') }}
        </p>
      </div>
      <button v-if="view === 'detailed' || is_empty" class="rounded-xl bg-[#123d32] px-5 py-3 text-sm font-medium text-white">Export</button>
    </div>

    <!-- Tabs -->
    <div class="flex w-max gap-1 rounded-xl border border-[#e6e4dc] bg-white p-1">
      <button
        v-for="t in [{ k: 'summary', l: 'Summary' }, { k: 'detailed', l: 'Detailed report' }]" :key="t.k"
        class="rounded-[9px] px-4 py-[7px] text-sm font-medium"
        :class="view === t.k ? 'bg-[#123d32] text-white' : 'bg-white text-[#202a27]'"
        @click="view = t.k as any"
      >{{ t.l }}</button>
    </div>

    <!-- Detailed filters -->
    <div v-if="view === 'detailed'" class="flex items-center gap-2.5">
      <div class="flex gap-1 rounded-xl border border-[#e6e4dc] bg-white p-1">
        <button
          v-for="g in ['daily', 'weekly', 'monthly']" :key="g"
          class="rounded-lg px-3.5 py-1.5 text-sm capitalize"
          :class="granularity === g ? 'bg-[#e5f4ed] font-semibold text-[#123d32]' : 'text-[#202a27]'"
          @click="granularity = g as any"
        >{{ g }}</button>
      </div>
      <span class="rounded-xl border border-[#e6e4dc] bg-white px-3.5 py-2 text-sm">1 Oct – 31 Oct 2026</span>
      <span class="rounded-xl border border-[#e6e4dc] bg-white px-3.5 py-2 text-sm">All products</span>
      <span class="rounded-xl border border-[#e6e4dc] bg-white px-3.5 py-2 text-sm">All customers</span>
    </div>

    <div v-if="view === 'summary' && !is_empty" class="flex items-center gap-2.5">
      <h2 class="font-display text-xl font-bold tracking-[-0.01em] text-[#202a27]">The numbers</h2>
      <span class="rounded-full bg-[#ecebe5] px-2 py-0.5 text-xs text-[#58625e]">From your records</span>
    </div>

    <!-- Local currency banner -->
    <div v-if="currency !== 'KES'" class="flex flex-col gap-1 rounded-[10px] bg-[#e8f7f5] px-[18px] py-[11px]">
      <p class="text-xs text-[#14665c]">LOCAL CURRENCY VIEW · Preferred currency: KES ▾</p>
      <p class="text-[13px] text-[#2e424d]">Reports can display estimated KES totals · Conversion rate and date shown alongside figures</p>
      <p class="text-[10px] text-[#61757a]">Estimate only · Exchange rates change · Actual PayPal payout may differ</p>
    </div>

    <!-- EMPTY STATE -->
    <section v-if="is_empty" class="flex flex-col items-center gap-3 rounded-[18px] border border-[#e6e4dc] bg-white px-5 py-20 text-center">
      <div class="size-14 rounded-[17px] rounded-bl-[4px] bg-gradient-to-br from-[#15966b] to-[#7be0b6]" />
      <h3 class="font-display text-xl font-bold text-[#202a27]">No sales to report yet</h3>
      <p class="max-w-[591px] text-sm text-[#6b7773]">Once you create an invoice or record a payment in this period, your report will appear here.</p>
      <Link href="/invoices/create" class="rounded-xl bg-[#123d32] px-5 py-2.5 text-sm font-medium text-white">Create invoice</Link>
    </section>

    <!-- SUMMARY -->
    <template v-else-if="view === 'summary'">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <span class="text-xs text-[#6b7773]">Revenue</span>
          <b class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em]">{{ money(s.revenue) }}</b>
          <span class="text-xs text-[#0d6b4a]">+{{ s.revenueChange }}% on {{ s.compare }}</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <span class="text-xs text-[#6b7773]">Invoices issued</span>
          <b class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em]">{{ s.invoices }}</b>
          <span class="text-xs text-[#0d6b4a]">+{{ s.invoicesChange }} on {{ s.compare }}</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <span class="text-xs text-[#6b7773]">Average sale</span>
          <b class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em]">{{ money(s.averageSale) }}</b>
          <span class="text-xs text-[#0d6b4a]">+{{ s.averageChange }}% on {{ s.compare }}</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <span class="text-xs text-[#6b7773]">Collected so far</span>
          <b class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em]">{{ s.collectedPct }}%</b>
          <span class="text-xs text-[#6b7773]">of {{ money(s.revenue) }} billed</span>
        </div>
      </div>

      <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
        <h3 class="font-display text-base font-medium tracking-[-0.01em]">Revenue trend</h3>
        <p class="text-xs text-[#6b7773]">Last 6 months, {{ currency }} thousands</p>
        <div class="flex items-end gap-3">
          <div v-for="(t, i) in s.trend" :key="t.label" class="flex min-w-0 flex-1 flex-col items-center gap-1.5">
            <span class="text-xs" :class="i === s.trend.length - 1 ? 'text-[#0d6b4a]' : 'text-[#6b7773]'">{{ t.value }}</span>
            <div class="w-full rounded-t-lg" :class="i === s.trend.length - 1 ? 'bg-[#15966b]' : 'bg-[#e5f4ed]'" :style="{ height: pct(t.value, trendMax) * 1.6 + 'px' }" />
            <span class="text-xs text-[#6b7773]">{{ t.label }}</span>
          </div>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Outstanding by age</h3>
          <p class="text-xs text-[#6b7773]">{{ money(s.outstandingTotal) }} across {{ s.outstandingCount }} invoices</p>
          <div v-for="a in s.aging" :key="a.label" class="flex flex-col gap-1">
            <div class="flex justify-between text-sm"><span>{{ a.label }}</span><b class="font-semibold">{{ money(a.amount) }}</b></div>
            <div class="h-1.5 w-full overflow-hidden rounded-[9px] bg-[#e6e4dc]">
              <div class="h-1.5 rounded-[9px]" :class="a.late ? 'bg-[#b4432f]' : 'bg-[#15966b]'" :style="{ width: pct(a.amount, agingTotal) + '%' }" />
            </div>
          </div>
        </section>
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Best sellers</h3>
          <div v-for="(p, i) in s.bestSellers" :key="p.name" class="flex items-center gap-2.5">
            <span class="grid size-[26px] place-items-center rounded-full bg-[#e5f4ed] text-sm font-semibold text-[#123d32]">{{ i + 1 }}</span>
            <div class="flex flex-col"><b class="text-sm font-semibold">{{ p.name }}</b><span class="text-xs text-[#6b7773]">{{ p.units }} sold · {{ money(p.revenue) }}</span></div>
          </div>
        </section>
      </div>

      <section class="flex flex-col gap-3.5 rounded-[20px] bg-gradient-to-br from-[#123d32] to-[#1b5b49] px-6 py-[22px]">
        <div class="flex flex-wrap items-center gap-2.5">
          <div class="size-[26px] rounded-lg rounded-bl-[2px] bg-gradient-to-br from-[#15966b] to-[#7be0b6]" />
          <h2 class="font-display text-xl font-bold tracking-[-0.01em] text-white">What MtaaFlow would do next</h2>
          <span class="rounded-full bg-[#2c7a62] px-2 py-0.5 text-xs text-[#cfeee0]">AI suggestion · based on your numbers above</span>
        </div>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
          <div v-for="r in s.recommendations" :key="r.title" class="flex flex-col items-start gap-1.5 rounded-[14px] bg-white p-4">
            <b class="text-sm font-semibold text-[#202a27]">{{ r.title }}</b>
            <p class="text-xs text-[#6b7773]">{{ r.body }}</p>
            <span class="rounded-full bg-[#e5f4ed] px-2 py-0.5 text-xs text-[#0d6b4a]">{{ r.impact }}</span>
          </div>
        </div>
      </section>
    </template>

    <!-- DETAILED -->
    <template v-else>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"><span class="text-xs text-[#6b7773]">Revenue (billed)</span><b class="font-display text-[28px] font-bold leading-[1.15]">{{ money(d.billed) }}</b><span class="text-xs text-[#6b7773]">{{ d.billedCount }} invoices</span></div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"><span class="text-xs text-[#6b7773]">Payments received</span><b class="font-display text-[28px] font-bold leading-[1.15]">{{ money(d.received) }}</b><span class="text-xs text-[#6b7773]">{{ d.receivedPct }}% of billed</span></div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"><span class="text-xs text-[#6b7773]">Outstanding</span><b class="font-display text-[28px] font-bold leading-[1.15]">{{ money(d.outstanding) }}</b><span class="text-xs text-[#6b7773]">{{ d.outstandingCount }} invoices</span></div>
        <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"><span class="text-xs text-[#6b7773]">Failed payments</span><b class="font-display text-[28px] font-bold leading-[1.15]">{{ money(d.failed) }}</b><span class="text-xs text-[#6b7773]">{{ d.failedCount }} transaction{{ d.failedCount === 1 ? '' : 's' }}</span></div>
      </div>

      <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
        <h3 class="font-display text-base font-medium">Daily sales</h3>
        <p class="text-xs text-[#6b7773]">{{ currency }} thousands per day. Best day: {{ d.bestDay }}.</p>
        <div class="flex items-end gap-1.5">
          <div v-for="x in d.daily" :key="x.day" class="flex min-w-0 flex-1 flex-col items-center gap-1">
            <div class="w-full rounded-t" :class="x.amount === dailyMax ? 'bg-[#15966b]' : 'bg-[#e5f4ed]'" :style="{ height: pct(x.amount, dailyMax) * 1.3 + 'px' }" />
            <span class="text-[10px] text-[#6b7773]">{{ x.day }}</span>
          </div>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.45fr_1fr]">
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Revenue and payments by week</h3>
          <table class="w-full text-sm">
            <thead><tr class="border-b border-[#e6e4dc] text-xs text-[#6b7773]"><th class="py-2 text-left font-medium">Week</th><th class="text-right font-medium">Billed</th><th class="text-right font-medium">Collected</th><th class="text-right font-medium">Outstanding</th></tr></thead>
            <tbody>
              <tr v-for="w in d.weekly" :key="w.label" class="border-b border-[#f0efe9]"><td class="py-[11px]">{{ w.label }}</td><td class="text-right">{{ money(w.billed) }}</td><td class="text-right">{{ money(w.collected) }}</td><td class="text-right">{{ money(w.outstanding) }}</td></tr>
              <tr class="font-semibold"><td class="py-[11px]">Total</td><td class="text-right">{{ money(d.billed) }}</td><td class="text-right">{{ money(d.received) }}</td><td class="text-right">{{ money(d.outstanding) }}</td></tr>
            </tbody>
          </table>
        </section>
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Payment summary</h3>
          <div class="flex h-3 gap-0.5">
            <div class="rounded-full bg-[#15966b]" :style="{ width: pct(d.pay.received, payTotal) + '%' }" />
            <div class="rounded-full bg-[#e0a23a]" :style="{ width: pct(d.pay.pending, payTotal) + '%' }" />
            <div class="rounded-full bg-[#b4432f]" :style="{ width: pct(d.pay.failed, payTotal) + '%' }" />
          </div>
          <div class="flex items-center justify-between text-sm"><span class="flex items-center gap-2"><i class="size-2 rounded-full bg-[#15966b]" />Received</span><b class="font-semibold">{{ money(d.pay.received) }}</b></div>
          <div class="flex items-center justify-between text-sm"><span class="flex items-center gap-2"><i class="size-2 rounded-full bg-[#e0a23a]" />Pending</span><b class="font-semibold">{{ money(d.pay.pending) }}</b></div>
          <div class="flex items-center justify-between text-sm"><span class="flex items-center gap-2"><i class="size-2 rounded-full bg-[#b4432f]" />Failed</span><b class="font-semibold">{{ money(d.pay.failed) }}</b></div>
          <p class="text-xs text-[#6b7773]">All card payments were made through PayPal.</p>
        </section>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Product performance</h3>
          <table class="w-full text-sm">
            <thead><tr class="border-b border-[#e6e4dc] text-xs text-[#6b7773]"><th class="py-2 text-left font-medium">Product</th><th class="text-right font-medium">Units</th><th class="text-right font-medium">Revenue</th><th class="text-right font-medium">Share</th></tr></thead>
            <tbody><tr v-for="p in d.products" :key="p.name" class="border-b border-[#f0efe9]"><td class="py-[11px]">{{ p.name }}</td><td class="text-right">{{ p.units }}</td><td class="text-right">{{ money(p.revenue) }}</td><td class="text-right">{{ p.share }}%</td></tr></tbody>
          </table>
        </section>
        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
          <h3 class="font-display text-base font-medium">Customer activity</h3>
          <table class="w-full text-sm">
            <thead><tr class="border-b border-[#e6e4dc] text-xs text-[#6b7773]"><th class="py-2 text-left font-medium">Customer</th><th class="text-right font-medium">Invoices</th><th class="text-right font-medium">Billed</th><th class="text-right font-medium">Last active</th></tr></thead>
            <tbody><tr v-for="c in d.customers" :key="c.name" class="border-b border-[#f0efe9]"><td class="py-[11px]">{{ c.name }}</td><td class="text-right">{{ c.invoices }}</td><td class="text-right">{{ money(c.billed) }}</td><td class="text-right">{{ c.last }}</td></tr></tbody>
          </table>
        </section>
      </div>
    </template>
  </div>
</template>
