<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { Printer, X } from '@lucide/vue';

const preparing = ref(false);
const busy = ref(false);
const feedback = ref('');
const cancelButton = ref<HTMLButtonElement>();
let animationTimer: ReturnType<typeof setTimeout> | undefined;
let watchdog: ReturnType<typeof setTimeout> | undefined;
let attempt = 0;
let previousFocus: HTMLElement | null = null;

function clearTimers() {
    clearTimeout(animationTimer);
    clearTimeout(watchdog);
    window.removeEventListener('keydown', onKeydown);
}

function reset() {
    clearTimers();
    preparing.value = false;
    busy.value = false;
    previousFocus?.focus();
}

function cancel() {
    attempt += 1;
    reset();
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
    }
    if (event.key === 'Tab' && preparing.value) {
        event.preventDefault();
        cancelButton.value?.focus();
    }
}

async function openPrintDialog(currentAttempt: number) {
    if (!busy.value || currentAttempt !== attempt) return;
    clearTimers();
    preparing.value = false;
    await nextTick();
    if (!busy.value || currentAttempt !== attempt) return;

    try {
        if (typeof window.print !== 'function') {
            throw new Error(t('interface.browser_printing_is_unavailable'));
        }
        window.print();
    } catch {
        feedback.value = t('invoices.printFallback');
    } finally {
        reset();
    }
}

async function printInvoice() {
    if (busy.value) return;
    busy.value = true;
    feedback.value = '';
    previousFocus = document.activeElement as HTMLElement | null;
    const currentAttempt = ++attempt;

    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        await openPrintDialog(currentAttempt);
        return;
    }

    preparing.value = true;
    window.addEventListener('keydown', onKeydown);
    animationTimer = setTimeout(
        () => void openPrintDialog(currentAttempt),
        1100,
    );
    watchdog = setTimeout(() => void openPrintDialog(currentAttempt), 1500);
    await nextTick();
    cancelButton.value?.focus();
}

onBeforeUnmount(() => {
    attempt += 1;
    clearTimers();
});
</script>

