<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import LanguageSelector from '@/components/LanguageSelector.vue';
import { translate as t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CheckCircle2,
    FileText,
    LockKeyhole,
    ShieldCheck,
} from '@lucide/vue';
import InvoicePrintButton from '@/components/InvoicePrintButton.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestJson } from '@/lib/api';
import { publicMethod as publicInvoice } from '@/routes/invoice-shares';
import { capture, orders } from '@/routes/invoice-shares/paypal';
import { date, money } from '@/types/business';

type SharedInvoice = {
    invoice_number: string;
    customer_name: string;
    issue_date: string;
    due_date: string | null;
    currency: string;
    items: {
        description: string;
        quantity: string | number;
        unit_price: string;
        amount: string;
    }[];
    subtotal: string;
    total: string;
    payments_received: string;
    remaining_balance: string;
    status: string;
};

type Checkout = {
    available: boolean;
    reason: string | null;
    reason_code: 'paid' | 'unavailable' | 'currency' | 'configuration' | null;
    mode: string;
    order_id: string | null;
};

type OrderResponse = {
    order_id: string;
    approval_url: string | null;
    amount: string;
    currency: string;
    status: string;
    payer_approved?: boolean;
};

type CaptureResponse = {
    message: string;
    payment: {
        status: string;
        amount: string;
        currency: string;
    };
};

const props = defineProps<{
    token: string;
    invoice: SharedInvoice;
    checkout: Checkout;
}>();
const invoice = ref(props.invoice);
const checkout = ref(props.checkout);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const paymentVerified = ref(false);
const amount = ref('');
const idempotencyKey = ref('');
const captureOrderId = ref(props.checkout.order_id);
const captureRetryAvailable = ref(Boolean(props.checkout.order_id));
const isSandbox = computed(() => checkout.value.mode === 'sandbox');
const canPay = computed(
    () =>
        checkout.value.available &&
        invoice.value.status === 'approved' &&
        Number(invoice.value.remaining_balance) > 0,
);

async function refreshInvoice() {
    const updated = await requestJson<{
        invoice: SharedInvoice;
        checkout: Checkout;
    }>(publicInvoice(props.token));
    invoice.value = updated.invoice;
    checkout.value = updated.checkout;
}

