<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InvoiceItemsGrid from '@/components/InvoiceItemsGrid.vue';
import type { DraftItem } from '@/components/InvoiceItemsGrid.vue';

type Customer = { id: number; name: string };
type Draft = { customer_name: string | null; customer_id: number | null; currency: string; issue_date: string; due_date: string; items: DraftItem[] };

// Defaults are mock data. Laravel's real `customers` and `currencies` props replace them.
const props = withDefaults(defineProps<{ customers?: Customer[]; currencies?: string[] }>(), {
    customers: () => [{ id: 1, name: 'Brian Kamau' }, { id: 2, name: 'Amina Hassan' }],
    currencies: () => ['USD', 'EUR', 'GBP'],
});

const today = () => new Date().toLocaleDateString('en-CA');
const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toLocaleDateString('en-CA');

const form = useForm({
    customer_id: null as number | null,
    currency: 'USD',
    issue_date: today(),
    due_date: inDays(14),
    items: [] as DraftItem[],
});

const prompt = ref('');
const drafting = ref(false);
const error = ref('');
const notice = ref('');
const unmatchedCustomer = ref<string | null>(null);
const aiItems = ref<DraftItem[]>([]);
const total = ref(0);

function applyDraft(d: Draft) {
    form.customer_id = d.customer_id ?? form.customer_id;
    unmatchedCustomer.value = d.customer_id == null ? d.customer_name : null;
    form.currency = d.currency ?? form.currency;
    form.issue_date = d.issue_date ?? form.issue_date;
    form.due_date = d.due_date ?? form.due_date;
    aiItems.value = d.items;
}

const sampleDraft = (): Draft => ({
    customer_name: 'Brian',
    customer_id: props.customers.find((c) => c.name.toLowerCase().includes('brian'))?.id ?? null,
    currency: 'USD',
    issue_date: today(),
    due_date: inDays(14),
    items: [{ description: 'Hoodie', quantity: 3, unit_price: 25 }],
});

async function draftWithAi() {
    if (!prompt.value.trim()) return;
    drafting.value = true;
    error.value = '';
    notice.value = '';
    try {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const res = await fetch('/invoices/draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify({ prompt: prompt.value }),
        });
        if (res.status === 404) {
            applyDraft(sampleDraft());
            notice.value = 'The AI draft endpoint is not live yet, so this is sample data.';
            return;
        }
        const body = await res.json();
        if (!res.ok) throw new Error(body.message ?? 'The AI could not draft this invoice. Try again.');
        applyDraft(body as Draft);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong. Try again.';
    } finally {
        drafting.value = false;
    }
}

function onItems(items: DraftItem[], t: number) {
    form.items = items;
    total.value = t;
}

const canApprove = computed(() => !!form.customer_id && form.items.length > 0 && total.value > 0 && !form.processing);

function approve() {
    // TODO: use the generated route helper once the backend route exists.
    form.post('/invoices');
}
</script>

<template>
    <Head title="New Invoice" />
    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <header>
            <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">New invoice</h1>
            <p class="text-sm text-[#6b7773]">Describe the invoice and MtaaFlow AI drafts it. Nothing is saved until you approve it.</p>
        </header>

        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <label for="prompt" class="text-sm font-semibold text-[#202a27]">What do you want to invoice?</label>
            <textarea id="prompt" v-model="prompt" rows="2" placeholder="Create an invoice for Brian for three hoodies at $25 each, due in 14 days."
                class="rounded-xl border border-[#e6e4dc] px-3.5 py-2.5 text-sm outline-none focus:border-[#15966b]" />
            <div class="flex items-center gap-3">
                <button type="button" :disabled="drafting || !prompt.trim()" class="rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="draftWithAi">
                    {{ drafting ? 'Drafting…' : 'Draft with AI' }}
                </button>
                <p v-if="notice" class="text-xs text-[#b7791f]">{{ notice }}</p>
                <p v-if="error" class="text-xs text-[#b4432f]" role="alert">{{ error }}</p>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-4 rounded-[18px] border border-[#e6e4dc] bg-white p-5 md:grid-cols-4">
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Customer
                <select v-model="form.customer_id" class="h-[38px] rounded-xl border border-[#e6e4dc] px-3 text-sm text-[#202a27]">
                    <option :value="null" disabled>Select a customer</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <span v-if="unmatchedCustomer" class="text-[#b7791f]">No customer called “{{ unmatchedCustomer }}” yet. Pick one, or add them in Customers first.</span>
                <span v-if="form.errors.customer_id" class="text-[#b4432f]">{{ form.errors.customer_id }}</span>
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Currency
                <select v-model="form.currency" class="h-[38px] rounded-xl border border-[#e6e4dc] px-3 text-sm text-[#202a27]">
                    <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Issue date
                <input v-model="form.issue_date" type="date" class="h-[38px] rounded-xl border border-[#e6e4dc] px-3 text-sm text-[#202a27]" />
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Due date
                <input v-model="form.due_date" type="date" class="h-[38px] rounded-xl border border-[#e6e4dc] px-3 text-sm text-[#202a27]" />
                <span v-if="form.errors.due_date" class="text-[#b4432f]">{{ form.errors.due_date }}</span>
            </label>
        </section>

        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">Items</h2>
            <InvoiceItemsGrid :ai-items="aiItems" :currency="form.currency" @change="onItems" />
            <p v-if="form.errors.items" class="text-xs text-[#b4432f]">{{ form.errors.items }}</p>
        </section>

        <footer class="flex items-center justify-end gap-3">
            <p class="text-xs text-[#6b7773]">Review every line. The invoice is only saved when you approve it.</p>
            <button type="button" :disabled="!canApprove" class="rounded-xl bg-[#123d32] px-[22px] py-3 text-sm font-semibold text-white disabled:opacity-50" @click="approve">
                {{ form.processing ? 'Saving…' : 'Approve & save invoice' }}
            </button>
        </footer>
    </div>
</template>
