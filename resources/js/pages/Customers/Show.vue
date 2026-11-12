<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Mail, MapPin, Phone, Trash2, UserRound } from '@lucide/vue';
import CustomerEditor from '@/components/CustomerEditor.vue';
import { index, destroy } from '@/routes/customers';
import type { CustomerRecord } from '@/types/business';

const props = defineProps<{ customer: CustomerRecord }>();
const page = usePage();
function remove() {
    if (window.confirm(t('customers.deletePrompt')))
        router.delete(destroy.url(props.customer.id));
}
</script>

<template>
    <div class="mf-page flex flex-col gap-7">
        <Head :title="customer.name" />
        <header v-reveal>
            <Link
                :href="index()"
                class="mb-5 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-emerald-dark hover:underline dark:text-[#8bdbb4]"
                ><ArrowLeft class="size-3.5" aria-hidden="true" />{{
                    t('interface.back_to_customers')
                }}</Link
            >
            <p class="mf-kicker mb-2">{{ t('interface.customer_details') }}</p>
            <h1
                class="font-display text-3xl font-bold tracking-tight break-words text-brand-forest sm:text-4xl dark:text-foreground"
            >
                {{ customer.name }}
            </h1>
            <p
                class="mt-2 text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
            >
                {{
                    t(
                        'interface.keep_the_right_details_close_for_your_next_project_together',
                    )
                }}
            </p>
        </header>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
            <div v-reveal class="min-w-0">
                <CustomerEditor :customer="customer" />
            </div>
            <aside
                v-reveal
                class="mf-glass min-w-0 p-6"
                :aria-label="t('interface.customer_contact_information')"
            >
                <span
                    class="mb-5 flex size-14 items-center justify-center rounded-2xl border border-brand-emerald/10 bg-brand-mint font-display text-2xl font-semibold text-brand-forest dark:bg-secondary dark:text-foreground"
                    aria-hidden="true"
                    >{{ customer.name.slice(0, 1).toUpperCase() }}</span
                >
                <h2
                    class="font-display text-xl font-bold tracking-tight break-words text-brand-forest dark:text-foreground"
                >
                    {{ customer.name }}
                </h2>
                <p
                    class="mt-1 text-xs text-ink-muted dark:text-muted-foreground"
                >
                    {{ t('interface.customer_contact') }}
                </p>
                <dl class="mt-6 flex flex-col gap-5">
                    <div>
                        <dt
                            class="mb-1.5 flex items-center gap-2 text-xs font-medium text-ink-muted dark:text-muted-foreground"
                        >
                            <Mail
                                class="size-3.5 text-brand-emerald-dark dark:text-[#8bdbb4]"
                                aria-hidden="true"
                            />{{ t('common.emailShort') }}
                        </dt>
                        <dd
                            class="text-sm break-all text-brand-forest dark:text-foreground"
                        >
                            {{
                                customer.email ||
                                t('interface.no_email_provided')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="mb-1.5 flex items-center gap-2 text-xs font-medium text-ink-muted dark:text-muted-foreground"
                        >
                            <Phone
                                class="size-3.5 text-brand-emerald-dark dark:text-[#8bdbb4]"
                                aria-hidden="true"
                            />{{ t('common.phone') }}
                        </dt>
                        <dd
                            class="text-sm break-words text-brand-forest dark:text-foreground"
                        >
                            {{
                                customer.phone ||
                                t('interface.no_phone_provided')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="mb-1.5 flex items-center gap-2 text-xs font-medium text-ink-muted dark:text-muted-foreground"
                        >
                            <MapPin
                                class="size-3.5 text-brand-emerald-dark dark:text-[#8bdbb4]"
                                aria-hidden="true"
                            />{{ t('common.address') }}
                        </dt>
                        <dd
                            class="text-sm leading-relaxed whitespace-pre-line text-brand-forest dark:text-foreground"
                        >
                            {{
                                customer.address ||
                                t('interface.no_address_provided')
                            }}
                        </dd>
                    </div>
                </dl>
                <p
                    class="mt-6 flex items-start gap-2 border-t border-brand-forest/10 pt-4 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    <UserRound
                        class="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />{{
                        t(
                            'interface.changes_to_contact_details_are_saved_when_you_select_save_customer',
                        )
                    }}
                </p>
            </aside>
        </div>

        <div
            v-if="Object.keys(page.props.errors).length"
            class="rounded-2xl border border-[#b4432f]/20 bg-[#fff4ef] px-5 py-4"
            role="alert"
        >
            <p
                v-for="(error, key) in page.props.errors"
                :key="key"
                class="text-sm leading-relaxed text-[#b4432f]"
            >
                {{ error }}
            </p>
        </div>

        <section
            v-reveal
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-line bg-white/70 px-5 py-5 sm:px-6 dark:border-border dark:bg-card/70"
        >
            <div>
                <h2
                    class="text-sm font-semibold text-brand-forest dark:text-foreground"
                >
                    {{ t('interface.remove_customer') }}
                </h2>
                <p
                    class="mt-1 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t('interface.customers_with_invoices_cannot_be_deleted')
                    }}
                </p>
            </div>
            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-[#b4432f]/25 bg-white px-4 py-2.5 text-sm font-semibold text-[#b4432f] transition-colors hover:bg-[#fff4ef] focus-visible:ring-3 focus-visible:ring-[#b4432f]/20 focus-visible:outline-none dark:bg-card"
                @click="remove"
            >
                <Trash2 class="size-4" aria-hidden="true" />{{
                    t('customers.delete')
                }}
            </button>
        </section>
    </div>
</template>
