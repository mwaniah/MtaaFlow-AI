<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KpiCard from '@/components/KpiCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import { sampleCustomers } from '@/mock/customers';
import type { Customer } from '@/types/customer';

// Real data from the backend replaces the sample automatically.
const props = withDefaults(defineProps<{ customers?: Customer[] }>(), {
    customers: () => sampleCustomers,
});

type Filter = 'all' | 'balance' | 'overdue' | 'up_to_date';
const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'balance', label: 'Has balance' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'up_to_date', label: 'Up to date' },
];

const activeFilter = ref<Filter>('all');
const search = ref('');

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.customers.filter((c) => {
        const matchesFilter =
            activeFilter.value === 'all' ||
            (activeFilter.value === 'balance' && c.outstanding > 0) ||
            (activeFilter.value === 'overdue' && c.status === 'overdue') ||
            (activeFilter.value === 'up_to_date' && c.status === 'up_to_date');
        const matchesSearch =
            !q || [c.name, c.email, c.phone].some((v) => v?.toLowerCase().includes(q));
        return matchesFilter && matchesSearch;
    });
});

const withBalance = computed(() => props.customers.filter((c) => c.outstanding > 0));
const totalOutstanding = computed(() =>
    props.customers.reduce((sum, c) => sum + c.outstanding, 0),
);
const overdueAmount = computed(() =>
    props.customers
        .filter((c) => c.status === 'overdue')
        .reduce((sum, c) => sum + c.outstanding, 0),
);
const openInvoices = computed(() =>
    withBalance.value.reduce((sum, c) => sum + c.open_invoices, 0),
);
const addedThisMonth = computed(() => {
    const now = new Date();
    return props.customers.filter((c) => {
        const d = new Date(c.created_at);
        return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    }).length;
});

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

const formatDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : '–';

const columns =
    'grid grid-cols-[minmax(220px,1fr)_130px_130px_120px_130px_110px] items-center gap-3';
</script>

<template>
    <Head title="Customers" />

    <div class="flex flex-col gap-5 p-7">
        <!-- Header -->
        <div class="flex items-center gap-3">
            <div class="flex flex-1 flex-col gap-1">
                <h1 class="text-h1 text-ink">Customers</h1>
                <p class="text-sm text-ink-muted">
                    {{ customers.length }} customers · {{ formatMoney(totalOutstanding) }} outstanding
                </p>
            </div>
            <!-- The add-customer modal comes next -->
            <Button class="h-10 rounded-xl px-[18px] font-semibold">Add customer</Button>
        </div>

        <!-- Summary cards -->
        <div class="flex flex-col gap-4 md:flex-row">
            <KpiCard
                label="Total customers"
                :value="customers.length"
                :hint="`${addedThisMonth} added this month`"
            />
            <KpiCard
                label="With a balance"
                :value="withBalance.length"
                :hint="`Across ${openInvoices} invoices`"
            />
            <KpiCard
                label="Total outstanding"
                :value="formatMoney(totalOutstanding)"
                :hint="`${formatMoney(overdueAmount)} overdue`"
                danger
            />
        </div>

        <!-- Directory -->
        <div class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-5">
            <div class="flex flex-wrap items-center gap-2.5">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by name, phone or email"
                    class="h-[38px] w-80 rounded-xl border-line bg-paper"
                />
                <button
                    v-for="f in filters"
                    :key="f.key"
                    type="button"
                    class="rounded-full border px-3.5 py-[7px] text-sm font-medium"
                    :class="
                        activeFilter === f.key
                            ? 'border-brand-forest bg-brand-forest text-white'
                            : 'border-line bg-paper text-ink'
                    "
                    @click="activeFilter = f.key"
                >
                    {{ f.label }}
                </button>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[900px]">
                    <div :class="[columns, 'border-b border-line py-2 text-xs text-ink-muted']">
                        <span>Customer</span>
                        <span>Phone</span>
                        <span class="text-right">Total purchases</span>
                        <span class="text-right">Outstanding</span>
                        <span>Status</span>
                        <span>Last purchase</span>
                    </div>

                    <div
                        v-for="c in visible"
                        :key="c.id"
                        :class="[columns, 'border-b border-line py-3 text-sm text-ink']"
                    >
                        <div class="flex items-center gap-2.5">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-mint text-[13px] font-semibold text-brand-forest"
                            >
                                {{ initials(c.name) }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ c.name }}</p>
                                <p class="truncate text-xs text-ink-muted">{{ c.email }}</p>
                            </div>
                        </div>
                        <span>{{ c.phone }}</span>
                        <span class="text-right font-semibold">{{ formatMoney(c.total_purchases) }}</span>
                        <span
                            class="text-right font-semibold"
                            :class="c.outstanding === 0 ? 'text-ink-muted' : ''"
                        >
                            {{ formatMoney(c.outstanding) }}
                        </span>
                        <span><StatusBadge :status="c.status" /></span>
                        <span>{{ formatDate(c.last_purchase) }}</span>
                    </div>

                    <p v-if="visible.length === 0" class="py-8 text-center text-sm text-ink-muted">
                        No customers match your search.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>