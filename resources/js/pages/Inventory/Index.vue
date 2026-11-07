<script setup lang="ts">
import type { ColDef, ICellRendererParams } from 'ag-grid-community';
import { computed, ref } from 'vue';
import DataGrid from '@/components/DataGrid.vue';
import { esc } from '@/lib/grid';

type Product = { name: string; sku: string; category: string; price: number; stock: number; alert: number; tint: string };

const products: Product[] = [
    { name: 'Hoodie, black M', sku: 'HD-BLK-M', category: 'Apparel', price: 1500, stock: 4, alert: 10, tint: '#cfeee0' },
    { name: 'Hoodie, grey L', sku: 'HD-GRY-L', category: 'Apparel', price: 1500, stock: 18, alert: 10, tint: '#cfeee0' },
    { name: 'Cap, navy', sku: 'CP-NVY', category: 'Headwear', price: 800, stock: 9, alert: 10, tint: '#fbe6b5' },
    { name: 'Cap, black', sku: 'CP-BLK', category: 'Headwear', price: 800, stock: 26, alert: 10, tint: '#fbe6b5' },
    { name: 'Tote bag, canvas', sku: 'TB-CNV', category: 'Bags', price: 600, stock: 34, alert: 10, tint: '#d9e4fb' },
    { name: 'Water bottle, steel', sku: 'WB-STL', category: 'Accessories', price: 950, stock: 0, alert: 10, tint: '#eadcf5' },
];

const restock = [
    { name: 'Hoodie, black M', note: '4 left · about 6 days of stock', order: 'Order 30 units', est: 'Est. USD 27,000' },
    { name: 'Cap, navy', note: '9 left · about 10 days of stock', order: 'Order 20 units', est: 'Est. USD 10,000' },
    { name: 'Water bottle, steel', note: 'Out of stock · 12 sold last month', order: 'Order 25 units', est: 'Est. USD 15,000' },
];

type Status = 'all' | 'in' | 'low' | 'out';
const filter = ref<Status>('all');
const query = ref('');
const filters: { key: Status; label: string }[] = [
    { key: 'all', label: 'All' }, { key: 'in', label: 'In stock' },
    { key: 'low', label: 'Low stock' }, { key: 'out', label: 'Out of stock' },
];