async function startCheckout() {
    if (busy.value || !canPay.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    paymentVerified.value = false;

    try {
        idempotencyKey.value ||= crypto.randomUUID();
        const order = await requestJson<OrderResponse>(orders(props.token), {
            idempotency_key: idempotencyKey.value,
            ...(amount.value.trim() ? { amount: amount.value.trim() } : {}),
        });
        if (order.status === 'completed') {
            await showVerifiedPayment(order);
            busy.value = false;
            return;
        }
        captureOrderId.value = order.order_id;
        if (order.payer_approved) {
            busy.value = false;
            await confirmPayment();
            return;
        }
        if (!order.approval_url) {
            throw new Error(
                t(
                    'interface.paypal_did_not_provide_a_checkout_link_please_try_again',
                ),
            );
        }
        window.location.assign(order.approval_url);
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        busy.value = false;
    }
}

async function showVerifiedPayment(payment: {
    amount: string;
    currency: string;
}) {
    paymentVerified.value = true;
    notice.value = t('payments.verifiedNotice', {
        amount: money(payment.amount, payment.currency),
    });
    captureOrderId.value = null;
    captureRetryAvailable.value = false;
    idempotencyKey.value = '';
    amount.value = '';
    try {
        await refreshInvoice();
    } catch {
        error.value = t(
            'interface.your_payment_was_verified_but_the_invoice_could_not_be_refreshed_refresh_this_page_to_see_the_updated_balance',
        );
    }
}

async function confirmPayment() {
    if (busy.value || !captureOrderId.value) return;
    busy.value = true;
    error.value = '';
    notice.value = t(
        'interface.confirming_your_payment_with_paypal_please_wait',
    );
    paymentVerified.value = false;

    try {
        const result = await requestJson<CaptureResponse>(
            capture({ token: props.token, orderId: captureOrderId.value }),
        );
        if (result.payment?.status !== 'completed') {
            notice.value = t(
                'interface.your_payment_has_not_been_confirmed_you_can_check_again_without_starting_another_checkout',
            );
            captureRetryAvailable.value = true;
            return;
        }
        await showVerifiedPayment(result.payment);
    } catch (e) {
        notice.value = '';
        error.value = e instanceof Error ? e.message : String(e);
        captureRetryAvailable.value = true;
    } finally {
        busy.value = false;
    }
}

async function restoreCheckout(event: PageTransitionEvent) {
    if (!event.persisted) return;
    busy.value = false;
    try {
        await refreshInvoice();
        captureOrderId.value = checkout.value.order_id;
        captureRetryAvailable.value = Boolean(checkout.value.order_id);
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    }
}

onMounted(() => {
    window.addEventListener('pageshow', restoreCheckout);
    const url = new URL(window.location.href);
    const outcome = url.searchParams.get('checkout');
    if (outcome !== 'approved' && outcome !== 'cancelled') return;
    const orderId = url.searchParams.get('token');
    url.searchParams.delete('checkout');
    url.searchParams.delete('token');
    url.searchParams.delete('PayerID');
    window.history.replaceState(window.history.state, '', url);

    if (outcome === 'cancelled') {
        notice.value = t(
            'interface.paypal_checkout_was_cancelled_no_payment_has_been_confirmed_from_this_return_you_can_resume_checkout_below',
        );
        captureRetryAvailable.value = false;
        return;
    }
    if (!orderId) {
        error.value = t(
            'interface.paypal_did_not_return_an_order_reference_please_resume_checkout_and_try_again',
        );
        return;
    }
    captureOrderId.value = orderId;
    void confirmPayment();
});
onBeforeUnmount(() => {
    window.removeEventListener('pageshow', restoreCheckout);
});
</script>

<template>
    <main
        class="relative min-h-screen overflow-x-hidden bg-[#faf9f6] px-4 py-7 text-[#202a27] sm:px-6 sm:py-10 dark:bg-background dark:text-foreground"
        data-invoice-page
    >
        <Head
            :title="t('invoices.title', { number: invoice.invoice_number })"
        />
        <div
            class="pointer-events-none absolute top-0 right-0 h-[460px] w-[620px] max-w-full rounded-full bg-[#c8ead8]/35 blur-[100px] dark:bg-primary/5"
            aria-hidden="true"
            data-print-hide
        />
        <div class="relative mx-auto flex max-w-6xl flex-col gap-7">
            <div class="flex justify-end" data-print-hide>
                <LanguageSelector compact />
            </div>
            <header
                class="flex flex-wrap items-center justify-between gap-4 border-b border-[#123d32]/10 pb-6 dark:border-border"
                data-print-hide
            >
                <div class="flex items-center gap-2.5">
                    <span
                        class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-[11px_11px_11px_3px] bg-gradient-to-br from-[#15966b] to-[#123d32] shadow-lg shadow-[#123d32]/10"
                        aria-hidden="true"
                        ><span
                            class="h-[21px] w-[17px] rotate-[29deg] rounded-[10px_2px_10px_2px] border-2 border-[#c7f0da]" /></span
                    ><span
                        class="font-display text-xl font-semibold tracking-tight text-[#123d32] dark:text-foreground"
                        >MtaaFlow AI<span class="text-[#15966b]">.</span></span
                    >
                </div>
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-[#15966b]/15 bg-white/70 px-3 py-1.5 text-xs font-medium text-[#0f7d58] dark:bg-primary/10 dark:text-primary"
                    ><LockKeyhole class="size-3.5" />{{
                        t('interface.private_invoice_link')
                    }}</span
                >
            </header>
            <div
                v-reveal
                class="flex flex-wrap items-end justify-between gap-4"
                data-print-hide
            >
                <div>
                    <p class="mf-kicker mb-2">
                        {{ t('interface.a_clear_view_of_your_invoice') }}
                    </p>
                    <h1
                        class="font-display text-3xl font-semibold tracking-tight text-[#123d32] sm:text-4xl dark:text-foreground"
                    >
                        {{ t('interface.hello') }}{{ invoice.customer_name }}.
                    </h1>
                    <p
                        class="mt-3 max-w-xl text-sm leading-6 text-muted-foreground"
                    >
                        {{
                            t(
                                'interface.review_your_invoice_below_when_you_are_ready_continue_to_paypal_to_pay_no_mtaaflow_ai_account_is_required',
                            )
                        }}
                    </p>
                </div>
                <InvoicePrintButton />
            </div>
            <p
                v-if="error"
                class="rounded-2xl border border-[#b4432f]/25 bg-[#b4432f]/5 p-4 text-sm leading-6 text-[#b4432f] dark:text-red-300"
                role="alert"
                data-print-hide
            >
                {{ error }}
            </p>
            <p
                v-if="notice"
                :class="
                    paymentVerified
                        ? 'border-[#15966b]/20 bg-[#e5f4ed] text-[#0f7d58] dark:bg-primary/15 dark:text-primary'
                        : 'border-border bg-card text-foreground'
                "
                class="flex items-start gap-3 rounded-2xl border p-4 text-sm leading-6"
                role="status"
                aria-live="polite"
                data-print-hide
            >
                <CheckCircle2
                    v-if="paymentVerified"
                    class="mt-0.5 size-5 shrink-0"
                />{{ notice }}
            </p>
            <div
                class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.8fr)_minmax(280px,1fr)]"
                data-print-grid
            >
                <section
                    v-reveal
                    class="mf-card min-w-0 overflow-hidden"
                    data-invoice-document
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4 border-b border-border bg-[#f2f7f0] p-6 sm:p-8 dark:bg-primary/5"
                    >
                        <div>
                            <p
                                class="font-display text-lg font-semibold text-[#123d32] dark:text-foreground"
                            >
                                MtaaFlow AI<span class="text-[#15966b]">.</span>
                            </p>
                            <p
                                class="mt-6 text-[10px] font-semibold tracking-[0.2em] text-[#63776e] uppercase"
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
                                    : 'border-border bg-white/80 text-[#63776e] dark:bg-card dark:text-foreground'
                            "
                            >{{ statusLabel(invoice.status) }}</span
                        >
                    </div>
                    <div class="p-6 sm:p-8">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                                >
                                    {{ t('interface.billed_to') }}
                                </p>
                                <h3 class="mt-2 font-semibold">
                                    {{ invoice.customer_name }}
                                </h3>
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
                            <table
                                class="w-full min-w-[390px] text-left text-sm"
                            >
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
                                            class="py-3 pl-2 text-right font-medium"
                                        >
                                            {{ t('common.amount') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(item, index) in invoice.items"
                                        :key="index"
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
                                            class="py-5 pl-2 text-right font-medium whitespace-nowrap tabular-nums"
                                        >
                                            {{
                                                money(
                                                    item.amount,
                                                    invoice.currency,
                                                )
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
                                    {{
                                        money(
                                            invoice.subtotal,
                                            invoice.currency,
                                        )
                                    }}
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
                                class="flex justify-between gap-4 text-muted-foreground"
                            >
                                <dt>{{ t('interface.payments_received') }}</dt>
                                <dd class="tabular-nums">
                                    {{
                                        money(
                                            invoice.payments_received,
                                            invoice.currency,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                class="flex flex-wrap justify-between gap-3 rounded-xl bg-[#e5f4ed] p-4 font-semibold text-[#0f7d58] dark:bg-primary/15 dark:text-primary"
                            >
                                <dt>{{ t('invoices.balance') }}</dt>
                                <dd class="tabular-nums">
                                    {{
                                        money(
                                            invoice.remaining_balance,
                                            invoice.currency,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="mt-8 border-t border-border pt-5 text-xs leading-5 text-muted-foreground"
                        >
                            {{
                                t(
                                    'interface.thank_you_for_your_business_please_keep_this_invoice_for_your_records',
                                )
                            }}
                        </p>
                    </div>
                </section>
                <aside class="flex flex-col gap-5" data-print-hide>
                    <section
                        class="mf-glass flex flex-col gap-5 rounded-3xl p-6 sm:p-7"
                        :aria-busy="busy"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="grid size-11 place-items-center rounded-xl bg-[#e5f4ed] text-[#15966b] dark:bg-primary/15"
                                ><ShieldCheck
                                    class="size-5"
                                    aria-hidden="true" /></span
                            ><span
                                class="text-xs font-medium text-muted-foreground"
                                >{{ t('interface.paypal_checkout') }}</span
                            >
                        </div>
                        <div>
                            <h2
                                class="font-display text-2xl font-semibold tracking-tight"
                            >
                                {{
                                    Number(invoice.remaining_balance) <= 0
                                        ? t('interface.all_settled')
                                        : t('interface.pay_your_invoice')
                                }}
                            </h2>
                            <p
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{
                                    Number(invoice.remaining_balance) <= 0
                                        ? t(
                                              'interface.your_invoice_has_no_outstanding_balance_thank_you',
                                          )
                                        : t(
                                              'interface.a_straightforward_finish_to_great_work',
                                          )
                                }}
                            </p>
                        </div>
                        <p
                            v-if="isSandbox"
                            class="rounded-xl border border-amber-200/70 bg-amber-50/80 p-3 text-xs leading-5 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            {{
                                t(
                                    'interface.paypal_sandbox_test_checkout_use_a_sandbox_buyer_account_this_is_not_a_real_money_payment',
                                )
                            }}
                        </p>
                        <template v-if="canPay">
                            <div
                                class="border-y border-[#123d32]/10 py-4 dark:border-border"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{ t('invoices.outstandingCurrency')
                                    }}{{ invoice.currency }}
                                </p>
                                <p
                                    class="mt-1 font-display text-3xl font-semibold tracking-tight text-[#123d32] tabular-nums dark:text-foreground"
                                >
                                    {{
                                        money(
                                            invoice.remaining_balance,
                                            invoice.currency,
                                        )
                                    }}
                                </p>
                            </div>
                            <form
                                class="flex flex-col gap-4"
                                @submit.prevent="startCheckout"
                            >
                                <label
                                    for="payment-amount"
                                    class="flex flex-col gap-2 text-sm font-medium"
                                    >{{ t('interface.payment_amount')
                                    }}{{ invoice.currency }})<input
                                        id="payment-amount"
                                        v-model="amount"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :disabled="
                                            busy || Boolean(checkout.order_id)
                                        "
                                        :placeholder="invoice.remaining_balance"
                                        aria-describedby="payment-amount-help"
                                        class="h-12 w-full rounded-xl border border-border bg-background/70 px-4 text-base transition outline-none focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/15 disabled:opacity-60"
                                /></label>
                                <p
                                    id="payment-amount-help"
                                    class="-mt-1 text-xs leading-5 text-muted-foreground"
                                >
                                    {{
                                        checkout.order_id
                                            ? t(
                                                  'interface.resume_the_existing_checkout_to_keep_its_original_payment_amount',
                                              )
                                            : t(
                                                  'interface.leave_blank_to_pay_the_outstanding_balance_or_enter_a_smaller_amount_for_a_partial_payment',
                                              )
                                    }}
                                </p>
                                <button
                                    type="submit"
                                    :disabled="busy"
                                    class="mf-button w-full justify-center py-3.5 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {{
                                        busy
                                            ? t('interface.processing_payment')
                                            : checkout.order_id
                                              ? t(
                                                    'interface.resume_paypal_checkout',
                                                )
                                              : t('interface.pay_with_paypal')
                                    }}<ArrowUpRight
                                        v-if="!busy"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </form>
                            <p
                                class="flex items-start gap-2 text-xs leading-5 text-muted-foreground"
                            >
                                <LockKeyhole
                                    class="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />{{
                                    t(
                                        'interface.continue_to_paypal_to_authorize_payment_your_invoice_updates_after_payment_is_captured_and_verified',
                                    )
                                }}
                            </p>
                        </template>
                        <p
                            v-else
                            class="text-sm leading-6 text-muted-foreground"
                        >
                            {{
                                (checkout.reason_code
                                    ? t('checkout.' + checkout.reason_code)
                                    : checkout.reason) ||
                                (Number(invoice.remaining_balance) <= 0
                                    ? t(
                                          'interface.this_invoice_has_no_outstanding_balance',
                                      )
                                    : t(
                                          'interface.this_invoice_is_not_currently_available_for_payment',
                                      ))
                            }}
                        </p>
                        <button
                            v-if="captureRetryAvailable && captureOrderId"
                            type="button"
                            :disabled="busy"
                            class="mf-button-secondary w-full disabled:cursor-wait disabled:opacity-60"
                            @click="confirmPayment"
                        >
                            {{
                                busy
                                    ? t('interface.confirming_payment')
                                    : t('interface.check_payment_status')
                            }}
                        </button>
                        <p
                            v-if="captureOrderId"
                            class="text-xs leading-5 text-muted-foreground"
                        >
                            {{
                                t(
                                    'interface.if_paypal_does_not_return_automatically_reopen_this_invoice_and_choose_check_payment_status',
                                )
                            }}
                        </p>
                    </section>
                    <div class="flex gap-3 px-2">
                        <FileText
                            class="mt-0.5 size-4 shrink-0 text-[#15966b]"
                            aria-hidden="true"
                        />
                        <p class="text-xs leading-5 text-muted-foreground">
                            {{
                                t(
                                    'interface.need_a_copy_choose_print_invoice_above_then_print_or_select_save_as_pdf_in_your_browser',
                                )
                            }}
                        </p>
                    </div>
                </aside>
            </div>
            <footer
                class="flex flex-wrap items-center justify-between gap-3 border-t border-[#123d32]/10 pt-6 text-xs leading-5 text-muted-foreground dark:border-border"
                data-print-hide
            >
                <span>{{
                    t('interface.keep_this_private_invoice_link_secure')
                }}</span
                ><span class="flex items-center gap-1.5"
                    ><ShieldCheck class="size-3.5 text-[#15966b]" />{{
                        t('interface.payment_details_verified_by_mtaaflow_ai')
                    }}</span
                >
            </footer>
        </div>
    </main>
</template>
