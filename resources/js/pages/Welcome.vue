<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ canRegister?: boolean }>();

const page = usePage<{ auth?: { user?: unknown } }>();
const signedIn = computed(() => !!page.props.auth?.user);

const features = [
    { n: '01', title: 'AI-powered invoices', text: 'Describe your work in plain language. Review and send an invoice in minutes.' },
    { n: '02', title: 'PayPal payments', text: 'Share a payment link with clients around the world and track verified payments.' },
    { n: '03', title: 'Clear financial insights', text: 'Understand earnings, outstanding invoices and customer activity at a glance.' },
];

const steps = [
    { n: '1', title: 'Describe the sale', text: 'Type what you sold, to whom and when it is due. MtaaFlow AI drafts the invoice.' },
    { n: '2', title: 'Review and approve', text: 'Check every line and edit anything. Nothing is saved or sent until you approve it.' },
    { n: '3', title: 'Get paid with PayPal', text: 'Your client pays through PayPal and the payment shows up once it is verified.' },
];

const bars = [
    { h: 29, c: 'bg-[#9fcfad]' },
    { h: 50, c: 'bg-[#6bb18a]' },
    { h: 65, c: 'bg-[#1d6c56]' },
    { h: 42, c: 'bg-[#9fcfad]' },
    { h: 76, c: 'bg-[#1d6c56]' },
];
</script>

<template>
    <Head title="Your business flows better with AI" />
    <div class="min-h-screen bg-[#faf9f6] text-[#173d31]">
        <header class="mx-auto flex max-w-[1440px] items-center gap-6 px-6 py-6 lg:px-[82px]">
            <Link href="/" class="flex flex-1 items-center gap-3.5">
                <span class="font-display grid size-[37px] place-items-center rounded-xl bg-[#1d6c56] text-[23px] font-bold text-white" aria-hidden="true">M</span>
                <span class="font-display text-2xl font-bold">MtaaFlow AI</span>
            </Link>
            <nav class="hidden items-center gap-10 text-[15px] text-[#172c27] sm:flex" aria-label="Page sections">
                <a href="#features" class="hover:underline">Features</a>
                <a href="#how-it-works" class="hover:underline">How it works</a>
            </nav>
            <Link :href="signedIn ? '/dashboard' : '/login'" class="font-display rounded-xl bg-[#1d6c56] px-[39px] py-[11px] text-base font-bold whitespace-nowrap text-white hover:bg-[#173d31]">
                {{ signedIn ? 'Dashboard' : 'Log in' }}
            </Link>
        </header>

        <section class="bg-[#e9f4ea]">
            <div class="mx-auto grid max-w-[1440px] items-center gap-12 px-6 py-14 lg:grid-cols-2 lg:px-[82px] lg:py-[75px]">
                <div class="flex flex-col gap-5">
                    <p class="font-display text-sm font-bold tracking-wide text-[#1d6c56] uppercase">For freelancers &amp; small businesses</p>
                    <h1 class="font-display text-[40px] leading-[1.1] font-bold tracking-[-0.02em] sm:text-[58px]">Your business flows better with AI.</h1>
                    <p class="max-w-[650px] text-lg leading-[1.45] text-[#53645c] sm:text-xl">
                        Create professional invoices in seconds, get paid internationally with PayPal, and keep every customer and payment in one place.
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-8">
                        <Link :href="signedIn ? '/dashboard' : '/register'" class="font-display rounded-[14px] bg-[#1d6c56] px-8 py-[15px] text-xl font-bold text-white hover:bg-[#173d31]">
                            {{ signedIn ? 'Go to dashboard' : 'Get started' }}
                        </Link>
                        <a href="#how-it-works" class="font-display text-[17px] font-bold text-[#1d6c56] hover:underline">See how it works →</a>
                    </div>
                </div>

                <!-- Decorative preview of the product. The numbers are illustrative, not live data. -->
                <div class="relative mx-auto w-full max-w-[486px]" aria-hidden="true">
                    <div class="absolute -top-8 right-[-10px] size-[300px] rounded-full bg-[#cde8d5]" />
                    <div class="relative flex flex-col gap-3 rounded-3xl bg-white p-6">
                        <div class="font-display flex h-[54px] items-center rounded-xl bg-[#f0f5f1] px-5 text-base font-bold text-[#1d6c56]">MtaaFlow AI &nbsp;/&nbsp; Overview</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-[15px] bg-[#f5f7f3] p-4">
                                <p class="text-sm text-[#53645c]">Payments received</p>
                                <p class="font-display mt-1 text-[26px] font-bold">$ 2,450.00</p>
                            </div>
                            <div class="rounded-[15px] bg-[#f5f7f3] p-4">
                                <p class="text-sm text-[#53645c]">Invoices paid</p>
                                <p class="font-display mt-1 text-2xl font-bold">12 this month</p>
                            </div>
                        </div>
                        <div class="rounded-[15px] bg-[#f5f7f3] p-5">
                            <p class="font-display text-lg font-bold">Your business, at a glance</p>
                            <div class="mt-4 flex h-[76px] items-end gap-3.5 px-2">
                                <span v-for="(b, i) in bars" :key="i" class="w-12 rounded-md" :class="b.c" :style="{ height: `${b.h}px` }" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="mx-auto max-w-[1440px] scroll-mt-6 px-6 py-14 lg:px-[82px]">
            <h2 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.01em] sm:text-[34px]">Everything you need to get paid and grow</h2>
            <div class="mt-8 grid gap-6 md:grid-cols-3">
                <article v-for="f in features" :key="f.n" class="flex min-h-[179px] flex-col gap-2 rounded-[18px] bg-white p-6">
                    <span class="font-display mb-3 grid size-11 place-items-center rounded-xl bg-[#ddefe2] text-base font-bold text-[#1d6c56]">{{ f.n }}</span>
                    <h3 class="font-display text-[21px] leading-[1.15] font-bold">{{ f.title }}</h3>
                    <p class="text-[15px] leading-[1.45] text-[#53645c]">{{ f.text }}</p>
                </article>
            </div>
        </section>

        <section id="how-it-works" class="mx-auto max-w-[1440px] scroll-mt-6 px-6 pb-14 lg:px-[82px]">
            <h2 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.01em] sm:text-[34px]">How it works</h2>
            <ol class="mt-8 grid gap-6 md:grid-cols-3">
                <li v-for="s in steps" :key="s.n" class="flex min-h-[150px] flex-col gap-2 rounded-[18px] bg-white p-6">
                    <span class="font-display text-sm font-bold text-[#1d6c56]">Step {{ s.n }}</span>
                    <h3 class="font-display text-[21px] leading-[1.15] font-bold">{{ s.title }}</h3>
                    <p class="text-[15px] leading-[1.45] text-[#53645c]">{{ s.text }}</p>
                </li>
            </ol>
        </section>

        <footer class="mx-auto max-w-[1440px] px-6 pb-10 text-[13px] text-[#65766d] lg:px-[82px]">© 2026 MtaaFlow AI · Built to simplify business.</footer>
    </div>
</template>