const statusOf = (p: Product): Exclude<Status, 'all'> => (p.stock === 0 ? 'out' : p.stock < p.alert ? 'low' : 'in');
const badge = {
    in: { label: 'In stock', cls: 'bg-[#e5f4ed] text-[#0d6b4a]', bar: '#15966b' },
    low: { label: 'Low stock', cls: 'bg-[#fbf0da] text-[#b7791f]', bar: '#b7791f' },
    out: { label: 'Out of stock', cls: 'bg-[#f9e4df] text-[#b4432f]', bar: '#b4432f' },
};
const initials = (n: string) => n.split(/[ ,]+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const usd = (n: number) => `USD ${n.toLocaleString()}`;

const rows = computed(() =>
    products.filter((p) => (filter.value === 'all' || statusOf(p) === filter.value) &&
        `${p.name} ${p.sku}`.toLowerCase().includes(query.value.toLowerCase())),
);
const colDefs: ColDef<Product>[] = [
    {
        field: 'name',
        headerName: 'Product',
        flex: 1,
        minWidth: 220,
        cellRenderer: (p: ICellRendererParams<Product>) => {
            const r = p.data;
            if (!r) return '';
            return `<div class="flex items-center gap-2.5"><div class="font-display flex size-10 shrink-0 items-center justify-center rounded-[10px] text-base font-medium text-[#123d32]" style="background:${esc(r.tint)}">${esc(initials(r.name))}</div><div class="leading-tight"><div class="font-semibold">${esc(r.name)}</div><div class="text-xs text-[#6b7773]">SKU ${esc(r.sku)}</div></div></div>`;
        },
    },
    { field: 'category', headerName: 'Category', width: 140 },
    {
        field: 'price',
        headerName: 'Price',
        width: 130,
        headerClass: 'ag-right-aligned-header',
        cellClass: 'text-right font-semibold',
        valueFormatter: (p) => usd(p.value),
    },
    {
        field: 'stock',
        headerName: 'Stock',
        width: 130,
        cellClass: '!flex flex-col justify-center',
        cellRenderer: (p: ICellRendererParams<Product>) => {
            const r = p.data;
            if (!r) return '';
            const pct = Math.max(Math.min((r.stock / 30) * 100, 100), 3);
            return `<div class="leading-tight font-semibold">${esc(r.stock)}</div><div class="mt-1 h-[5px] w-[60px] overflow-hidden rounded-[9px] bg-[#e6e4dc]"><div class="h-[5px] rounded-[9px]" style="width:${pct}%;background:${badge[statusOf(r)].bar}"></div></div>`;
        },
    },
    {
        headerName: 'Status',
        width: 140,
        sortable: false,
        cellRenderer: (p: ICellRendererParams<Product>) => {
            const b = p.data ? badge[statusOf(p.data)] : null;
            return b ? `<span class="rounded-full px-2.5 py-[3px] text-xs font-semibold ${b.cls}">${esc(b.label)}</span>` : '';
        },
    },
];

const units = computed(() => products.reduce((s, p) => s + p.stock, 0));
const low = computed(() => products.filter((p) => statusOf(p) === 'low').length);
const out = computed(() => products.filter((p) => statusOf(p) === 'out'));
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <header class="flex items-center gap-3">
            <div class="flex-1">
                <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">Inventory &amp; Stock</h1>
                <p class="text-sm text-[#6b7773]">{{ products.length }} products · {{ units }} units in stock</p>
            </div>
            <button class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32]">Export CSV</button>
            <button disabled title="Coming soon" class="cursor-not-allowed rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white opacity-50">Add product</button>
        </header>

        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="text-xs text-[#6b7773]">Total products</p>
                <p class="font-display text-[28px] font-bold text-[#202a27]">{{ products.length }}</p>
                <p class="text-xs text-[#6b7773]">3 added this month</p>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="text-xs text-[#6b7773]">Total stock units</p>
                <p class="font-display text-[28px] font-bold text-[#202a27]">{{ units }}</p>
                <p class="text-xs text-[#6b7773]">Worth USD 241,500</p>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="text-xs text-[#6b7773]">Low-stock products</p>
                <p class="font-display text-[28px] font-bold text-[#b7791f]">{{ low }}</p>
                <p class="text-xs text-[#6b7773]">Below their alert level</p>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
                <p class="text-xs text-[#6b7773]">Out of stock</p>
                <p class="font-display text-[28px] font-bold text-[#b4432f]">{{ out.length }}</p>
                <p class="text-xs text-[#6b7773]">{{ out.map((p) => p.name).join(', ') || 'Nothing out' }}</p>
            </div>
        </section>

        <section class="flex flex-col gap-3.5 rounded-[20px] bg-gradient-to-br from-[#123d32] to-[#1b5b49] px-6 py-[22px]">
            <div class="flex flex-col gap-0.5">
                <span class="w-fit rounded-full bg-[#2c7a62] px-2.5 py-0.5 text-xs text-[#cfeee0]">AI recommendation</span>
                <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-white">Restock these {{ restock.length }} products this week</h2>
                <p class="text-xs text-[#a9cdbf]">Based on your last 30 days of sales. You decide what to order.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div v-for="r in restock" :key="r.name" class="flex flex-col gap-1 rounded-[14px] bg-white px-4 py-3.5">
                    <p class="text-sm font-semibold text-[#202a27]">{{ r.name }}</p>
                    <p class="text-xs text-[#6b7773]">{{ r.note }}</p>
                    <p class="flex items-center gap-2 pt-1.5">
                        <span class="text-sm font-semibold text-[#0d6b4a]">{{ r.order }}</span>
                        <span class="text-xs text-[#6b7773]">{{ r.est }}</span>
                    </p>
                </div>
            </div>
        </section>

        <section class="flex flex-col gap-3.5 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <div class="flex flex-wrap items-center gap-2.5">
                <input v-model="query" type="search" placeholder="Search products"
                    class="h-[38px] w-[260px] rounded-xl border border-[#e6e4dc] px-3.5 text-sm outline-none focus:border-[#15966b]" />
                <button v-for="f in filters" :key="f.key" @click="filter = f.key"
                    :class="filter === f.key ? 'border-[#123d32] bg-[#123d32] text-white' : 'border-[#e6e4dc] bg-white text-[#202a27]'"
                    class="rounded-full border px-3 py-[7px] text-sm font-medium">{{ f.label }}</button>
            </div>

            <DataGrid
                :row-data="rows"
                :column-defs="colDefs"
                :row-height="56"
                empty-text="No products match. Clear the search or pick another filter."
            />
        </section>
    </div>
</template>
