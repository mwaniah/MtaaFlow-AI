<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InvoiceItemsGrid from '@/components/InvoiceItemsGrid.vue';
import type { DraftItem, GridInfo } from '@/components/InvoiceItemsGrid.vue';

type Customer = { id: number; name: string };
type Api = { message?: string };
type DraftResponse = Api & {
    draft?: { customer_id: number | null; customer_name: string | null; currency: string | null; issue_date: string | null; due_date: string | null; items: unknown[] };
    customer_matches?: Customer[];
    missing_fields?: string[];
    errors?: Record<string, string[]>;
    confirmation?: { token: string; expires_at: string };
};
type ConfirmResponse = Api & { invoice_id?: number };

// `currencies` comes from Currency::all(); its exact shape is normalised below.
const props = defineProps<{ customers: Customer[]; currencies?: unknown }>();

const today = () => new Date().toLocaleDateString('en-CA');
const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toLocaleDateString('en-CA');

const form = useForm({
    customer_id: null as number | null,
    currency: '',
    issue_date: today(),
    due_date: inDays(14),
    items: [] as DraftItem[],
});

const prompt = ref('');
const drafting = ref(false);
const saving = ref(false);
const error = ref('');
const aiMessage = ref('');
const aiErrors = ref<string[]>([]);
const missing = ref<string[]>([]);
const matches = ref<Customer[]>([]);
const draftCustomerName = ref<string | null>(null);
const token = ref<string | null>(null);
const snapshot = ref('');
const drafted = ref(false);
const aiItems = ref<DraftItem[]>([]);
const info = ref<GridInfo>({ total: 0, incomplete: 0, modified: false });

const currencyOptions = computed(() => {
    const raw = props.currencies as unknown;
    const out: { code: string; label: string }[] = [];
    const push = (code: unknown, name?: unknown) => {
        if (typeof code !== 'string' || !code) return;
        const c = code.toUpperCase();
        out.push({ code: c, label: typeof name === 'string' && name ? `${c} · ${name}` : c });
    };
    if (Array.isArray(raw)) {
        for (const c of raw) {
            if (typeof c === 'string') push(c);
            else if (c && typeof c === 'object') {
                const o = c as Record<string, unknown>;
                push(o.code ?? o.currency ?? o.iso ?? o.id, o.name ?? o.label);
            }
        }
    } else if (raw && typeof raw === 'object') {
        for (const [code, v] of Object.entries(raw as Record<string, unknown>)) {
            if (typeof v === 'string') push(code, v);
            else if (v && typeof v === 'object') push(code, (v as Record<string, unknown>).name);
            else push(code);
        }
    }
    return out.length ? out : ['USD', 'EUR', 'GBP'].map((code) => ({ code, label: code }));
});

const headerKey = () => JSON.stringify([form.customer_id, form.currency, form.issue_date, form.due_date]);
const headerModified = computed(() => headerKey() !== snapshot.value);
// The server stored the AI's draft, so the token is only honest while nothing has been changed.
const tokenUsable = computed(() => !!token.value && !headerModified.value && !info.value.modified);

const suggested = computed<Customer[]>(() => {
    if (matches.value.length > 1) return matches.value;
    const n = (draftCustomerName.value ?? '').toLowerCase();
    return n ? props.customers.filter((c) => c.name.toLowerCase().includes(n)) : [];
});

const itemErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k === 'items' || k.startsWith('items.'))
        .map(([, v]) => v),
);

const canSave = computed(
    () => !!form.customer_id && !!form.currency && !!form.issue_date && form.items.length > 0 && info.value.incomplete === 0 && !saving.value && !form.processing,
);

const toItem = (raw: unknown): DraftItem => {
    const o = (raw ?? {}) as Record<string, unknown>;
    const q = o.quantity === null || o.quantity === undefined || o.quantity === '' ? null : Number(o.quantity);
    const p = o.unit_price === null || o.unit_price === undefined || o.unit_price === '' ? null : String(o.unit_price);
    return {
        description: typeof o.description === 'string' && o.description.trim() ? o.description.trim() : null,
        quantity: q !== null && Number.isFinite(q) ? q : null,
        unit_price: p,
    };
};