<template>
    <div class="invoice-print-action" data-print-hide>
        <button
            type="button"
            class="mf-button-secondary"
            :disabled="busy"
            @click="printInvoice"
        >
            <Printer class="size-4" aria-hidden="true" />
            {{
                busy
                    ? t('interface.preparing_invoice')
                    : t('interface.print_invoice')
            }}
        </button>
        <p
            v-if="feedback"
            class="mt-2 max-w-sm text-xs text-muted-foreground"
            role="status"
        >
            {{ feedback }}
        </p>
        <Teleport to="body">
            <div
                v-if="preparing"
                class="invoice-printer-overlay"
                data-print-hide
            >
                <div
                    class="invoice-printer-dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="invoice-print-title"
                    aria-describedby="invoice-print-description"
                >
                    <button
                        ref="cancelButton"
                        type="button"
                        class="absolute top-4 right-4 grid size-10 place-items-center rounded-full text-[#63776e] transition hover:bg-[#e5f4ed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#15966b]"
                        :aria-label="t('interface.cancel_printing')"
                        @click="cancel"
                    >
                        <X class="size-5" />
                    </button>
                    <p class="mf-kicker">
                        {{ t('interface.ready_for_the_real_world') }}
                    </p>
                    <svg
                        class="invoice-printer"
                        viewBox="0 0 360 320"
                        fill="none"
                        aria-hidden="true"
                    >
                        <defs>
                            <linearGradient
                                id="mf-printer-body"
                                x1="76"
                                y1="110"
                                x2="280"
                                y2="240"
                                gradientUnits="userSpaceOnUse"
                            >
                                <stop stop-color="#286b54" />
                                <stop offset="1" stop-color="#123d32" />
                            </linearGradient>
                            <linearGradient
                                id="mf-printer-top"
                                x1="90"
                                y1="96"
                                x2="270"
                                y2="160"
                                gradientUnits="userSpaceOnUse"
                            >
                                <stop stop-color="#448e72" />
                                <stop offset="1" stop-color="#1d5c47" />
                            </linearGradient>
                            <filter
                                id="mf-paper-shadow"
                                x="70"
                                y="160"
                                width="230"
                                height="165"
                                filterUnits="userSpaceOnUse"
                            >
                                <feDropShadow
                                    dx="0"
                                    dy="8"
                                    stdDeviation="6"
                                    flood-color="#102e24"
                                    flood-opacity="0.18"
                                />
                            </filter>
                        </defs>
                        <ellipse
                            cx="180"
                            cy="284"
                            rx="117"
                            ry="15"
                            fill="#123d32"
                            opacity="0.08"
                        />
                        <path
                            d="M101 89h158l23 44H78l23-44Z"
                            fill="url(#mf-printer-top)"
                        />
                        <rect
                            x="108"
                            y="64"
                            width="144"
                            height="57"
                            rx="7"
                            fill="#dcebe3"
                        />
                        <path
                            d="M122 80h63m-63 12h109"
                            stroke="#a5bfb2"
                            stroke-width="4"
                            stroke-linecap="round"
                        />
                        <rect
                            x="61"
                            y="118"
                            width="238"
                            height="113"
                            rx="22"
                            fill="url(#mf-printer-body)"
                        />
                        <path
                            d="M76 132h208"
                            stroke="white"
                            stroke-opacity="0.15"
                            stroke-width="2"
                        />
                        <rect
                            x="80"
                            y="148"
                            width="200"
                            height="16"
                            rx="8"
                            fill="#0a2a21"
                        />
                        <rect
                            x="92"
                            y="151"
                            width="176"
                            height="5"
                            rx="2.5"
                            fill="#051e17"
                        />
                        <g
                            class="invoice-printer-paper"
                            filter="url(#mf-paper-shadow)"
                        >
                            <path
                                d="M105 161h150v108a7 7 0 0 1-7 7H112a7 7 0 0 1-7-7V161Z"
                                fill="#fffefa"
                            />
                            <rect
                                x="119"
                                y="177"
                                width="37"
                                height="5"
                                rx="2.5"
                                fill="#15966b"
                            />
                            <rect
                                x="119"
                                y="190"
                                width="75"
                                height="3"
                                rx="1.5"
                                fill="#aabbb1"
                            />
                            <path
                                d="M119 207h122m-122 12h122m-122 12h122"
                                stroke="#e6ece6"
                                stroke-width="2"
                            />
                            <rect
                                x="119"
                                y="202"
                                width="48"
                                height="3"
                                rx="1.5"
                                fill="#7c9688"
                            />
                            <rect
                                x="220"
                                y="202"
                                width="21"
                                height="3"
                                rx="1.5"
                                fill="#7c9688"
                            />
                            <path
                                d="M197 247h44"
                                stroke="#15966b"
                                stroke-width="5"
                                stroke-linecap="round"
                            />
                            <circle cx="125" cy="252" r="7" fill="#e5f4ed" />
                            <path
                                d="m122 252 2 2 4-4"
                                stroke="#15966b"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </g>
                        <path
                            d="M80 164h200v23H80z"
                            fill="url(#mf-printer-body)"
                        />
                        <rect
                            x="91"
                            y="170"
                            width="178"
                            height="5"
                            rx="2.5"
                            fill="#0a2a21"
                        />
                        <circle
                            cx="271"
                            cy="136"
                            r="4"
                            fill="#8aefb9"
                            class="invoice-printer-light"
                        />
                        <path
                            d="M83 211h33"
                            stroke="white"
                            stroke-opacity="0.13"
                            stroke-width="3"
                            stroke-linecap="round"
                        />
                    </svg>
                    <h2
                        id="invoice-print-title"
                        class="font-display text-2xl font-semibold text-[#123d32]"
                    >
                        {{ t('interface.a_little_paper_magic') }}
                    </h2>
                    <p
                        id="invoice-print-description"
                        class="mt-2 text-sm leading-6 text-[#63776e]"
                    >
                        {{ t('interface.preparing_your_complete_invoice')
                        }}<br />{{
                            t(
                                'interface.your_browser_s_print_options_will_open_next',
                            )
                        }}
                    </p>
                    <p class="mt-5 text-xs text-[#63776e]">
                        {{
                            t('interface.print_on_paper_or_choose_save_as_pdf')
                        }}
                    </p>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.invoice-printer-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 1.25rem;
    background: rgb(10 37 28 / 38%);
    backdrop-filter: blur(9px);
}
.invoice-printer-dialog {
    position: relative;
    width: min(100%, 27rem);
    max-height: calc(100dvh - 2.5rem);
    overflow-y: auto;
    padding: 2.5rem 1.5rem 2rem;
    border: 1px solid rgb(255 255 255 / 80%);
    border-radius: 2rem;
    background: #fafcf8;
    text-align: center;
    box-shadow: 0 30px 90px rgb(5 27 19 / 20%);
}
.invoice-printer {
    display: block;
    width: min(100%, 21rem);
    margin: -0.5rem auto -1rem;
}
.invoice-printer-paper {
    animation: invoice-paper-feed 1050ms cubic-bezier(0.2, 0.7, 0.25, 1) both;
    transform-origin: center;
}
.invoice-printer-light {
    animation: invoice-printer-status 400ms ease-in-out 2 alternate;
}
@keyframes invoice-paper-feed {
    from {
        transform: translateY(-76px);
        opacity: 0;
    }
    22% {
        opacity: 1;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
@keyframes invoice-printer-status {
    to {
        opacity: 0.35;
    }
}
@media (prefers-reduced-motion: reduce) {
    .invoice-printer-paper,
    .invoice-printer-light {
        animation: none;
    }
}
@media print {
    .invoice-print-action,
    .invoice-printer-overlay {
        display: none !important;
    }
}
</style>
