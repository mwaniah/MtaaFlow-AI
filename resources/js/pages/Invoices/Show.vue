<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import { translate as t } from '@/lib/i18n';
import { computed, ref } from 'vue';
import {
    ArrowLeft,
    ArrowUpRight,
    Check,
    FileText,
    Link2,
    Mail,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import InvoicePrintButton from '@/components/InvoicePrintButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { index, edit, approve, destroy } from '@/routes/invoices';
import {
    store as shareStore,
    destroy as revokeShare,
    email as emailShare,
} from '@/routes/invoices/shares';
import { requestJson } from '@/lib/api';
import { balance, date, money, type InvoiceRecord } from '@/types/business';
const props = defineProps<{ invoice: InvoiceRecord }>();
const page = usePage();
const error = ref('');
const notice = ref('');
const busy = ref(false);
const remaining = computed(() => balance(props.invoice));
const share = ref<{
    share: { id: number };
    share_token: string;
    share_url: string;
    whatsapp_share_url: string;
} | null>(null);

async function run(action: () => Promise<void>) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await action();
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}
function approveInvoice() {
    router.post(approve.url(props.invoice.id));
}
function remove() {
    if (window.confirm(t('invoices.deletePrompt')))
        router.delete(destroy.url(props.invoice.id));
}
function createShare() {
    void run(async () => {
        share.value = await requestJson(shareStore(props.invoice.id), {});
        notice.value = t(
            'interface.share_link_created_no_email_or_whatsapp_message_has_been_sent',
        );
    });
}
function sendEmail() {
    if (!share.value) return;
    const current = share.value;
    void run(async () => {
        const result = await requestJson<{ message: string }>(
            emailShare({
                invoice: props.invoice.id,
                invoiceShare: current.share.id,
            }),
            { share_token: current.share_token },
        );
        notice.value = result.message + ' ' + t('common.emailDelivery');
    });
}
function revoke() {
    if (!share.value) return;
    const current = share.value;
    void run(async () => {
        await requestJson(
            revokeShare({
                invoice: props.invoice.id,
                invoiceShare: current.share.id,
            }),
        );
        share.value = null;
        notice.value = t('interface.share_link_revoked');
    });
}
</script>
<template>
    <div class="mf-page flex flex-col gap-7" data-invoice-page>
        <Head :title="invoice.invoice_number" />
        <header
            v-reveal
            class="flex flex-wrap items-end justify-between gap-5"
            data-print-hide
        >
            <div>
                <Link
                    :href="index()"
                    class="mb-4 inline-flex items-center gap-2 text-sm text-muted-foreground transition hover:text-[#15966b]"
                    ><ArrowLeft class="size-4" />{{
                        t('interface.back_to_invoices')
                    }}</Link
                >
                <p class="mf-kicker mb-2">
                    {{ t('interface.the_details_all_in_one_place') }}
                </p>
                <h1
                    class="font-display text-3xl font-semibold tracking-tight text-[#123d32] sm:text-4xl dark:text-foreground"
                >
                    {{ invoice.invoice_number }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ invoice.customer?.name }} <span class="px-1.5">·</span
                    ><span class="capitalize">{{
                        statusLabel(invoice.status)
                    }}</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <InvoicePrintButton />
                <template v-if="invoice.status === 'draft'">
                    <Link
                        :href="edit(invoice.id)"
                        class="mf-button-secondary"
                        >{{ t('interface.edit_draft') }}</Link
                    >
                    <button class="mf-button" @click="approveInvoice">
                        <Check class="size-4" />{{ t('invoices.approve') }}
                    </button>
                    <button
                        class="grid size-11 place-items-center rounded-xl border border-border text-[#b4432f] transition hover:bg-red-50 dark:hover:bg-red-500/10"
                        :aria-label="t('interface.delete_draft_invoice')"
                        @click="remove"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </template>
            </div>
        </header>
        <p
            v-if="error"
            class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-[#b4432f] dark:border-red-400/30 dark:bg-red-500/10"
            role="alert"
            data-print-hide
        >
            {{ error }}
        </p>
        <p
            v-for="(value, key) in page.props.errors"
            :key="key"
            class="text-sm text-[#b4432f]"
            role="alert"
            data-print-hide
        >
            {{ value }}
        </p>
        <p
            v-if="notice"
            class="flex items-center gap-3 rounded-2xl border border-[#15966b]/20 bg-[#e5f4ed] p-4 text-sm text-[#0f7d58] dark:bg-primary/15 dark:text-primary"
            role="status"
            data-print-hide
        >
            <Check class="size-4 shrink-0" />{{ notice }}
        </p>
        <div class="grid items-start gap-6 lg:grid-cols-3" data-print-grid>
            <section
                v-reveal
                class="mf-card min-w-0 overflow-hidden lg:col-span-2"
                data-invoice-document
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-5 border-b border-border bg-[#f3f8f2] px-6 py-7 sm:px-8 dark:bg-primary/5"
                >
                    <div>
                        <p
                            class="font-display text-lg font-semibold text-[#123d32] dark:text-foreground"
                        >
                            MtaaFlow AI<span class="text-[#15966b]">.</span>
                        </p>
                        <p
                            class="mt-5 text-[10px] font-semibold tracking-[0.2em] text-[#63776e] uppercase"
                        >
                            {{ t('interface.invoice') }}
                        </p>
                        <h2
                            class="mt-1 font-display text-2xl font-semibold text-[#123d32] dark:text-foreground"
                        >
                            {{ invoice.invoice_number }}
                        </h2>
                    </div>
                    <span
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold capitalize"
                        :class="
                            invoice.status === 'paid'
                                ? 'border-[#15966b]/20 bg-[#e5f4ed] text-[#0f7d58] dark:bg-primary/15 dark:text-primary'
                                : invoice.status === 'approved'
                                  ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200'
                                  : 'border-border bg-card text-muted-foreground'
                        "
                        >{{ statusLabel(invoice.status) }}</span
                    >
                </div>
                <div class="p-6 sm:p-8">
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <p
                                class="mb-2 text-[10px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                            >
                                {{ t('interface.billed_to') }}
                            </p>
                            <p class="font-semibold">
                                {{ invoice.customer?.name }}
                            </p>
                            <p
                                v-if="invoice.customer?.email"
                                class="mt-1 text-sm break-all text-muted-foreground"
                            >
                                {{ invoice.customer.email }}
                            </p>
                            <p
                                v-if="invoice.customer?.phone"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{ invoice.customer.phone }}
                            </p>
                            <p
                                v-if="invoice.customer?.address"
                                class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ invoice.customer.address }}
                            </p>
                        </div>
                        <dl
                            class="grid grid-cols-2 gap-4 text-sm sm:text-right"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    {{ t('invoices.issueDate') }}
                                </dt>
                                <dd class="mt-2 font-medium">
                                    {{ date(invoice.issue_date) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    {{ t('invoices.dueDate') }}
                                </dt>
                                <dd class="mt-2 font-medium">
                                    {{ date(invoice.due_date) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-8 overflow-x-auto">
                        <table class="w-full min-w-[390px] text-left text-sm">
                            <caption class="sr-only">
                                {{
                                    t('interface.invoice_items_in')
                                }}{{
                                    invoice.currency
                                }}
                            </caption>
                            <thead
                                class="border-y border-border text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="py-3 pr-4 font-medium"
                                    >
                                        {{ t('common.description') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-2 py-3 text-right font-medium"
                                    >
                                        {{ t('interface.qty') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-2 py-3 text-right font-medium"
                                    >
                                        {{ t('invoices.unitPrice') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-3 pl-3 text-right font-medium"
                                    >
                                        {{ t('common.amount') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, i) in invoice.items ?? []"
                                    :key="i"
                                    class="border-b border-border"
                                >
                                    <td class="py-5 pr-4 break-words">
                                        {{ item.description }}
                                    </td>
                                    <td
                                        class="px-2 py-5 text-right tabular-nums"
                                    >
                                        {{ item.quantity }}
                                    </td>
                                    <td
                                        class="px-2 py-5 text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            money(
                                                item.unit_price,
                                                invoice.currency,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="py-5 pl-3 text-right font-medium whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            money(item.amount, invoice.currency)
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <dl
                        class="mt-6 flex flex-col gap-4 text-sm sm:ml-auto sm:max-w-sm"
                    >
                        <div
                            class="flex justify-between gap-4 text-muted-foreground"
                        >
                            <dt>{{ t('invoices.subtotal') }}</dt>
                            <dd class="tabular-nums">
                                {{ money(invoice.subtotal, invoice.currency) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 text-lg font-semibold"
                        >
                            <dt>{{ t('common.total') }}</dt>
                            <dd class="tabular-nums">
                                {{ money(invoice.total, invoice.currency) }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap justify-between gap-3 rounded-xl bg-[#e5f4ed] p-4 font-semibold text-[#0f7d58] dark:bg-primary/15 dark:text-primary"
                        >
                            <dt>
                                {{
                                    invoice.status === 'draft'
                                        ? t('interface.draft_status')
                                        : t('invoices.outstanding')
                                }}
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    invoice.status === 'draft'
                                        ? t('interface.not_yet_payable')
                                        : money(remaining, invoice.currency)
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="mt-8 border-t border-border pt-5 text-xs text-muted-foreground"
                    >
                        {{
                            invoice.status === 'draft'
                                ? t(
                                      'interface.this_is_a_draft_invoice_approval_is_required_before_payment',
                                  )
                                : t('interface.thank_you_for_your_business')
                        }}
                    </p>
                </div>
            </section>
            <aside class="flex flex-col gap-5" data-print-hide>
                <section
                    v-if="
                        invoice.status === 'approved' ||
                        invoice.status === 'paid'
                    "
                    class="mf-glass flex flex-col gap-4 rounded-3xl p-6"
                >
                    <span
                        class="grid size-11 place-items-center rounded-xl bg-[#e5f4ed] text-[#15966b] dark:bg-primary/15"
                        ><Link2 class="size-5"
                    /></span>
                    <div>
                        <h2 class="font-display text-xl font-semibold">
                            {{ t('invoices.share') }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-muted-foreground">
                            {{
                                t(
                                    'interface.a_private_link_a_clear_invoice_an_easy_way_for_your_customer_to_pay',
                                )
                            }}
                        </p>
                    </div>
                    <button
                        :disabled="busy"
                        class="mf-button w-full"
                        @click="createShare"
                    >
                        <Link2 class="size-4" />{{
                            t('interface.create_secure_share_link')
                        }}
                    </button>
                    <template v-if="share">
                        <a
                            :href="share.share_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-xl border border-border bg-background/70 p-3 text-xs leading-5 break-all text-[#0f7d58] underline dark:text-primary"
                            >{{ share.share_url }}</a
                        >
                        <p class="text-xs leading-5 text-muted-foreground">
                            {{
                                t(
                                    'interface.customers_can_review_this_invoice_and_pay_with_paypal_through_this_secure_link',
                                )
                            }}
                        </p>
                        <button
                            :disabled="busy"
                            class="mf-button-secondary w-full"
                            @click="sendEmail"
                        >
                            <Mail class="size-4" />{{
                                t('interface.email_link_to_customer')
                            }}
                        </button>
                        <a
                            :href="share.whatsapp_share_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center justify-between gap-2 text-sm font-medium text-[#0f7d58] underline underline-offset-4 dark:text-primary"
                            >{{ t('interface.share_via_whatsapp_not_yet_sent')
                            }}<ArrowUpRight class="size-4"
                        /></a>
                        <button
                            :disabled="busy"
                            class="text-left text-xs text-[#b4432f] underline underline-offset-4"
                            @click="revoke"
                        >
                            {{ t('interface.revoke_this_link') }}
                        </button>
                    </template>
                </section>
                <section v-else class="mf-glass rounded-3xl p-6">
                    <FileText class="mb-4 size-5 text-[#15966b]" />
                    <h2 class="font-display text-lg font-semibold">
                        {{ t('interface.ready_when_you_are') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        {{
                            t(
                                'interface.review_the_details_and_approve_your_draft_to_make_sharing_and_customer_payment_available',
                            )
                        }}
                    </p>
                </section>
                <p
                    class="flex items-start gap-2 px-2 text-xs leading-5 text-muted-foreground"
                >
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-[#15966b]"
                    />{{
                        t(
                            'interface.your_invoice_stays_in_your_account_you_control_when_it_is_approved_and_shared',
                        )
                    }}
                </p>
            </aside>
        </div>
        <section v-reveal class="mf-card p-6" data-print-hide>
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-display text-xl font-semibold">
                    {{ t('interface.payment_activity') }}
                </h2>
                <span
                    class="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground"
                    >{{ invoice.payments?.length ?? 0
                    }}{{ t('interface.records') }}</span
                >
            </div>
            <p
                v-if="!invoice.payments?.length"
                class="py-7 text-sm text-muted-foreground"
            >
                {{
                    t(
                        'interface.payment_records_will_appear_here_as_your_invoice_is_paid',
                    )
                }}
            </p>
            <div
                v-for="payment in invoice.payments"
                :key="payment.id"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4 text-sm"
            >
                <div>
                    <p class="font-semibold tabular-nums">
                        {{ money(payment.amount, payment.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ date(payment.paid_at ?? payment.created_at) }}
                    </p>
                </div>
                <span
                    class="rounded-full bg-muted px-3 py-1 text-xs capitalize"
                    >{{ statusLabel(payment.status) }}</span
                >
            </div>
        </section>
    </div>
</template>