async function postJson<T extends Api>(url: string, body: unknown) {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify(body),
    });
    let data = {} as T;
    try {
        data = (await res.json()) as T;
    } catch {
        // A non-JSON reply (for example an HTML error page) leaves data empty.
    }
    return { ok: res.ok, status: res.status, data };
}

function failure(r: { status: number; data: Api }): string {
    if (r.status === 419) return 'Your session expired. Refresh the page and try again.';
    if (r.status === 401) return 'You are signed out. Log in again to continue.';
    if (r.status === 429) return 'Too many requests. Wait a minute and try again.';
    return r.data.message ?? `Something went wrong (error ${r.status}). Try again.`;
}

function applyDraft(r: DraftResponse) {
    const d = r.draft!;
    const hasToken = !!r.confirmation?.token;
    form.customer_id = d.customer_id ?? null;
    form.currency = d.currency ?? '';
    // With a token the screen must match what the server stored, so nothing is defaulted.
    form.issue_date = d.issue_date ?? (hasToken ? '' : today());
    form.due_date = d.due_date ?? (hasToken ? '' : inDays(14));
    aiItems.value = (d.items ?? []).map(toItem);
    token.value = r.confirmation?.token ?? null;
    snapshot.value = headerKey();
    matches.value = r.customer_matches ?? [];
    draftCustomerName.value = d.customer_name;
    aiMessage.value = r.message ?? '';
    aiErrors.value = r.errors ? Object.values(r.errors).flat() : [];
    missing.value = r.missing_fields ?? [];
    drafted.value = true;
    form.clearErrors();
}

async function draftWithAi() {
    if (!prompt.value.trim() || drafting.value) return;
    drafting.value = true;
    error.value = '';
    try {
        const r = await postJson<DraftResponse>('/ai/generate-invoice', { prompt: prompt.value });
        if (r.data.draft) applyDraft(r.data);
        else error.value = failure(r);
    } catch {
        error.value = 'Could not reach the server. Check your connection and try again.';
    } finally {
        drafting.value = false;
    }
}

function onItems(items: DraftItem[], i: GridInfo) {
    form.items = items;
    info.value = i;
}

function pickCustomer(id: number) {
    form.customer_id = id;
}

async function createInvoice() {
    if (!canSave.value) return;
    saving.value = true;
    error.value = '';
    try {
        if (tokenUsable.value) {
            const r = await postJson<ConfirmResponse>('/ai/invoice/confirm', { confirmation_token: token.value });
            if (r.ok && r.data.invoice_id) {
                router.visit(`/invoices/${r.data.invoice_id}`);
                return;
            }
            if (r.status === 410 || r.status === 404) {
                token.value = null; // expired: save the same values through the normal form instead
            } else {
                error.value = failure(r);
                return;
            }
        }
        form.post('/invoices', { preserveScroll: true });
    } catch {
        error.value = 'Could not reach the server. Check your connection and try again.';
    } finally {
        saving.value = false;
    }
}

const field = 'h-[38px] rounded-xl border px-3 text-sm text-[#202a27] outline-none focus:border-[#15966b]';
const need = (empty: boolean) => (drafted.value && empty ? 'border-[#b4432f] bg-[#fdf3f0]' : 'border-[#e6e4dc]');
</script>

