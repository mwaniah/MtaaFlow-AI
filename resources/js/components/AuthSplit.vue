<script setup lang="ts">
import LanguageSelector from '@/components/LanguageSelector.vue';
import { translate as t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Check, FileText, Sparkles } from '@lucide/vue';
import { home } from '@/routes';
</script>

<template>
    <div
        class="auth-shell grid min-h-screen bg-[#faf9f6] text-[#202a27] lg:grid-cols-[1fr_1.08fr]"
    >
        <aside
            class="auth-story relative isolate hidden overflow-hidden px-10 py-10 text-white lg:flex lg:flex-col lg:justify-between xl:px-16 xl:py-12"
        >
            <div class="auth-orbit" aria-hidden="true" />
            <Link
                :href="home()"
                class="relative inline-flex w-fit items-center gap-3"
                :aria-label="t('interface.mtaaflow_ai_home')"
            >
                <span class="auth-mark" aria-hidden="true"><span /></span>
                <span
                    class="font-display text-2xl font-bold tracking-[-0.035em]"
                    >MtaaFlow<span class="ml-1 font-medium text-[#a8e5c5]"
                        >AI</span
                    ></span
                >
            </Link>

            <div class="relative py-12">
                <p
                    class="text-[10px] font-semibold tracking-[0.17em] text-[#b8d9c8] uppercase"
                >
                    {{ t('interface.a_little_clarity_a_better_flow') }}
                </p>
                <h2
                    class="mt-5 font-display text-[clamp(2.8rem,4.5vw,4.8rem)] leading-[1.02] font-semibold tracking-[-0.055em]"
                >
                    {{ t('interface.your_talent') }}<br />{{
                        t('interface.a_world_of')
                    }}<br /><span class="text-[#a8e5c5]">{{
                        t('interface.possibility')
                    }}</span>
                </h2>
                <p
                    class="mt-6 max-w-[350px] text-sm leading-relaxed text-[#c7ddd2]"
                >
                    {{
                        t(
                            'interface.bring_your_invoices_international_payments_and_business_insights_into_one_thoughtful_workspace',
                        )
                    }}
                </p>

                <div
                    class="auth-flow mt-10 max-w-[390px] rounded-[22px] border border-white/20 bg-white/8 p-5 backdrop-blur-xl xl:p-6"
                >
                    <div class="flex items-center justify-between gap-4">
                        <span
                            class="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/10"
                            ><FileText
                                class="size-5 text-[#c7f0da]"
                                aria-hidden="true" /></span
                        ><span
                            class="rounded-full border border-white/15 px-2.5 py-1.5 text-[9px] font-medium tracking-[0.1em] text-[#d0e1d9] uppercase"
                            >{{ t('interface.from_work_to_paid') }}</span
                        >
                    </div>
                    <p
                        class="mt-6 font-display text-xl font-medium tracking-tight"
                    >
                        {{ t('interface.a_good_day_s_work') }}<br />{{
                            t('interface.everything_else_in_focus')
                        }}
                    </p>
                    <div
                        class="mt-5 flex items-center gap-2.5 text-[10px] font-medium text-[#d0e1d9]"
                    >
                        <span class="flex items-center gap-1.5"
                            ><Check
                                class="size-3 text-[#a8e5c5]"
                                aria-hidden="true"
                            />{{ t('interface.create') }}</span
                        ><span
                            class="h-px flex-1 bg-white/20"
                            aria-hidden="true"
                        /><span>{{ t('interface.review') }}</span
                        ><span
                            class="h-px flex-1 bg-white/20"
                            aria-hidden="true"
                        /><span class="flex items-center gap-1.5"
                            >{{ t('interface.share')
                            }}<ArrowUpRight
                                class="size-3 text-[#a8e5c5]"
                                aria-hidden="true"
                        /></span>
                    </div>
                </div>
            </div>

            <div
                class="relative flex items-center justify-between gap-4 border-t border-white/15 pt-5 text-[10px] text-[#b8d9c8]"
            >
                <span>{{
                    t('interface.built_in_kenya_ready_for_the_world')
                }}</span
                ><Sparkles class="size-4 text-[#a8e5c5]" aria-hidden="true" />
            </div>
        </aside>

        <main
            class="relative flex flex-col justify-center px-5 py-8 sm:px-10 sm:py-12 lg:px-12"
        >
            <div class="mx-auto w-full max-w-[420px]">
                <Link
                    :href="home()"
                    class="mb-12 inline-flex items-center gap-3 lg:hidden"
                    :aria-label="t('interface.mtaaflow_ai_home')"
                    ><span class="auth-mark" aria-hidden="true"><span /></span
                    ><span
                        class="font-display text-2xl font-bold tracking-[-0.035em] text-[#123d32]"
                        >MtaaFlow<span class="ml-1 font-medium text-[#0f7d58]"
                            >AI</span
                        ></span
                    ></Link
                >
                <div class="mb-6 flex justify-end">
                    <LanguageSelector compact />
                </div>
                <slot />
                <div
                    class="mt-9 flex items-center gap-2 border-t border-[#123d32]/10 pt-5 text-[11px] text-[#6b7773]"
                >
                    <span
                        class="size-1.5 rounded-full bg-[#15966b]"
                        aria-hidden="true"
                    /><span>{{
                        t(
                            'interface.a_clearer_space_for_the_business_you_re_building',
                        )
                    }}</span>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.auth-story {
    background:
        radial-gradient(ellipse at 0% 100%, #2f755a75, transparent 65%),
        linear-gradient(155deg, #123d32, #164a3b);
}
.auth-story::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    opacity: 0.25;
    background-image:
        linear-gradient(#bce4ca13 1px, transparent 1px),
        linear-gradient(90deg, #bce4ca13 1px, transparent 1px);
    background-size: 70px 70px;
    mask-image: linear-gradient(transparent, black);
}
.auth-orbit {
    position: absolute;
    width: 560px;
    height: 560px;
    right: -320px;
    top: 16%;
    border: 1px solid #c7f0da12;
    border-radius: 50%;
    box-shadow:
        0 0 0 60px #c7f0da04,
        0 0 0 120px #c7f0da03;
    pointer-events: none;
}
.auth-mark {
    display: grid;
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    place-items: center;
    overflow: hidden;
    border-radius: 11px 11px 11px 3px;
    background: linear-gradient(145deg, #15966b, #123d32);
    box-shadow: inset 0 0 0 1px #ffffff25;
}
.auth-mark > span {
    width: 17px;
    height: 21px;
    transform: rotate(29deg);
    border: 2px solid #c7f0da;
    border-radius: 10px 2px 10px 2px;
}
.auth-flow {
    box-shadow:
        0 16px 45px #071e1620,
        inset 0 1px 0 #ffffff08;
}
</style>
