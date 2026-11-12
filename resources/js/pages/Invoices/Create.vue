<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import { fieldLabel, currencyName, translate as t } from '@/lib/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CheckCircle2, FileText, ShieldCheck, Sparkles } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { generate, confirm } from '@/routes/ai/invoice';
import { store, update, show } from '@/routes/invoices';
import type { InvoiceRecord } from '@/types/business';
import InvoiceItemsGrid from '@/components/InvoiceItemsGrid.vue';
import type { DraftItem, GridInfo } from '@/components/InvoiceItemsGrid.vue';

type Customer = { id: number; name: string };
type Api = { message?: string; errors?: Record<string, string[]> };
type DraftResponse = Api & {
    draft?: {
        customer_id: number | null;
        customer_name: string | null;
        currency: string | null;
        issue_date: string | null;
        due_date: string | null;
        items: unknown[];
    };
    customer_matches?: Customer[];
    missing_fields?: string[];
    errors?: Record<string, string[]>;
    confirmation?: { token: string; expires_at: string };
};
type ConfirmResponse = Api & {
    invoice_id?: number;
    invoice?: {
        invoice_number: string;
        currency: string;
        total: string;
        status: string;
    };
};

// `currencies` comes from Currency::all(); its exact shape is normalised below.
const props = defineProps<{
    customers: Customer[];
    currencies?: unknown;
    invoice?: InvoiceRecord;
    editing?: boolean;
}>();

const page = usePage();
const mode = ref(page.url.includes('mode=manual') ? 'manual' : 'ai');

const today = () => new Date().toLocaleDateString('en-CA');
const inDays = (n: number) =>
    new Date(Date.now() + n * 86_400_000).toLocaleDateString('en-CA');

const form = useForm({
    customer_id: props.invoice?.customer_id ?? (null as number | null),
    currency: props.invoice?.currency ?? '',
    issue_date: props.invoice?.issue_date.slice(0, 10) ?? today(),
    due_date: props.invoice
        ? (props.invoice.due_date?.slice(0, 10) ?? '')
        : inDays(14),
    items: [] as DraftItem[],
});

const prompt = ref('');
const drafting = ref(false);
const saving = ref(false);
const createdInvoice = ref<ConfirmResponse | null>(null);
const error = ref('');
const aiMessage = ref('');
const aiErrors = ref<string[]>([]);
const missing = ref<string[]>([]);
const matches = ref<Customer[]>([]);
const draftCustomerName = ref<string | null>(null);
const token = ref<string | null>(null);
const snapshot = ref('');
const drafted = ref(false);
const aiItems = ref<DraftItem[]>(
    props.invoice?.items?.map((item) => ({
        description: item.description,
        quantity: Number(item.quantity),
        unit_price: item.unit_price,
    })) ?? [],
);
const info = ref<GridInfo>({ total: 0, incomplete: 0, modified: false });

const currencyOptions = computed(() => {
    const raw = props.currencies as unknown;
    const out: { code: string; label: string }[] = [];
    const push = (code: unknown, name?: unknown) => {
        if (typeof code !== 'string' || !code) return;
        const c = code.toUpperCase();
        out.push({
            code: c,
            label:
                typeof name === 'string' && name
                    ? `${c} · ${currencyName(c)}`
                    : c,
        });
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
        for (const [code, v] of Object.entries(
            raw as Record<string, unknown>,
        )) {
            if (typeof v === 'string') push(code, v);
            else if (v && typeof v === 'object')
                push(code, (v as Record<string, unknown>).name);
            else push(code);
        }
    }
    return out.length
        ? out
        : ['USD', 'EUR', 'GBP'].map((code) => ({ code, label: code }));
});

const headerKey = () =>
    JSON.stringify([
        form.customer_id,
        form.currency,
        form.issue_date,
        form.due_date,
    ]);
const headerModified = computed(() => headerKey() !== snapshot.value);
// The server stored the AI's draft, so the token is only honest while nothing has been changed.
const tokenUsable = computed(
    () => !!token.value && !headerModified.value && !info.value.modified,
);

const suggested = computed<Customer[]>(() => {
    if (matches.value.length > 1) return matches.value;
    const n = (draftCustomerName.value ?? '').toLowerCase();
    return n
        ? props.customers.filter((c) => c.name.toLowerCase().includes(n))
        : [];
});

const itemErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k === 'items' || k.startsWith('items.'))
        .map(([, v]) => v),
);

const canSave = computed(
    () =>
        !!form.customer_id &&
        !!form.currency &&
        !!form.issue_date &&
        form.items.length > 0 &&
        info.value.incomplete === 0 &&
        !createdInvoice.value &&
        !drafting.value &&
        !saving.value &&
        !form.processing,
);

