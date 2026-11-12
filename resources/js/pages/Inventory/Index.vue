<script setup lang="ts">
import { dateTime, translate as t } from '@/lib/i18n';
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { index, store, update, destroy } from '@/routes/products';
import {
    index as adjustmentIndex,
    store as adjust,
} from '@/routes/products/adjustments';
import { requestJson } from '@/lib/api';
type Product = {
    id: number;
    name: string;
    sku: string | null;
    description: string | null;
    quantity: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
};
type Adjustment = {
    id: number;
    quantity_change: number;
    quantity_after: number;
    notes: string | null;
    created_at: string;
};
const products = ref<Product[]>([]);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const selected = ref<Product | null>(null);
const editing = ref(false);
const adjustments = ref<Adjustment[]>([]);
const form = ref({
    name: '',
    sku: '',
    description: '',
    quantity: 0,
    low_stock_threshold: 0,
});
const stock = ref({ quantity_change: 1, notes: '' });
const rows = computed(() =>
    products.value.filter((p) =>
        `${p.name} ${p.sku ?? ''}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
async function load() {
    products.value = (
        await requestJson<{ products: Product[] }>(index())
    ).products;
}
onMounted(async () => {
    try {
        await load();
    } catch (e) {
        error.value = String(e);
    } finally {
        loading.value = false;
    }
});
function edit(product?: Product) {
    selected.value = product ?? null;
    form.value = {
        name: product?.name ?? '',
        sku: product?.sku ?? '',
        description: product?.description ?? '',
        quantity: 0,
        low_stock_threshold: product?.low_stock_threshold ?? 0,
    };
    editing.value = true;
}
async function run(action: () => Promise<void>) {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await action();
        await load();
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}
function save() {
    void run(async () => {
        if (selected.value) {
            const { quantity: _quantity, ...data } = form.value;
            await requestJson(update(selected.value.id), data);
        } else await requestJson(store(), form.value);
        editing.value = false;
        notice.value = t('interface.product_saved');
    });
}
function remove(product: Product) {
    if (window.confirm(t('inventory.deletePrompt', { name: product.name })))
        void run(async () => {
            await requestJson(destroy(product.id));
            selected.value = null;
            notice.value = t('interface.product_deleted');
        });
}
async function view(product: Product) {
    selected.value = product;
    editing.value = false;
    error.value = '';
    try {
        adjustments.value = (
            await requestJson<{ adjustments: { data: Adjustment[] } }>(
                adjustmentIndex(product.id),
            )
        ).adjustments.data;
    } catch (e) {
        error.value = String(e);
    }
}
function changeStock() {
    if (!selected.value) return;
    const product = selected.value;
    void run(async () => {
        await requestJson(adjust(product.id), stock.value);
        await load();
        await view(products.value.find((p) => p.id === product.id)!);
        notice.value = t('interface.stock_adjustment_saved');
    });
}
</script>
<template>
    <div class="flex flex-col gap-5 p-7">
        <Head :title="t('interface.inventory_stock')" />
        <header class="flex flex-wrap justify-between gap-3">
            <div>
                <h1 class="font-display text-[28px] font-bold">
                    {{ t('interface.inventory_stock') }}
                </h1>
                <p class="text-sm text-[#6b7773]">
                    {{ products.length }}{{ t('interface.products')
                    }}{{ products.reduce((sum, p) => sum + p.quantity, 0)
                    }}{{ t('interface.units_in_stock') }}
                </p>
            </div>
            <button
                class="rounded-xl bg-[#15966b] px-5 py-2.5 font-semibold text-white"
                @click="edit()"
            >
                {{ t('inventory.add') }}
            </button>
        </header>
        <p v-if="error" role="alert" class="text-[#b4432f]">{{ error }}</p>
        <p v-if="notice" role="status" class="text-[#15966b]">{{ notice }}</p>
        <form
            v-if="editing"
            class="grid gap-4 rounded-[18px] border bg-white p-5 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <label
                v-for="key in ['name', 'sku', 'description'] as const"
                :key="key"
                class="flex flex-col gap-1 capitalize"
                >{{ key
                }}<input
                    v-model="form[key]"
                    :required="key === 'name'"
                    class="rounded-xl border px-3 py-2" /></label
            ><label class="flex flex-col gap-1"
                >{{ t('interface.low_stock_threshold')
                }}<input
                    v-model.number="form.low_stock_threshold"
                    type="number"
                    min="0"
                    required
                    class="rounded-xl border px-3 py-2" /></label
            ><label v-if="!selected" class="flex flex-col gap-1"
                >{{ t('interface.initial_quantity')
                }}<input
                    v-model.number="form.quantity"
                    type="number"
                    min="0"
                    required
                    class="rounded-xl border px-3 py-2"
            /></label>
            <div class="flex gap-3">
                <button
                    :disabled="busy"
                    class="rounded-xl bg-[#15966b] px-5 py-2 text-white disabled:opacity-50"
                >
                    {{ t('interface.save_product') }}</button
                ><button type="button" @click="editing = false">
                    {{ t('common.cancel') }}
                </button>
            </div>
        </form>
        <section
            class="overflow-x-auto rounded-[18px] border border-[#e6e4dc] bg-white p-5"
        >
            <input
                v-model="search"
                :placeholder="t('interface.search_products')"
                :aria-label="t('interface.search_products')"
                class="mb-4 rounded-xl border px-3 py-2"
            />
            <p v-if="loading">{{ t('interface.loading_products') }}</p>
            <p v-else-if="!rows.length && !error" class="py-6 text-[#6b7773]">
                {{ t('interface.no_products_found') }}
            </p>
            <table v-if="rows.length" class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th class="p-3">{{ t('interface.product') }}</th>
                        <th>{{ t('inventory.sku') }}</th>
                        <th>{{ t('inventory.stock') }}</th>
                        <th>{{ t('interface.threshold') }}</th>
                        <th>{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in rows" :key="p.id" class="border-t">
                        <td class="p-3">{{ p.name }}</td>
                        <td>{{ p.sku || '—' }}</td>
                        <td :class="p.is_low_stock ? 'text-[#b4432f]' : ''">
                            {{ p.quantity }}
                        </td>
                        <td>{{ p.low_stock_threshold }}</td>
                        <td class="flex flex-wrap gap-3 p-3">
                            <button class="underline" @click="view(p)">
                                {{ t('interface.stock_history') }}</button
                            ><button class="underline" @click="edit(p)">
                                {{ t('common.edit') }}</button
                            ><button
                                :disabled="busy"
                                class="text-[#b4432f] underline"
                                @click="remove(p)"
                            >
                                {{ t('common.delete') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section
            v-if="selected && !editing"
            class="space-y-4 rounded-[18px] border bg-white p-5"
        >
            <h2 class="font-display text-xl font-bold">
                {{ t('interface.stock') }}{{ selected.name }}
            </h2>
            <p>{{ selected.description }}</p>
            <form class="flex flex-wrap gap-3" @submit.prevent="changeStock">
                <label
                    >{{ t('interface.quantity_change')
                    }}<input
                        v-model.number="stock.quantity_change"
                        type="number"
                        required
                        class="ml-2 rounded-xl border px-3 py-2" /></label
                ><input
                    v-model="stock.notes"
                    :aria-label="t('interface.adjustment_notes')"
                    :placeholder="t('interface.reason_for_adjustment')"
                    maxlength="500"
                    class="rounded-xl border px-3 py-2"
                /><button
                    :disabled="busy"
                    class="rounded-xl bg-[#15966b] px-5 py-2 text-white"
                >
                    {{ t('interface.save_adjustment') }}
                </button>
            </form>
            <p v-if="!adjustments.length">
                {{ t('interface.no_stock_adjustments_recorded') }}
            </p>
            <p v-for="a in adjustments" :key="a.id" class="border-t py-2">
                {{ dateTime(a.created_at) }}: {{ a.quantity_change }} →
                {{ a.quantity_after }}{{ t('interface.units') }}{{ a.notes }}
            </p>
        </section>
    </div>
</template>
