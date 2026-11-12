<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    BarChart3,
    Check,
    Clock3,
    FileText,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AnimatedNumber from '@/components/AnimatedNumber.vue';
import { show as customerShow } from '@/routes/customers';
import { index as invoicesIndex } from '@/routes/invoices';
import { money, type CurrencyTotal } from '@/types/business';

const props = defineProps<{
    verifiedEarningsByCurrency: CurrencyTotal[];
    monthlyPaymentSummaries: { month: string; currencies: CurrencyTotal[] }[];
    outstandingInvoiceTotalsByCurrency: CurrencyTotal[];
    paidInvoiceCount: number;
    unpaidInvoiceCount: number;
    customerPaymentSummaries: (CurrencyTotal & {
        customerId: number;
        customerName: string;
    })[];
}>();

const currencyCharts = computed(() => {
    const currencies = new Set(
        props.monthlyPaymentSummaries.flatMap((month) =>
            month.currencies.map((row) => row.currency),
        ),
    );

    return [...currencies].map((currency) => {
        const points = props.monthlyPaymentSummaries.flatMap((month) => {
            const row = month.currencies.find(
                (summary) => summary.currency === currency,
            );
            return row ? [{ month: month.month, ...row }] : [];
        });
        const maximum = Math.max(
            ...points.map((point) => Number(point.total)),
            1,
        );

        return { currency, points, maximum };
    });
});
</script>

