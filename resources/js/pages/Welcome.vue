<script setup lang="ts">
import LanguageSelector from '@/components/LanguageSelector.vue';
import { translate as t } from '@/lib/i18n';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    CreditCard,
    FileText,
    Globe,
    LayoutDashboard,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, home, login, register } from '@/routes';

withDefaults(defineProps<{ canRegister?: boolean }>(), { canRegister: true });
const page = usePage<{ auth?: { user?: unknown } }>();
const signedIn = computed(() => !!page.props.auth?.user);

const features = [
    {
        n: '01',
        icon: FileText,
        get title() {
            return t('interface.an_invoice_that_means_business');
        },
        get text() {
            return t(
                'interface.build_an_invoice_by_hand_review_every_detail_and_share_a_professional_payment_link_with_your_client',
            );
        },
        get label() {
            return t('interface.manual_invoicing');
        },
    },
    {
        n: '02',
        icon: Sparkles,
        get title() {
            return t('interface.less_admin_more_creative_space');
        },
        get text() {
            return t(
                'interface.describe_your_work_to_draft_an_invoice_or_ask_mtaaflow_about_your_actual_business_records_you_stay_in_control',
            );
        },
        get label() {
            return t('interface.ai_assistance');
        },
    },
    {
        n: '03',
        icon: CreditCard,
        get title() {
            return t('interface.your_work_travels_so_can_your_payments');
        },
        get text() {
            return t(
                'interface.let_international_clients_pay_through_paypal_with_payment_status_recorded_after_verification',
            );
        },
        get label() {
            return t('interface.paypal_checkout');
        },
    },
    {
        n: '04',
        icon: TrendingUp,
        get title() {
            return t('interface.a_clearer_view_of_what_comes_next');
        },
        get text() {
            return t(
                'interface.see_earnings_outstanding_invoices_and_customer_activity_together_grounded_in_your_own_account_data',
            );
        },
        get label() {
            return t('interface.financial_clarity');
        },
    },
];
const steps = [
    {
        n: '01',
        get title() {
            return t('interface.create_your_invoice');
        },
        get text() {
            return t(
                'interface.enter_the_details_yourself_or_start_with_an_ai_assisted_draft_your_work_your_rates_your_terms',
            );
        },
        icon: FileText,
    },
    {
        n: '02',
        get title() {
            return t('interface.review_then_share');
        },
        get text() {
            return t(
                'interface.check_every_line_and_confirm_when_you_are_ready_send_your_client_their_own_invoice_link',
            );
        },
        icon: Check,
    },
    {
        n: '03',
        get title() {
            return t('interface.let_the_payment_flow');
        },
        get text() {
            return t(
                'interface.your_client_reviews_the_invoice_and_pays_through_paypal_follow_verified_payments_from_your_workspace',
            );
        },
        icon: CreditCard,
    },
];
</script>