<template>
    <Head title="New Invoice" />
    <div class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-xl p-7">
        <header>
            <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">New invoice</h1>
            <p class="text-sm text-[#6b7773]">Describe the invoice and MtaaFlow AI drafts it. You review every line before anything is created.</p>
        </header>

        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <label for="prompt" class="text-sm font-semibold text-[#202a27]">What do you want to invoice?</label>
            <textarea id="prompt" v-model="prompt" rows="2" maxlength="5000" placeholder="Create an invoice for Brian Kamau for three hoodies at $25 each, due in 14 days."
                class="rounded-xl border border-[#e6e4dc] px-3.5 py-2.5 text-sm outline-none focus:border-[#15966b]" />
            <div class="flex items-center gap-3">
                <button type="button" :disabled="drafting || !prompt.trim()" class="rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="draftWithAi">
                    {{ drafting ? 'Drafting…' : 'Draft with AI' }}
                </button>
                <p v-if="error" class="text-sm text-[#b4432f]" role="alert">{{ error }}</p>
            </div>
            <p v-if="aiMessage" class="rounded-lg bg-[#e8f7f5] px-3 py-2 text-sm text-[#14665c]" role="status">{{ aiMessage }}</p>
            <ul v-if="aiErrors.length" class="list-disc rounded-lg bg-[#f9e4df] py-2 pr-3 pl-7 text-sm text-[#b4432f]" role="alert">
                <li v-for="(m, i) in aiErrors" :key="i">{{ m }}</li>
            </ul>
        </section>

        <section class="grid grid-cols-1 gap-4 rounded-[18px] border border-[#e6e4dc] bg-white p-5 md:grid-cols-4">
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Customer
                <select v-model="form.customer_id" :class="[field, need(!form.customer_id)]">
                    <option :value="null" disabled>Select a customer</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <span v-if="form.errors.customer_id" class="text-[#b4432f]">{{ form.errors.customer_id }}</span>
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Currency
                <select v-model="form.currency" :class="[field, need(!form.currency)]">
                    <option value="" disabled>Select a currency</option>
                    <option v-for="c in currencyOptions" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>
                <span v-if="form.errors.currency" class="text-[#b4432f]">{{ form.errors.currency }}</span>
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Issue date
                <input v-model="form.issue_date" type="date" :class="[field, need(!form.issue_date)]" />
                <span v-if="form.errors.issue_date" class="text-[#b4432f]">{{ form.errors.issue_date }}</span>
            </label>
            <label class="flex flex-col gap-1 text-xs text-[#6b7773]">Due date
                <input v-model="form.due_date" type="date" :class="[field, 'border-[#e6e4dc]']" />
                <span v-if="form.errors.due_date" class="text-[#b4432f]">{{ form.errors.due_date }}</span>
            </label>

            <div v-if="drafted && !form.customer_id" class="rounded-lg bg-[#fbf0da] px-3 py-2 text-sm text-[#8a5a14] md:col-span-4">
                <template v-if="suggested.length">
                    Which customer is “{{ draftCustomerName }}”?
                    <button v-for="c in suggested" :key="c.id" type="button" class="mr-2 ml-2 rounded-full border border-[#b7791f] px-2.5 py-0.5 text-xs font-semibold" @click="pickCustomer(c.id)">{{ c.name }}</button>
                </template>
                <template v-else-if="draftCustomerName">
                    There is no customer called “{{ draftCustomerName }}” yet. <Link href="/customers" class="font-semibold underline">Add them in Customers</Link>, then pick them above.
                </template>
                <template v-else>Pick a customer above.</template>
            </div>
        </section>

        <section class="flex flex-col gap-3 rounded-[18px] border border-[#e6e4dc] bg-white p-5">
            <h2 class="font-display text-xl leading-[1.15] font-bold tracking-[-0.01em] text-[#202a27]">Items</h2>
            <InvoiceItemsGrid :ai-items="aiItems" :currency="form.currency || null" @change="onItems" />
            <ul v-if="itemErrors.length" class="list-disc pl-5 text-xs text-[#b4432f]" role="alert">
                <li v-for="(m, i) in itemErrors" :key="i">{{ m }}</li>
            </ul>
        </section>

        <footer class="flex flex-wrap items-center justify-end gap-3">
            <p class="text-xs text-[#6b7773]">
                <template v-if="tokenUsable">Saving the AI draft exactly as drafted.</template>
                <template v-else-if="drafted">Saving your reviewed version.</template>
                <template v-else>Creates the invoice as a draft. You approve it on the next screen.</template>
            </p>
            <button type="button" :disabled="!canSave" class="rounded-xl bg-[#123d32] px-[22px] py-3 text-sm font-semibold text-white disabled:opacity-50" @click="createInvoice">
                {{ saving || form.processing ? 'Creating…' : 'Create invoice' }}
            </button>
        </footer>
    </div>
</template>