const toItem = (raw: unknown): DraftItem => {
    const o = (raw ?? {}) as Record<string, unknown>;
    const q =
        o.quantity === null || o.quantity === undefined || o.quantity === ''
            ? null
            : Number(o.quantity);
    const p =
        o.unit_price === null ||
        o.unit_price === undefined ||
        o.unit_price === ''
            ? null
            : String(o.unit_price);
    return {
        description:
            typeof o.description === 'string' && o.description.trim()
                ? o.description.trim()
                : null,
        quantity: q !== null && Number.isFinite(q) ? q : null,
        unit_price: p,
    };
};

async function postJson<T extends Api>(url: string, body: unknown) {
    const xsrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf,
        },
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
    if (r.status === 419)
        return 'Your session expired. Refresh the page and try again.';
    if (r.status === 401)
        return 'You are signed out. Log in again to continue.';
    if (r.status === 429) return t('errors.rateLimit');
    return (
        Object.values(r.data.errors ?? {})
            .flat()
            .join(' ') ||
        r.data.message ||
        t('errors.genericStatus', { status: r.status })
    );
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
    createdInvoice.value = null;
    error.value = '';
    try {
        const r = await postJson<DraftResponse>(generate.url(), {
            prompt: prompt.value,
        });
        if (r.data.draft) applyDraft(r.data);
        else error.value = failure(r);
    } catch {
        error.value = t(
            'interface.could_not_reach_the_server_check_your_connection_and_try_again',
        );
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
        if (props.editing && props.invoice) {
            form.put(update.url(props.invoice.id), { preserveScroll: true });
            return;
        }
        if (tokenUsable.value) {
            const r = await postJson<ConfirmResponse>(confirm.url(), {
                confirmation_token: token.value,
            });
            if (r.ok && r.data.invoice_id) {
                createdInvoice.value = r.data;
                return;
            }
            error.value =
                r.status === 410 || r.status === 404
                    ? t('errors.draftExpired')
                    : failure(r);
            return;
        }
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(t('interface.invoice_saved'), {
                    get description() {
                        return t(
                            'interface.your_draft_is_ready_to_review_and_approve',
                        );
                    },
                }),
        });
    } catch {
        error.value = t(
            'interface.could_not_reach_the_server_check_your_connection_and_try_again',
        );
    } finally {
        saving.value = false;
    }
}

const field =
    'h-11 w-full rounded-xl border bg-background/70 px-3 text-sm text-foreground outline-none transition focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/15';
const need = (empty: boolean) =>
    drafted.value && empty
        ? 'border-[#b4432f] bg-[#fdf3f0]'
        : 'border-[#e6e4dc]';
</script>