<template>
    <div class="landing min-h-screen bg-[#faf9f6] text-[#202a27]">
        <Head :title="t('interface.great_work_deserves_a_better_flow')" />
        <a
            href="#main-content"
            class="sr-only z-50 rounded-lg bg-white p-3 text-[#123d32] focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >{{ t('interface.skip_to_content') }}</a
        >
        <header
            class="landing-header relative z-20 border-b border-[#123d32]/8"
        >
            <div
                class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-x-5 gap-y-4 px-5 py-5 sm:px-8 lg:px-14"
            >
                <Link
                    :href="home()"
                    class="flex flex-1 items-center gap-3 text-[#123d32]"
                    :aria-label="t('interface.mtaaflow_ai_home')"
                >
                    <span class="brand-mark" aria-hidden="true"><span /></span>
                    <span
                        class="font-display text-xl font-bold tracking-[-0.04em] sm:text-2xl"
                        >MtaaFlow<span class="ml-1 font-medium text-[#0f7d58]"
                            >AI</span
                        ></span
                    >
                </Link>
                <nav
                    class="hidden items-center gap-8 text-sm font-medium text-[#53645c] md:flex"
                    :aria-label="t('interface.page_sections')"
                >
                    <a
                        href="#features"
                        class="transition-colors hover:text-[#0f7d58]"
                        >{{ t('interface.the_workspace') }}</a
                    >
                    <a
                        href="#how-it-works"
                        class="transition-colors hover:text-[#0f7d58]"
                        >{{ t('interface.how_it_flows') }}</a
                    >
                </nav>
                <div class="flex flex-wrap items-center gap-3 sm:gap-5">
                    <LanguageSelector compact />
                    <Link
                        v-if="!signedIn"
                        :href="login()"
                        class="min-h-11 content-center px-1 text-sm font-semibold text-[#123d32] underline-offset-4 hover:underline"
                        >{{ t('auth.login') }}</Link
                    >
                    <Link
                        v-if="signedIn || canRegister !== false"
                        :href="signedIn ? dashboard() : register()"
                        class="mf-button inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold sm:px-5"
                    >
                        {{ signedIn ? t('nav.dashboard') : t('auth.register') }}
                        <ArrowUpRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </header>
        <main id="main-content">
            <section class="hero relative isolate overflow-hidden text-white">
                <div class="hero-light hero-light-one" aria-hidden="true" />
                <div class="hero-light hero-light-two" aria-hidden="true" />
                <div class="hero-grid" aria-hidden="true" />
                <div
                    class="relative mx-auto grid max-w-[1440px] items-center gap-16 px-5 pt-16 pb-20 sm:px-8 sm:pt-20 lg:grid-cols-[1.04fr_1fr] lg:gap-12 lg:px-14 lg:pt-24 lg:pb-28"
                >
                    <div class="relative z-10">
                        <div
                            class="inline-flex items-center gap-2.5 rounded-full border border-white/15 bg-white/7 px-3 py-2 text-[11px] font-semibold tracking-[0.12em] text-[#d2e9dd] uppercase backdrop-blur-md sm:text-xs"
                        >
                            <span
                                class="size-1.5 rounded-full bg-[#8ee2b8]"
                                aria-hidden="true"
                            />{{ t('interface.made_for_work_without_borders') }}
                        </div>
                        <h1
                            class="mt-7 max-w-[690px] font-display text-[clamp(3rem,6vw,5.65rem)] leading-[0.99] font-semibold tracking-[-0.06em]"
                        >
                            {{ t('interface.great_work') }}<br /><span
                                class="text-[#a8e5c5]"
                                >{{ t('interface.a_better_flow') }}</span
                            >
                        </h1>
                        <p
                            class="mt-7 max-w-[470px] text-base leading-relaxed text-[#d0e1d9] sm:text-lg"
                        >
                            {{
                                t(
                                    'interface.from_your_next_invoice_to_your_next_international_payment_one_thoughtful_workspace_for_freelancers_building_beyond_borders',
                                )
                            }}
                        </p>
                        <div
                            class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-5"
                        >
                            <Link
                                v-if="signedIn || canRegister !== false"
                                :href="signedIn ? dashboard() : register()"
                                class="hero-cta inline-flex min-h-13 items-center justify-center gap-5 rounded-xl bg-[#e5f4ed] px-6 py-3.5 text-sm font-bold text-[#123d32]"
                                >{{
                                    signedIn
                                        ? t('interface.open_your_workspace')
                                        : t('interface.create_your_account')
                                }}
                                <ArrowUpRight class="size-5" aria-hidden="true"
                            /></Link>
                            <Link
                                v-else
                                :href="login()"
                                class="hero-cta inline-flex min-h-13 items-center gap-4 rounded-xl bg-[#e5f4ed] px-6 py-3.5 text-sm font-bold text-[#123d32]"
                                >{{ t('interface.log_in_to_your_workspace')
                                }}<ArrowUpRight
                                    class="size-5"
                                    aria-hidden="true"
                            /></Link>
                            <a
                                href="#how-it-works"
                                class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-white underline-offset-4 hover:underline"
                                >{{ t('interface.explore_the_flow')
                                }}<ArrowRight class="size-4" aria-hidden="true"
                            /></a>
                        </div>
                        <div
                            class="mt-10 flex flex-wrap gap-x-5 gap-y-3 text-xs text-[#c7ddd2]"
                        >
                            <span class="inline-flex items-center gap-2"
                                ><Check
                                    class="size-3.5 text-[#a8e5c5]"
                                    aria-hidden="true"
                                />{{
                                    t('interface.your_details_your_control')
                                }}</span
                            ><span class="inline-flex items-center gap-2"
                                ><Globe
                                    class="size-3.5 text-[#a8e5c5]"
                                    aria-hidden="true"
                                />{{
                                    t(
                                        'interface.built_in_kenya_ready_for_the_world',
                                    )
                                }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="workspace-stage relative mx-auto w-full max-w-[580px] pb-7 lg:pt-8"
                    >
                        <div
                            class="workspace-preview relative overflow-hidden rounded-[22px] border border-white/70 bg-[#fafcf9] text-[#202a27] shadow-2xl shadow-black/20"
                        >
                            <div
                                class="flex items-center justify-between gap-3 border-b border-[#123d32]/8 bg-white/90 px-5 py-4"
                            >
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="grid size-7 place-items-center rounded-lg bg-[#123d32] text-white"
                                        ><span
                                            class="font-display text-sm font-semibold"
                                            >M</span
                                        ></span
                                    ><span
                                        class="font-display text-sm font-bold tracking-tight"
                                        >{{
                                            t('interface.your_workspace')
                                        }}</span
                                    >
                                </div>
                                <span
                                    class="rounded-full border border-[#123d32]/10 px-2 py-1 text-[9px] font-semibold tracking-[0.08em] text-[#53645c] uppercase"
                                    >{{ t('interface.product_preview') }}</span
                                >
                            </div>
                            <div
                                class="grid grid-cols-[44px_1fr] sm:grid-cols-[52px_1fr]"
                            >
                                <div
                                    class="flex flex-col items-center gap-5 border-r border-[#123d32]/7 bg-[#eff5ef] py-6 text-[#7a8f82]"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="grid size-8 place-items-center rounded-lg bg-white text-[#0f7d58] shadow-sm"
                                        ><LayoutDashboard
                                            class="size-4" /></span
                                    ><FileText class="size-4" /><Users
                                        class="size-4"
                                    /><TrendingUp class="size-4" /><span
                                        class="mt-auto grid size-7 place-items-center rounded-full border border-[#15966b]/15 text-[#0f7d58]"
                                        ><Sparkles class="size-3.5"
                                    /></span>
                                </div>
                                <div class="min-w-0 p-4 sm:p-6">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <div>
                                            <p
                                                class="text-[10px] font-medium text-[#6b7773]"
                                            >
                                                {{
                                                    t(
                                                        'interface.a_little_clarity_goes_a_long_way',
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 font-display text-xl font-semibold tracking-[-0.03em] sm:text-2xl"
                                            >
                                                {{
                                                    t(
                                                        'interface.business_overview',
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <span
                                            class="grid size-8 shrink-0 place-items-center rounded-xl bg-[#e5f4ed] text-[#0f7d58]"
                                            aria-hidden="true"
                                            ><ArrowUpRight class="size-4"
                                        /></span>
                                    </div>
                                    <div
                                        class="mt-5 grid grid-cols-2 gap-2.5 sm:gap-3"
                                    >
                                        <div
                                            class="rounded-xl border border-[#123d32]/8 bg-white p-3.5"
                                        >
                                            <p
                                                class="text-[10px] font-medium text-[#53645c]"
                                            >
                                                {{
                                                    t(
                                                        'interface.payments_received',
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-2 font-display text-3xl text-[#123d32]"
                                            >
                                                &mdash;
                                            </p>
                                            <div
                                                class="mt-3 h-1 w-10 rounded-full bg-[#e5f4ed]"
                                                aria-hidden="true"
                                            />
                                        </div>
                                        <div
                                            class="rounded-xl border border-[#123d32]/8 bg-white p-3.5"
                                        >
                                            <p
                                                class="text-[10px] font-medium text-[#53645c]"
                                            >
                                                {{
                                                    t(
                                                        'invoices.outstandingTitle',
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-2 font-display text-3xl text-[#123d32]"
                                            >
                                                &mdash;
                                            </p>
                                            <div
                                                class="mt-3 h-1 w-10 rounded-full bg-[#ecebe5]"
                                                aria-hidden="true"
                                            />
                                        </div>
                                    </div>
                                    <div
                                        class="mt-3 rounded-xl border border-dashed border-[#123d32]/15 bg-white/65 px-4 py-6 text-center"
                                    >
                                        <span
                                            class="mx-auto grid size-10 place-items-center rounded-xl bg-[#e5f4ed] text-[#0f7d58]"
                                            aria-hidden="true"
                                            ><FileText class="size-5"
                                        /></span>
                                        <p
                                            class="mt-3 font-display text-sm font-semibold"
                                        >
                                            {{
                                                t(
                                                    'interface.your_data_appears_here',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mx-auto mt-1 max-w-48 text-[10px] leading-relaxed text-[#6b7773]"
                                        >
                                            {{
                                                t(
                                                    'interface.a_clear_view_of_your_invoices_and_verified_payments_as_your_business_grows',
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div
                                        class="mt-4 flex items-center justify-between gap-2 text-[10px] text-[#53645c]"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                            ><ShieldCheck
                                                class="size-3 text-[#0f7d58]"
                                                aria-hidden="true"
                                            />{{
                                                t(
                                                    'interface.your_account_your_records',
                                                )
                                            }}</span
                                        ><span
                                            aria-hidden="true"
                                            class="flex gap-1"
                                            ><span
                                                class="size-1 rounded-full bg-[#15966b]" /><span
                                                class="size-1 rounded-full bg-[#d5e6db]" /><span
                                                class="size-1 rounded-full bg-[#d5e6db]"
                                        /></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="preview-note absolute right-1 -bottom-1 flex max-w-[250px] items-center gap-3 rounded-2xl border border-white/25 bg-[#214f41]/85 px-4 py-3 text-white shadow-xl backdrop-blur-xl sm:-right-3"
                        >
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-xl border border-white/15 bg-white/10"
                                ><Sparkles
                                    class="size-4 text-[#a8e5c5]"
                                    aria-hidden="true"
                            /></span>
                            <div>
                                <p class="text-xs font-semibold">
                                    {{ t('interface.a_little_less_busywork') }}
                                </p>
                                <p
                                    class="mt-0.5 text-[10px] leading-relaxed text-[#c7ddd2]"
                                >
                                    {{
                                        t(
                                            'interface.ai_drafts_you_make_the_call',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative border-t border-white/10">
                    <div
                        class="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-5 px-5 py-5 text-[11px] font-medium tracking-[0.04em] text-[#d0e1d9] sm:px-8 sm:text-xs lg:px-14"
                    >
                        <p class="text-[#9cbcae]">
                            {{ t('interface.one_workspace_a_clearer_workday') }}
                        </p>
                        <div class="flex flex-wrap gap-x-7 gap-y-3">
                            <span class="flex items-center gap-2"
                                ><FileText
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ t('nav.invoices') }}</span
                            ><span class="flex items-center gap-2"
                                ><Sparkles
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ t('interface.ai_assistance') }}</span
                            ><span class="flex items-center gap-2"
                                ><CreditCard
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ t('interface.paypal_payments') }}</span
                            ><span class="flex items-center gap-2"
                                ><TrendingUp
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ t('interface.reports') }}</span
                            >
                        </div>
                    </div>
                </div>
            </section>
            <section
                id="features"
                class="mx-auto max-w-[1440px] scroll-mt-8 px-5 py-20 sm:px-8 lg:px-14 lg:py-28"
            >
                <div
                    v-reveal
                    class="grid gap-5 md:grid-cols-[1.1fr_0.7fr] md:items-end md:gap-16"
                >
                    <div>
                        <p class="mf-kicker">
                            {{ t('interface.space_for_your_best_work') }}
                        </p>
                        <h2
                            class="mt-4 max-w-[600px] font-display text-4xl leading-[1.06] font-semibold tracking-[-0.045em] text-[#123d32] sm:text-5xl"
                        >
                            {{ t('interface.less_keeping_up') }}<br />{{
                                t('interface.more_moving_forward')
                            }}
                        </h2>
                    </div>
                    <p
                        class="max-w-[380px] text-base leading-relaxed text-[#53645c]"
                    >
                        {{
                            t(
                                'interface.the_essentials_of_running_a_freelance_business_brought_together_with_care_from_the_first_draft_to_the_bigger_picture',
                            )
                        }}
                    </p>
                </div>
                <div class="mt-11 grid gap-5 md:grid-cols-2">
                    <article
                        v-for="feature in features"
                        :key="feature.n"
                        v-reveal
                        class="feature-card group relative overflow-hidden rounded-[24px] border border-[#123d32]/10 p-6 sm:p-8"
                    >
                        <div class="flex items-start justify-between gap-5">
                            <span
                                class="grid size-12 place-items-center rounded-2xl border border-[#15966b]/10 bg-[#e5f4ed] text-[#0f7d58]"
                                ><component
                                    :is="feature.icon"
                                    class="size-5"
                                    aria-hidden="true" /></span
                            ><span
                                class="font-display text-xs font-medium text-[#53645c]"
                                >{{ feature.n }} /</span
                            >
                        </div>
                        <p
                            class="mt-9 text-[10px] font-bold tracking-[0.14em] text-[#0f7d58] uppercase"
                        >
                            {{ feature.label }}
                        </p>
                        <h3
                            class="mt-3 max-w-[360px] font-display text-2xl leading-[1.12] font-semibold tracking-[-0.035em] text-[#123d32] sm:text-[29px]"
                        >
                            {{ feature.title }}
                        </h3>
                        <p
                            class="mt-4 max-w-[440px] text-sm leading-relaxed text-[#53645c]"
                        >
                            {{ feature.text }}
                        </p>
                    </article>
                </div>
            </section>
            <section
                id="how-it-works"
                class="scroll-mt-8 border-y border-[#123d32]/8 bg-[#e5f4ed]/55"
            >
                <div
                    class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:px-14 lg:py-24"
                >
                    <div
                        v-reveal
                        class="flex flex-wrap items-end justify-between gap-6"
                    >
                        <div>
                            <p class="mf-kicker">
                                {{ t('interface.from_work_to_paid') }}
                            </p>
                            <h2
                                class="mt-4 font-display text-4xl leading-[1.08] font-semibold tracking-[-0.045em] text-[#123d32] sm:text-5xl"
                            >
                                {{ t('interface.a_good_flow_is_simple') }}
                            </h2>
                        </div>
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-[#123d32]/12 bg-white/60 px-3 py-2 text-xs font-medium text-[#123d32]"
                            ><ShieldCheck
                                class="size-3.5"
                                aria-hidden="true"
                            />{{
                                t('interface.you_re_in_control_at_every_step')
                            }}</span
                        >
                    </div>
                    <ol class="mt-12 grid gap-8 md:grid-cols-3 md:gap-10">
                        <li
                            v-for="step in steps"
                            :key="step.n"
                            v-reveal
                            class="relative border-t border-[#123d32]/18 pt-7"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-display text-sm font-medium text-[#0f7d58]"
                                    >{{ step.n }}</span
                                ><component
                                    :is="step.icon"
                                    class="size-5 text-[#0f7d58]"
                                    aria-hidden="true"
                                />
                            </div>
                            <h3
                                class="mt-7 font-display text-2xl font-semibold tracking-[-0.03em] text-[#123d32]"
                            >
                                {{ step.title }}
                            </h3>
                            <p
                                class="mt-3 max-w-[330px] text-sm leading-relaxed text-[#53645c]"
                            >
                                {{ step.text }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>
            <section
                class="mx-auto max-w-[1440px] px-5 py-16 sm:px-8 lg:px-14 lg:py-24"
            >
                <div
                    v-reveal
                    class="closing-panel relative overflow-hidden rounded-[28px] px-7 py-12 text-white sm:px-12 md:flex md:items-center md:justify-between md:gap-10 lg:px-16 lg:py-16"
                >
                    <div class="relative">
                        <p
                            class="text-[11px] font-semibold tracking-[0.13em] text-[#a8e5c5] uppercase"
                        >
                            {{ t('interface.your_next_chapter_starts_here') }}
                        </p>
                        <h2
                            class="mt-4 font-display text-4xl leading-[1.05] font-semibold tracking-[-0.045em] sm:text-5xl"
                        >
                            {{ t('interface.make_room_for') }}<br />{{
                                t('interface.what_you_do_best')
                            }}
                        </h2>
                        <p
                            class="mt-5 max-w-[420px] text-sm leading-relaxed text-[#c7ddd2]"
                        >
                            {{
                                t(
                                    'interface.let_mtaaflow_bring_the_invoices_payments_and_business_details_into_focus',
                                )
                            }}
                        </p>
                    </div>
                    <div
                        class="relative mt-8 flex shrink-0 flex-col items-start gap-4 md:mt-0"
                    >
                        <Link
                            v-if="signedIn || canRegister !== false"
                            :href="signedIn ? dashboard() : register()"
                            class="hero-cta inline-flex min-h-13 items-center gap-8 rounded-xl bg-[#e5f4ed] px-6 py-3.5 text-sm font-bold text-[#123d32]"
                            >{{
                                signedIn
                                    ? t('interface.go_to_your_dashboard')
                                    : t('landing.findFlow')
                            }}<ArrowUpRight
                                class="size-5"
                                aria-hidden="true" /></Link
                        ><Link
                            v-if="!signedIn"
                            :href="login()"
                            class="inline-flex min-h-11 items-center text-xs text-[#d0e1d9] underline-offset-4 hover:underline"
                            >{{ t('interface.already_have_an_account_log_in')
                            }}<ArrowRight
                                class="ml-2 size-3.5"
                                aria-hidden="true"
                        /></Link>
                    </div>
                </div>
            </section>
        </main>
        <footer class="border-t border-[#123d32]/10">
            <div
                class="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-6 px-5 py-9 sm:px-8 lg:px-14"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-3 text-[#123d32]"
                    ><span
                        class="brand-mark brand-mark-small"
                        aria-hidden="true"
                        ><span /></span
                    ><span class="font-display text-lg font-bold tracking-tight"
                        >MtaaFlow AI</span
                    ></Link
                >
                <p class="text-xs text-[#53645c]">
                    {{ t('interface.built_in_kenya_for_the_way_you_work') }}
                </p>
                <p class="text-[11px] text-[#6b7773]">
                    &copy; {{ new Date().getFullYear() }} MtaaFlow AI
                </p>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.landing-header {
    background: rgba(250, 249, 246, 0.92);
    backdrop-filter: blur(16px);
}
.brand-mark {
    position: relative;
    display: grid;
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    place-items: center;
    overflow: hidden;
    border-radius: 11px 11px 11px 3px;
    background: linear-gradient(145deg, #15966b, #123d32);
    box-shadow: 0 4px 10px #123d3214;
}
.brand-mark > span {
    width: 17px;
    height: 21px;
    transform: rotate(29deg);
    border: 2px solid #c7f0da;
    border-radius: 10px 2px 10px 2px;
}
.brand-mark-small {
    width: 30px;
    height: 30px;
}
.hero {
    background: linear-gradient(118deg, #123d32 5%, #153f33 50%, #215642);
}
.hero-grid {
    position: absolute;
    inset: 0;
    opacity: 0.14;
    background-image:
        linear-gradient(#bce4ca1c 1px, transparent 1px),
        linear-gradient(90deg, #bce4ca1c 1px, transparent 1px);
    background-size: 80px 80px;
    mask-image: linear-gradient(90deg, transparent, black);
    pointer-events: none;
}
.hero-light {
    position: absolute;
    width: 700px;
    height: 700px;
    border-radius: 50%;
    filter: blur(85px);
    pointer-events: none;
}
.hero-light-one {
    top: -420px;
    right: -70px;
    background: #7bd7a62b;
    animation: ambient-drift 16s ease-in-out infinite alternate;
}
.hero-light-two {
    bottom: -610px;
    left: 10%;
    background: #15966b5c;
}
.workspace-preview {
    transform: perspective(1200px) rotateY(-5deg) rotateX(2deg);
    transform-origin: center;
}
.workspace-stage::before {
    content: '';
    position: absolute;
    inset: 14% -3% 9% 5%;
    transform: rotate(6deg);
    border: 1px solid #ffffff1c;
    border-radius: 25px;
    background: #ffffff06;
}
.preview-note {
    animation: note-enter 900ms 200ms both;
}
.hero-cta {
    transition:
        background-color 180ms ease,
        transform 180ms ease,
        box-shadow 180ms ease;
}
.hero-cta:hover {
    background: #fff;
    transform: translateY(-2px);
    box-shadow: 0 9px 25px #00000015;
}
.feature-card {
    background: linear-gradient(145deg, #ffffffef, #f9fbf8);
    transition:
        transform 200ms ease,
        box-shadow 200ms ease,
        border-color 200ms ease;
}
.feature-card:nth-child(2),
.feature-card:nth-child(3) {
    background: linear-gradient(130deg, #f1f7f1, #ffffffc7);
}
.feature-card:hover {
    transform: translateY(-4px);
    border-color: #15966b40;
    box-shadow: 0 20px 40px -30px #123d3255;
}
.closing-panel {
    background:
        radial-gradient(ellipse at 95% 30%, #34896555, transparent 60%), #123d32;
}
.closing-panel::after {
    content: '';
    position: absolute;
    width: 390px;
    height: 390px;
    right: -130px;
    top: -120px;
    border: 1px solid #bde5cf1a;
    border-radius: 50%;
    box-shadow:
        0 0 0 45px #bde5cf08,
        0 0 0 90px #bde5cf04;
    pointer-events: none;
}
@keyframes ambient-drift {
    to {
        transform: translate(-90px, 60px) scale(1.12);
    }
}
@keyframes note-enter {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (max-width: 640px) {
    .workspace-preview {
        transform: none;
    }
    .workspace-stage::before {
        inset: 10% 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .hero-light-one,
    .preview-note {
        animation: none;
    }
    .hero-cta,
    .feature-card {
        transition: none;
    }
    .hero-cta:hover,
    .feature-card:hover {
        transform: none;
    }
}
</style>
