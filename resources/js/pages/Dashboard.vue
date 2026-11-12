<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import { translate as t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    Check,
    Clock3,
    FileText,
    Plus,
    Sparkles,
    Users,
    Wallet,
} from '@lucide/vue';
import AnimatedNumber from '@/components/AnimatedNumber.vue';
import { index as askIndex } from '@/routes/ask';
import { index as customersIndex } from '@/routes/customers';
import { create, index as invoicesIndex, show } from '@/routes/invoices';
import { index as reportsIndex } from '@/routes/reports';
import {
    money,
    date,
    type InvoiceRecord,
    type PaymentRecord,
    type CurrencyTotal,
} from '@/types/business';

const props = defineProps<{
    totalCustomers: number;
    totalInvoices: number;
    paidInvoiceCount: number;
    outstandingInvoiceCount: number;
    recentInvoices: InvoiceRecord[];
    recentPayments: PaymentRecord[];
    verifiedEarningsByCurrency: CurrencyTotal[];
    outstandingInvoiceTotalsByCurrency: CurrencyTotal[];
    invoicedTotalsByCurrency: CurrencyTotal[];
}>();
</script>

<template>
    <div class="mf-page flex flex-col gap-7">
        <Head :title="t('nav.dashboard')" />
        <header v-reveal class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="mf-kicker mb-2">
                    {{ t('interface.your_business_in_focus') }}
                </p>
                <h1
                    class="font-display text-3xl font-bold tracking-tight text-brand-forest sm:text-4xl dark:text-foreground"
                >
                    {{ t('nav.dashboard') }}
                </h1>
                <p
                    class="mt-2 max-w-xl text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.a_little_clarity_for_your_next_big_move_your_invoices_payments_and_customers_together',
                        )
                    }}
                </p>
            </div>
            <Link
                :href="create({ query: { mode: 'manual' } })"
                class="mf-button"
            >
                <Plus class="size-4" aria-hidden="true" />{{
                    t('invoices.create')
                }}</Link
            >
        </header>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section
                v-reveal
                class="mf-glass dashboard-metric dashboard-metric-earnings relative overflow-hidden p-5 sm:p-6"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-brand-forest dark:text-foreground"
                    >
                        {{ t('interface.verified_earnings') }}
                    </h2>
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-brand-emerald/10 text-brand-emerald-dark dark:text-[#8bdbb4]"
                        ><Wallet class="size-[18px]" aria-hidden="true"
                    /></span>
                </div>
                <div class="mt-5 flex flex-col gap-2">
                    <p
                        v-for="row in props.verifiedEarningsByCurrency"
                        :key="row.currency"
                        class="font-display text-[clamp(1.4rem,2.1vw,2rem)] leading-tight font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
                    >
                        {{ money(row.total, row.currency) }}
                    </p>
                    <p
                        v-if="!props.verifiedEarningsByCurrency.length"
                        class="font-display text-3xl font-bold text-brand-forest dark:text-foreground"
                    >
                        0
                    </p>
                </div>
                <p
                    class="mt-4 flex items-center gap-1.5 text-xs leading-relaxed text-brand-forest/75 dark:text-muted-foreground"
                >
                    <Check class="size-3.5 shrink-0" aria-hidden="true" />{{
                        t('interface.from_completed_payments')
                    }}
                </p>
            </section>
            <section v-reveal class="mf-glass dashboard-metric p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-ink-muted dark:text-muted-foreground"
                    >
                        {{ t('interface.outstanding_balances') }}
                    </h2>
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-[#fbf0da] text-[#8a5b13] dark:bg-[#43371f] dark:text-[#edce91]"
                        ><Clock3 class="size-[18px]" aria-hidden="true"
                    /></span>
                </div>
                <div class="mt-5 flex flex-col gap-2">
                    <p
                        v-for="row in props.outstandingInvoiceTotalsByCurrency"
                        :key="row.currency"
                        class="font-display text-[clamp(1.4rem,2.1vw,2rem)] leading-tight font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
                    >
                        {{ money(row.total, row.currency) }}
                    </p>
                    <p
                        v-if="!props.outstandingInvoiceTotalsByCurrency.length"
                        class="font-display text-3xl font-bold text-brand-forest dark:text-foreground"
                    >
                        0
                    </p>
                </div>
                <p
                    class="mt-4 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    <AnimatedNumber :value="outstandingInvoiceCount" />{{
                        t('interface.outstanding_invoices')
                    }}
                </p>
            </section>
            <section v-reveal class="mf-glass dashboard-metric p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-ink-muted dark:text-muted-foreground"
                    >
                        {{ t('interface.total_invoiced') }}
                    </h2>
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-brand-mint text-brand-forest dark:bg-secondary dark:text-foreground"
                        ><FileText class="size-[18px]" aria-hidden="true"
                    /></span>
                </div>
                <div class="mt-5 flex flex-col gap-2">
                    <p
                        v-for="row in props.invoicedTotalsByCurrency"
                        :key="row.currency"
                        class="font-display text-[clamp(1.4rem,2.1vw,2rem)] leading-tight font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
                    >
                        {{ money(row.total, row.currency) }}
                    </p>
                    <p
                        v-if="!props.invoicedTotalsByCurrency.length"
                        class="font-display text-3xl font-bold text-brand-forest dark:text-foreground"
                    >
                        0
                    </p>
                </div>
                <p
                    class="mt-4 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{ t('interface.including_drafts')
                    }}<AnimatedNumber :value="totalInvoices" />{{
                        t('interface.invoices')
                    }}
                </p>
            </section>
            <section v-reveal class="mf-glass dashboard-metric p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-ink-muted dark:text-muted-foreground"
                    >
                        {{ t('interface.customer_connections') }}
                    </h2>
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-brand-mint text-brand-forest dark:bg-secondary dark:text-foreground"
                        ><Users class="size-[18px]" aria-hidden="true"
                    /></span>
                </div>
                <p
                    class="mt-5 font-display text-3xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                >
                    <AnimatedNumber :value="totalCustomers" />
                </p>
                <Link
                    :href="customersIndex()"
                    class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-emerald-dark hover:underline dark:text-[#8bdbb4]"
                    >{{ t('interface.manage_customers')
                    }}<ArrowUpRight class="size-3.5" aria-hidden="true"
                /></Link>
            </section>
        </div>

        <p
            class="-mt-3 flex items-start gap-2 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
        >
            <span
                class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-emerald"
                aria-hidden="true"
            ></span
            >{{
                t(
                    'interface.all_amounts_are_from_your_recorded_activity_shown_in_their_original_currencies',
                )
            }}
        </p>

        <section
            v-if="!totalInvoices && !recentPayments.length"
            v-reveal
            class="mf-card flex flex-col items-center gap-5 border-dashed px-6 py-10 text-center sm:flex-row sm:items-start sm:px-8 sm:text-left"
        >
            <div
                class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
            >
                <FileText
                    class="size-7"
                    :stroke-width="1.5"
                    aria-hidden="true"
                />
            </div>
            <div class="max-w-xl">
                <p class="mf-kicker mb-2">
                    {{ t('interface.room_for_your_next_chapter') }}
                </p>
                <h2
                    class="font-display text-2xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                >
                    {{ t('interface.good_work_deserves_a_great_invoice') }}
                </h2>
                <p
                    class="mt-2 text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.no_invoice_or_payment_activity_yet_add_a_customer_create_your_first_invoice_and_bring_your_business_into_view',
                        )
                    }}
                </p>
                <div
                    class="mt-5 flex flex-wrap justify-center gap-3 sm:justify-start"
                >
                    <Link
                        :href="create({ query: { mode: 'manual' } })"
                        class="mf-button"
                        >{{ t('interface.create_your_first_invoice')
                        }}<ArrowRight class="size-4" aria-hidden="true"
                    /></Link>
                    <Link
                        :href="customersIndex()"
                        class="mf-button-secondary"
                        >{{ t('interface.manage_customers') }}</Link
                    >
                </div>
            </div>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[1.3fr_1fr]">
            <section v-reveal class="mf-card min-w-0 overflow-hidden">
                <header
                    class="flex items-start justify-between gap-4 border-b border-line/80 p-5 sm:px-6 sm:py-5 dark:border-border/80"
                >
                    <div>
                        <p class="mf-kicker mb-1.5">
                            {{ t('interface.the_latest') }}
                        </p>
                        <h2
                            class="font-display text-xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                        >
                            {{ t('interface.recent_invoices') }}
                        </h2>
                    </div>
                    <Link
                        :href="invoicesIndex()"
                        class="mt-1 inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-emerald-dark hover:underline dark:text-[#8bdbb4]"
                        >{{ t('interface.view_all')
                        }}<ArrowUpRight class="size-4" aria-hidden="true"
                    /></Link>
                </header>
                <div
                    v-if="!recentInvoices.length"
                    class="flex min-h-48 flex-col items-center justify-center gap-2 p-8 text-center"
                >
                    <FileText
                        class="mb-1 size-7 text-brand-emerald/65"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <p
                        class="font-semibold text-brand-forest dark:text-foreground"
                    >
                        {{ t('interface.your_next_invoice_starts_here') }}
                    </p>
                    <p
                        class="max-w-xs text-sm text-ink-muted dark:text-muted-foreground"
                    >
                        {{
                            t(
                                'interface.new_invoices_will_appear_here_so_you_can_follow_their_progress',
                            )
                        }}
                    </p>
                </div>
                <div v-else class="divide-y divide-line/70 px-5 sm:px-6">
                    <Link
                        v-for="invoice in recentInvoices"
                        :key="invoice.id"
                        :href="show(invoice.id)"
                        class="group flex items-center gap-3 py-4 transition-colors hover:bg-brand-mint/25 dark:hover:bg-secondary/25"
                    >
                        <span
                            class="hidden size-10 shrink-0 items-center justify-center rounded-xl border border-line bg-canvas text-brand-forest sm:flex dark:border-border dark:bg-background dark:text-foreground"
                            ><FileText
                                class="size-[18px]"
                                :stroke-width="1.5"
                                aria-hidden="true"
                        /></span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-semibold text-brand-forest group-hover:text-brand-emerald-dark dark:text-foreground dark:hover:text-[#a8e8c9]"
                            >
                                {{
                                    invoice.customer?.name ||
                                    t('invoices.customer')
                                }}
                            </p>
                            <p
                                class="mt-1 truncate text-xs text-ink-muted dark:text-muted-foreground"
                            >
                                {{ invoice.invoice_number }}
                            </p>
                        </div>
                        <div class="min-w-0 text-right">
                            <p
                                class="text-sm font-semibold break-words text-brand-forest dark:text-foreground"
                            >
                                {{ money(invoice.total, invoice.currency) }}
                            </p>
                            <span
                                class="mt-1 inline-flex items-center gap-1.5 text-xs capitalize"
                                :class="
                                    invoice.status === 'paid'
                                        ? 'text-brand-emerald-dark dark:text-[#8bdbb4]'
                                        : invoice.status === 'draft'
                                          ? 'text-ink-muted dark:text-muted-foreground'
                                          : 'text-[#8a5b13] dark:text-[#edce91]'
                                "
                                ><span
                                    class="size-1.5 rounded-full bg-current"
                                    aria-hidden="true"
                                ></span
                                >{{ statusLabel(invoice.status) }}</span
                            >
                        </div>
                        <ArrowUpRight
                            class="ml-1 hidden size-4 shrink-0 text-ink-muted/50 transition-colors group-hover:text-brand-emerald-dark sm:block dark:hover:text-[#a8e8c9]"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
                <footer
                    class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-line/80 bg-canvas/70 px-5 py-3.5 text-xs text-ink-muted sm:px-6 dark:border-border/80 dark:bg-background/40 dark:text-muted-foreground"
                >
                    <span
                        ><strong
                            class="font-semibold text-brand-forest dark:text-foreground"
                            ><AnimatedNumber :value="totalInvoices" /></strong
                        >{{ t('interface.total_invoices') }}</span
                    ><span class="inline-flex items-center gap-1.5"
                        ><span
                            class="size-1.5 rounded-full bg-brand-emerald"
                            aria-hidden="true"
                        ></span
                        ><strong
                            class="font-semibold text-brand-forest dark:text-foreground"
                            ><AnimatedNumber
                                :value="paidInvoiceCount" /></strong
                        >{{ t('interface.paid') }}</span
                    ><span class="inline-flex items-center gap-1.5"
                        ><span
                            class="size-1.5 rounded-full bg-[#b7791f]"
                            aria-hidden="true"
                        ></span
                        >{{ outstandingInvoiceCount
                        }}{{ t('interface.outstanding') }}</span
                    >
                </footer>
            </section>

            <section v-reveal class="mf-card min-w-0 overflow-hidden">
                <header
                    class="flex items-start justify-between gap-4 border-b border-line/80 p-5 sm:px-6 sm:py-5 dark:border-border/80"
                >
                    <div>
                        <p class="mf-kicker mb-1.5">
                            {{ t('interface.money_in') }}
                        </p>
                        <h2
                            class="font-display text-xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                        >
                            {{ t('interface.completed_payments') }}
                        </h2>
                    </div>
                    <span
                        class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                        ><ArrowDownLeft class="size-4" aria-hidden="true"
                    /></span>
                </header>
                <div
                    v-if="!recentPayments.length"
                    class="flex min-h-48 flex-col items-center justify-center gap-2 p-8 text-center"
                >
                    <Wallet
                        class="mb-1 size-7 text-brand-emerald/65"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <p
                        class="font-semibold text-brand-forest dark:text-foreground"
                    >
                        {{ t('interface.a_place_for_every_payment') }}
                    </p>
                    <p
                        class="max-w-xs text-sm text-ink-muted dark:text-muted-foreground"
                    >
                        {{
                            t(
                                'interface.your_completed_payments_will_appear_here_as_they_arrive',
                            )
                        }}
                    </p>
                </div>
                <div v-else class="divide-y divide-line/70 px-5 sm:px-6">
                    <div
                        v-for="payment in recentPayments"
                        :key="payment.id"
                        class="flex items-center gap-3 py-4"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                            ><ArrowDownLeft class="size-4" aria-hidden="true"
                        /></span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-semibold text-brand-forest dark:text-foreground"
                            >
                                {{
                                    payment.invoice?.customer?.name ||
                                    t('interface.customer_payment')
                                }}
                            </p>
                            <p
                                class="mt-1 text-xs text-ink-muted dark:text-muted-foreground"
                            >
                                {{ date(payment.paid_at) }}
                            </p>
                        </div>
                        <p
                            class="text-right text-sm font-semibold break-words text-brand-emerald-dark dark:text-[#8bdbb4]"
                        >
                            {{ money(payment.amount, payment.currency) }}
                        </p>
                    </div>
                </div>
                <footer
                    class="border-t border-line/80 bg-canvas/70 px-5 py-3.5 sm:px-6 dark:border-border/80 dark:bg-background/40"
                >
                    <Link
                        :href="reportsIndex()"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-emerald-dark hover:underline dark:text-[#8bdbb4]"
                        >{{ t('interface.explore_your_reports')
                        }}<ArrowRight class="size-3.5" aria-hidden="true"
                    /></Link>
                </footer>
            </section>
        </div>

        <Link
            v-reveal
            :href="askIndex()"
            class="dashboard-assistant group relative flex flex-col items-start justify-between gap-5 overflow-hidden rounded-[22px] bg-brand-forest p-6 text-white sm:flex-row sm:items-center sm:p-7"
        >
            <div class="relative z-10 flex items-start gap-4">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-white/15 bg-white/10"
                    ><Sparkles
                        class="size-5 text-[#a8e8c9]"
                        :stroke-width="1.5"
                        aria-hidden="true"
                /></span>
                <div>
                    <p
                        class="mb-1 text-xs font-medium tracking-wide text-[#a8e8c9]"
                    >
                        ASK MTAAFLOW
                    </p>
                    <h2 class="font-display text-xl font-bold tracking-tight">
                        {{ t('interface.your_numbers_a_clearer_next_step') }}
                    </h2>
                    <p
                        class="mt-1.5 max-w-xl text-sm leading-relaxed text-[#cfe3da]"
                    >
                        {{
                            t(
                                'interface.ask_about_your_recorded_business_activity_or_draft_an_invoice_with_a_little_ai_assistance',
                            )
                        }}
                    </p>
                </div>
            </div>
            <span
                class="relative z-10 inline-flex shrink-0 items-center gap-2 text-sm font-semibold"
                >{{ t('interface.open_assistant')
                }}<ArrowUpRight
                    class="size-4 motion-safe:transition-transform motion-safe:group-hover:translate-x-0.5 motion-safe:group-hover:-translate-y-0.5"
                    aria-hidden="true"
            /></span>
        </Link>
    </div>
</template>

<style scoped>
.dashboard-metric {
    min-height: 184px;
}
.dashboard-metric-earnings {
    background: linear-gradient(125deg, var(--secondary), var(--mf-glass));
}
.dashboard-assistant::after {
    position: absolute;
    right: 3%;
    bottom: -180px;
    width: 370px;
    height: 370px;
    border: 1px solid rgb(207 238 224 / 10%);
    border-radius: 50%;
    box-shadow:
        0 0 0 42px rgb(207 238 224 / 4%),
        0 0 0 85px rgb(207 238 224 / 3%);
    content: '';
    pointer-events: none;
}
</style>