<template>
    <div class="mf-page flex min-w-0 flex-col gap-6">
        <Head
            :title="editing ? t('interface.edit_invoice') : t('invoices.new')"
        />
        <header v-reveal>
            <p class="mf-kicker mb-2">
                {{ t('interface.from_great_work_to_a_clear_invoice') }}
            </p>
            <h1
                class="font-display text-3xl font-semibold tracking-tight text-[#123d32] sm:text-4xl dark:text-foreground"
            >
                {{
                    editing
                        ? t('interface.edit_draft_invoice')
                        : t('invoices.new')
                }}
            </h1>
            <p class="mt-3 max-w-xl text-sm leading-6 text-muted-foreground">
                {{
                    editing
                        ? t(
                              'interface.refine_your_draft_before_approval_every_detail_stays_in_your_control',
                          )
                        : mode === 'manual'
                          ? t(
                                'interface.build_your_invoice_line_by_line_save_a_draft_review_it_then_approve_when_you_are_ready',
                            )
                          : t(
                                'interface.describe_the_work_ai_drafts_the_details_you_review_every_line_before_anything_is_created',
                            )
                }}
            </p>
        </header>

        <nav
            v-if="!editing"
            class="flex w-fit max-w-full flex-wrap gap-1 rounded-2xl border border-border bg-white/70 p-1.5 dark:bg-card"
            :aria-label="t('interface.invoice_creation_method')"
        >
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium transition"
                :class="
                    mode === 'manual'
                        ? 'bg-[#123d32] text-white shadow-sm'
                        : 'text-muted-foreground hover:bg-[#e5f4ed] dark:hover:bg-primary/15'
                "
                :aria-pressed="mode === 'manual'"
                @click="mode = 'manual'"
            >
                <FileText class="size-4" aria-hidden="true" />{{
                    t('interface.create_manually')
                }}</button
            ><button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium transition"
                :class="
                    mode === 'ai'
                        ? 'bg-[#123d32] text-white shadow-sm'
                        : 'text-muted-foreground hover:bg-[#e5f4ed] dark:hover:bg-primary/15'
                "
                :aria-pressed="mode === 'ai'"
                @click="mode = 'ai'"
            >
                <Sparkles class="size-4" aria-hidden="true" />{{
                    t('interface.generate_with_ai')
                }}
            </button>
        </nav>
        <section
            v-if="!editing && mode === 'ai'"
            class="mf-card flex min-w-0 flex-col gap-4 p-5 sm:p-7"
        >
            <p class="mf-kicker flex items-center gap-2">
                <Sparkles class="size-3.5" />{{
                    t('interface.your_invoice_copilot')
                }}
            </p>
            <label
                for="prompt"
                class="font-display text-xl font-semibold text-[#123d32] dark:text-foreground"
                >{{ t('interface.what_do_you_want_to_invoice') }}</label
            >
            <textarea
                id="prompt"
                v-model="prompt"
                rows="2"
                maxlength="5000"
                :placeholder="
                    t(
                        'interface.create_an_invoice_for_brian_kamau_for_three_hoodies_at_25_each_due_in_14_days',
                    )
                "
                class="min-h-28 rounded-2xl border border-border bg-[#f7faf5] p-4 text-sm leading-6 transition outline-none focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/15 dark:bg-background"
            />
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    :disabled="drafting || !prompt.trim()"
                    class="mf-button disabled:opacity-50"
                    @click="draftWithAi"
                >
                    {{
                        drafting
                            ? t('interface.drafting')
                            : t('interface.draft_with_ai')
                    }}
                </button>
                <p v-if="error" class="text-sm text-[#b4432f]" role="alert">
                    {{ error }}
                </p>
            </div>
            <p
                v-if="missing.length"
                class="text-sm text-[#b4432f]"
                role="status"
            >
                {{ t('interface.please_complete')
                }}{{ missing.map((key) => fieldLabel(key)).join(', ') }}.
            </p>
            <p
                v-if="aiMessage"
                class="rounded-lg bg-[#e8f7f5] px-3 py-2 text-sm text-[#14665c]"
                role="status"
            >
                {{ aiMessage }}
            </p>
            <ul
                v-if="aiErrors.length"
                class="list-disc rounded-lg bg-[#f9e4df] py-2 pr-3 pl-7 text-sm text-[#b4432f]"
                role="alert"
            >
                <li v-for="(m, i) in aiErrors" :key="i">{{ m }}</li>
            </ul>
        </section>

        <section
            class="mf-card grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 sm:p-7 xl:grid-cols-4"
        >
            <label
                class="flex flex-col gap-2 text-xs font-medium text-muted-foreground"
                >{{ t('invoices.customer')
                }}<select
                    v-model="form.customer_id"
                    :class="[field, need(!form.customer_id)]"
                >
                    <option :value="null" disabled>
                        {{ t('interface.select_a_customer') }}
                    </option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
                <span v-if="form.errors.customer_id" class="text-[#b4432f]">{{
                    form.errors.customer_id
                }}</span>
            </label>
            <label
                class="flex flex-col gap-2 text-xs font-medium text-muted-foreground"
                >{{ t('common.currency')
                }}<select
                    v-model="form.currency"
                    :class="[field, need(!form.currency)]"
                >
                    <option value="" disabled>
                        {{ t('interface.select_a_currency') }}
                    </option>
                    <option
                        v-for="c in currencyOptions"
                        :key="c.code"
                        :value="c.code"
                    >
                        {{ c.label }}
                    </option>
                </select>
                <span v-if="form.errors.currency" class="text-[#b4432f]">{{
                    form.errors.currency
                }}</span>
            </label>
            <label
                class="flex flex-col gap-2 text-xs font-medium text-muted-foreground"
                >{{ t('invoices.issueDate')
                }}<input
                    v-model="form.issue_date"
                    type="date"
                    :class="[field, need(!form.issue_date)]"
                />
                <span v-if="form.errors.issue_date" class="text-[#b4432f]">{{
                    form.errors.issue_date
                }}</span>
            </label>
            <label
                class="flex flex-col gap-2 text-xs font-medium text-muted-foreground"
                >{{ t('invoices.dueDate')
                }}<input
                    v-model="form.due_date"
                    type="date"
                    :class="[field, 'border-[#e6e4dc]']"
                />
                <span v-if="form.errors.due_date" class="text-[#b4432f]">{{
                    form.errors.due_date
                }}</span>
            </label>

            <div
                v-if="drafted && !form.customer_id"
                class="rounded-lg bg-[#fbf0da] px-3 py-2 text-sm text-[#8a5a14] sm:col-span-2 xl:col-span-4"
            >
                <template v-if="suggested.length"
                    >{{
                        t('customers.which', { name: draftCustomerName ?? '' })
                    }}
                    <button
                        v-for="c in suggested"
                        :key="c.id"
                        type="button"
                        class="mr-2 ml-2 rounded-full border border-[#b7791f] px-2.5 py-0.5 text-xs font-semibold"
                        @click="pickCustomer(c.id)"
                    >
                        {{ c.name }}
                    </button>
                </template>
                <template v-else-if="draftCustomerName"
                    >{{ t('customers.notFound', { name: draftCustomerName }) }}
                    <Link href="/customers" class="font-semibold underline">{{
                        t('interface.add_them_in_customers')
                    }}</Link
                    >{{ t('interface.then_pick_them_above') }}</template
                >
                <template v-else>{{
                    t('interface.pick_a_customer_above')
                }}</template>
            </div>
        </section>

        <section class="mf-card flex min-w-0 flex-col gap-4 p-5 sm:p-7">
            <h2
                class="font-display text-xl font-semibold text-[#123d32] dark:text-foreground"
            >
                {{ t('interface.line_items') }}
            </h2>
            <p class="-mt-1 text-sm text-muted-foreground">
                {{
                    t(
                        'interface.add_the_work_quantity_and_rate_review_each_line_before_saving',
                    )
                }}
            </p>
            <InvoiceItemsGrid
                :ai-items="aiItems"
                :currency="form.currency || null"
                @change="onItems"
            />
            <ul
                v-if="itemErrors.length"
                class="list-disc pl-5 text-xs text-[#b4432f]"
                role="alert"
            >
                <li v-for="(m, i) in itemErrors" :key="i">{{ m }}</li>
            </ul>
        </section>

        <section
            v-if="createdInvoice"
            class="mf-invoice-success rounded-2xl border border-[#15966b]/20 bg-[#e5f4ed] p-6 text-[#0d6b4a] dark:bg-primary/15 dark:text-primary"
            role="status"
        >
            <CheckCircle2 class="mb-3 size-7" aria-hidden="true" />
            <h2 class="font-display text-xl font-semibold">
                {{ createdInvoice.message }}
            </h2>
            <p v-if="createdInvoice.invoice">
                {{ createdInvoice.invoice.invoice_number }} —
                {{ createdInvoice.invoice.currency }}
                {{ createdInvoice.invoice.total }} ·
                {{ statusLabel(createdInvoice.invoice.status) }}
            </p>
            <p v-else>
                {{ t('interface.invoice') }}{{ createdInvoice.invoice_id
                }}{{ t('interface.was_created') }}
            </p>
            <Link
                v-if="createdInvoice.invoice_id"
                :href="show(createdInvoice.invoice_id)"
                class="underline"
                >{{ t('interface.view_saved_invoice') }}</Link
            >
        </section>

        <footer
            class="mf-glass flex flex-wrap items-center justify-between gap-5 rounded-2xl p-5"
        >
            <p
                class="flex max-w-md items-start gap-2 text-xs leading-5 text-muted-foreground"
            >
                <ShieldCheck
                    class="mt-0.5 size-4 shrink-0 text-[#15966b]"
                    aria-hidden="true"
                />
                <template v-if="tokenUsable">{{
                    t('interface.saving_the_ai_draft_exactly_as_drafted')
                }}</template>
                <template v-else-if="drafted">{{
                    t('interface.saving_your_reviewed_version')
                }}</template>
                <template v-else>{{
                    t(
                        'interface.creates_the_invoice_as_a_draft_you_approve_it_on_the_next_screen',
                    )
                }}</template>
            </p>
            <button
                type="button"
                :disabled="!canSave"
                class="mf-button disabled:opacity-50"
                @click="createInvoice"
            >
                {{
                    saving || form.processing
                        ? t('interface.saving')
                        : editing
                          ? t('interface.save_draft_changes')
                          : t('interface.confirm_and_create_invoice')
                }}
            </button>
        </footer>
    </div>
</template>

<style scoped>
.mf-invoice-success {
    animation: invoice-saved-in 400ms cubic-bezier(0.2, 0.7, 0.25, 1) both;
}
@keyframes invoice-saved-in {
    from {
        opacity: 0;
        transform: translateY(7px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (prefers-reduced-motion: reduce) {
    .mf-invoice-success {
        animation: none;
    }
}
</style>