<template>
    <div class="mf-page flex flex-col gap-7">
        <Head :title="t('nav.reports')" />
        <header v-reveal class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="mf-kicker mb-2">
                    {{ t('interface.a_clearer_perspective') }}
                </p>
                <h1
                    class="font-display text-3xl font-bold tracking-tight text-brand-forest sm:text-4xl dark:text-foreground"
                >
                    {{ t('nav.reports') }}
                </h1>
                <p
                    class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.understand_what_has_arrived_what_is_outstanding_and_the_customers_behind_your_work',
                        )
                    }}
                </p>
            </div>
            <Link :href="invoicesIndex()" class="mf-button-secondary"
                >{{ t('interface.view_invoices')
                }}<ArrowUpRight class="size-4" aria-hidden="true"
            /></Link>
        </header>

        <div class="grid gap-4 md:grid-cols-3">
            <section
                v-reveal
                class="mf-glass bg-brand-mint/60 p-6 dark:bg-secondary/60"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-brand-forest dark:text-foreground"
                    >
                        {{ t('interface.verified_earnings') }}
                    </h2>
                    <Wallet
                        class="size-5 text-brand-emerald-dark dark:text-[#8bdbb4]"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                </div>
                <div class="mt-5 flex flex-col gap-2">
                    <p
                        v-for="row in props.verifiedEarningsByCurrency"
                        :key="row.currency"
                        class="font-display text-3xl font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
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
                    class="mt-4 inline-flex items-center gap-1.5 text-xs text-brand-forest/75 dark:text-muted-foreground"
                >
                    <Check class="size-3.5" aria-hidden="true" />{{
                        t('interface.completed_payments_only')
                    }}
                </p>
            </section>
            <section v-reveal class="mf-glass p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-ink-muted dark:text-muted-foreground"
                    >
                        {{ t('interface.outstanding_balances') }}
                    </h2>
                    <Clock3
                        class="size-5 text-[#8a5b13] dark:text-[#edce91]"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                </div>
                <div class="mt-5 flex flex-col gap-2">
                    <p
                        v-for="row in props.outstandingInvoiceTotalsByCurrency"
                        :key="row.currency"
                        class="font-display text-3xl font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
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
                    class="mt-4 text-xs text-ink-muted dark:text-muted-foreground"
                >
                    {{ t('interface.remaining_balances_on_approved_invoices') }}
                </p>
            </section>
            <section v-reveal class="mf-glass p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2
                        class="text-sm font-medium text-ink-muted dark:text-muted-foreground"
                    >
                        {{ t('interface.invoice_progress') }}
                    </h2>
                    <FileText
                        class="size-5 text-brand-emerald-dark dark:text-[#8bdbb4]"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                </div>
                <div class="mt-5 flex items-baseline gap-2">
                    <AnimatedNumber
                        :value="paidInvoiceCount"
                        class="font-display text-3xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                    /><span
                        class="text-sm text-ink-muted dark:text-muted-foreground"
                        >{{ t('interface.paid') }}</span
                    >
                </div>
                <p
                    class="mt-4 flex items-center gap-1.5 text-xs text-ink-muted dark:text-muted-foreground"
                >
                    <span
                        class="size-1.5 rounded-full bg-[#b7791f]"
                        aria-hidden="true"
                    ></span
                    ><AnimatedNumber :value="unpaidInvoiceCount" />{{
                        t('interface.outstanding_invoices')
                    }}
                </p>
            </section>
        </div>

        <section v-reveal class="mf-card min-w-0 overflow-hidden">
            <header
                class="flex flex-wrap items-start justify-between gap-3 border-b border-line/80 p-5 sm:p-6 dark:border-border/80"
            >
                <div>
                    <p class="mf-kicker mb-1.5">
                        {{ t('interface.payment_rhythm') }}
                    </p>
                    <h2
                        class="font-display text-xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                    >
                        {{ t('interface.monthly_payment_summaries') }}
                    </h2>
                    <p
                        class="mt-2 text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                    >
                        {{
                            t(
                                'interface.your_dated_completed_payments_each_currency_has_its_own_scale',
                            )
                        }}
                    </p>
                </div>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-line bg-canvas px-3 py-1.5 text-xs text-ink-muted dark:border-border dark:bg-background dark:text-muted-foreground"
                    ><span
                        class="size-1.5 rounded-full bg-brand-emerald"
                        aria-hidden="true"
                    ></span
                    >{{ t('interface.recorded_activity') }}</span
                >
            </header>
            <div
                v-if="!monthlyPaymentSummaries.length"
                class="flex flex-col items-center gap-3 px-6 py-14 text-center"
            >
                <span
                    class="flex size-14 items-center justify-center rounded-2xl bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                    ><BarChart3
                        class="size-6"
                        :stroke-width="1.5"
                        aria-hidden="true"
                /></span>
                <h3
                    class="mt-1 font-display text-xl font-semibold text-brand-forest dark:text-foreground"
                >
                    {{ t('interface.your_story_will_take_shape_here') }}
                </h3>
                <p
                    class="max-w-sm text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.no_dated_completed_payments_to_report_yet_your_real_payment_history_will_bring_this_view_to_life',
                        )
                    }}
                </p>
            </div>
            <div v-else class="divide-y divide-line/80">
                <figure
                    v-for="chart in currencyCharts"
                    :key="chart.currency"
                    class="p-5 sm:p-6"
                >
                    <figcaption
                        class="mb-5 flex items-center justify-between gap-3"
                    >
                        <span
                            class="text-sm font-semibold text-brand-forest dark:text-foreground"
                            >{{ chart.currency
                            }}{{ t('interface.payments') }}</span
                        ><span
                            class="text-xs text-ink-muted dark:text-muted-foreground"
                            >{{ t('interface.monthly_totals') }}</span
                        >
                    </figcaption>
                    <div class="overflow-x-auto pb-1">
                        <div
                            class="flex min-w-full gap-3"
                            :style="{
                                minWidth: `${chart.points.length * 90}px`,
                            }"
                        >
                            <div
                                v-for="(point, index) in chart.points"
                                :key="point.month"
                                class="min-w-0 flex-1"
                                :aria-label="
                                    t('payments.chartLabel', {
                                        month: point.month,
                                        amount: money(
                                            point.total,
                                            point.currency,
                                        ),
                                        count: point.paymentCount ?? 0,
                                    })
                                "
                            >
                                <div
                                    class="report-chart-column relative flex h-40 items-end justify-center border-b border-line dark:border-border"
                                    aria-hidden="true"
                                >
                                    <div
                                        class="report-chart-bar relative w-full max-w-16 rounded-t-lg bg-linear-to-t from-brand-emerald-dark to-brand-emerald"
                                        :style="{
                                            height: `${Math.max(0, (Number(point.total) / chart.maximum) * 100)}%`,
                                            animationDelay: `${Math.min(index * 55, 330)}ms`,
                                        }"
                                    >
                                        <span
                                            class="absolute top-0 left-0 h-1/2 w-full rounded-t-lg bg-linear-to-b from-white/20 to-transparent"
                                        ></span>
                                    </div>
                                </div>
                                <p
                                    class="mt-3 text-center text-xs font-medium text-brand-forest dark:text-foreground"
                                >
                                    {{ point.month }}
                                </p>
                                <p
                                    class="mt-1 text-center text-[11px] text-ink-muted dark:text-muted-foreground"
                                >
                                    {{ point.paymentCount
                                    }}{{ t('interface.payments') }}
                                </p>
                                <p
                                    class="mt-1 text-center text-xs font-semibold break-words text-brand-forest dark:text-foreground"
                                >
                                    {{ money(point.total, point.currency) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </figure>
            </div>
            <footer
                class="border-t border-line/80 bg-canvas/70 px-5 py-3.5 text-xs leading-relaxed text-ink-muted sm:px-6 dark:border-border/80 dark:bg-background/40 dark:text-muted-foreground"
            >
                {{
                    t(
                        'interface.amounts_stay_in_their_original_currency_no_currencies_are_combined_or_converted',
                    )
                }}
            </footer>
        </section>

        <section v-reveal class="mf-card min-w-0 overflow-hidden">
            <header
                class="border-b border-line/80 p-5 sm:p-6 dark:border-border/80"
            >
                <p class="mf-kicker mb-1.5">
                    {{ t('interface.the_people_behind_the_payments') }}
                </p>
                <h2
                    class="font-display text-xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                >
                    {{ t('interface.customer_payment_activity') }}
                </h2>
            </header>
            <div
                v-if="!customerPaymentSummaries.length"
                class="px-6 py-10 text-center"
            >
                <p class="font-semibold text-brand-forest dark:text-foreground">
                    {{ t('interface.no_completed_customer_payments_yet') }}
                </p>
                <p
                    class="mt-2 text-sm text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.as_payments_arrive_see_each_customer_s_contribution_in_its_original_currency',
                        )
                    }}
                </p>
            </div>
            <div v-else class="divide-y divide-line/70 px-5 sm:px-6">
                <div
                    class="hidden grid-cols-[1fr_130px_1fr] gap-4 py-3.5 text-[11px] font-semibold tracking-wide text-ink-muted uppercase sm:grid dark:text-muted-foreground"
                >
                    <span>{{ t('invoices.customer') }}</span
                    ><span class="text-right">{{ t('nav.payments') }}</span
                    ><span class="text-right">{{
                        t('interface.completed_amount')
                    }}</span>
                </div>
                <div
                    v-for="row in customerPaymentSummaries"
                    :key="`${row.customerId}-${row.currency}`"
                    class="grid items-center gap-2 py-4 sm:grid-cols-[1fr_130px_1fr] sm:gap-4"
                >
                    <Link
                        :href="customerShow(row.customerId)"
                        class="inline-flex min-w-0 items-center gap-2 text-sm font-semibold text-brand-forest hover:text-brand-emerald-dark hover:underline dark:text-foreground dark:hover:text-[#a8e8c9]"
                        ><span class="truncate">{{ row.customerName }}</span
                        ><ArrowUpRight
                            class="size-3.5 shrink-0 text-brand-emerald-dark dark:text-[#8bdbb4]"
                            aria-hidden="true"
                    /></Link>
                    <p
                        class="text-xs text-ink-muted sm:text-right dark:text-muted-foreground"
                    >
                        {{ row.paymentCount }}{{ t('interface.payments') }}
                    </p>
                    <p
                        class="font-display text-base font-semibold break-words text-brand-forest sm:text-right dark:text-foreground"
                    >
                        {{ money(row.total, row.currency) }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.report-chart-column {
    background-image: repeating-linear-gradient(
        to top,
        transparent 0,
        transparent calc(25% - 1px),
        var(--border) calc(25% - 1px),
        var(--border) 25%
    );
}
.report-chart-bar {
    transform-origin: bottom;
}
@media (prefers-reduced-motion: no-preference) {
    .report-chart-bar {
        animation: report-bar-arrive 650ms cubic-bezier(0.16, 1, 0.3, 1) both;
    }
}
@keyframes report-bar-arrive {
    from {
        opacity: 0.4;
        transform: scaleY(0.1);
    }
    to {
        opacity: 1;
        transform: scaleY(1);
    }
}
</style>
