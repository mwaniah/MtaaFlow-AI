<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, Mail, Phone, Plus, Search, Users, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AnimatedNumber from '@/components/AnimatedNumber.vue';
import CustomerEditor from '@/components/CustomerEditor.vue';
import { show } from '@/routes/customers';
import type { CustomerRecord } from '@/types/business';

const props = defineProps<{ customers: CustomerRecord[] }>();
const search = ref('');
const adding = ref(false);
const visible = computed(() =>
    props.customers.filter((customer) =>
        [customer.name, customer.email, customer.phone].some((value) =>
            value?.toLowerCase().includes(search.value.toLowerCase()),
        ),
    ),
);
</script>

<template>
    <div class="mf-page flex flex-col gap-7">
        <Head :title="t('nav.customers')" />
        <header v-reveal class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="mf-kicker mb-2">
                    {{ t('interface.good_work_starts_with_people') }}
                </p>
                <h1
                    class="font-display text-3xl font-bold tracking-tight text-brand-forest sm:text-4xl dark:text-foreground"
                >
                    {{ t('nav.customers') }}
                </h1>
                <p
                    class="mt-2 text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        t(
                            'interface.your_connections_organised_keep_the_details_that_make_working_together_easier',
                        )
                    }}
                </p>
            </div>
            <button
                type="button"
                class="mf-button"
                :aria-expanded="adding"
                aria-controls="customer-create-form"
                @click="adding = !adding"
            >
                <X v-if="adding" class="size-4" aria-hidden="true" /><Plus
                    v-else
                    class="size-4"
                    aria-hidden="true"
                />{{ adding ? t('customers.closeForm') : t('customers.add') }}
            </button>
        </header>

        <Transition name="customer-form">
            <div v-if="adding" id="customer-create-form">
                <CustomerEditor @saved="adding = false" />
            </div>
        </Transition>

        <section v-reveal class="mf-card min-w-0 overflow-hidden">
            <header
                class="flex flex-wrap items-center justify-between gap-4 border-b border-line/80 p-5 sm:p-6 dark:border-border/80"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                        ><Users
                            class="size-[18px]"
                            :stroke-width="1.5"
                            aria-hidden="true"
                    /></span>
                    <div>
                        <h2
                            class="text-sm font-semibold text-brand-forest dark:text-foreground"
                        >
                            {{ t('interface.your_customer_directory') }}
                        </h2>
                        <p
                            class="mt-1 text-xs text-ink-muted dark:text-muted-foreground"
                        >
                            <AnimatedNumber :value="customers.length" />{{
                                t('interface.customers_in_your_account')
                            }}
                        </p>
                    </div>
                </div>
                <div class="relative w-full sm:w-80">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted dark:text-muted-foreground"
                        aria-hidden="true"
                    /><input
                        v-model="search"
                        type="search"
                        :aria-label="t('interface.search_customers')"
                        :placeholder="t('interface.search_name_email_or_phone')"
                        class="min-h-11 w-full rounded-xl border border-line bg-canvas/70 py-2.5 pr-4 pl-10 text-sm placeholder:text-ink-muted/80 focus:border-brand-emerald focus:ring-3 focus:ring-brand-emerald/10 focus:outline-none dark:border-border dark:bg-background/40"
                    />
                </div>
            </header>

            <div
                v-if="!visible.length"
                class="flex flex-col items-center px-6 py-14 text-center"
            >
                <div
                    class="customer-empty-symbol mb-5 flex size-20 items-center justify-center rounded-[24px] bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                >
                    <Users
                        class="size-9"
                        :stroke-width="1.25"
                        aria-hidden="true"
                    />
                </div>
                <h3
                    class="font-display text-2xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                >
                    {{
                        customers.length
                            ? t('interface.no_matching_connections')
                            : t('interface.meet_your_next_great_collaboration')
                    }}
                </h3>
                <p
                    class="mt-2 max-w-sm text-sm leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        customers.length
                            ? t(
                                  'interface.try_a_different_name_email_or_phone_number_to_find_the_right_customer',
                              )
                            : t(
                                  'interface.add_your_first_customer_to_keep_their_contact_details_close_and_start_creating_invoices',
                              )
                    }}
                </p>
                <button
                    v-if="search"
                    type="button"
                    class="mf-button-secondary mt-6"
                    @click="search = ''"
                >
                    {{ t('interface.clear_search') }}
                </button>
                <button
                    v-else-if="!adding"
                    type="button"
                    class="mf-button mt-6"
                    @click="adding = true"
                >
                    <Plus class="size-4" aria-hidden="true" />{{
                        t('interface.add_your_first_customer')
                    }}
                </button>
            </div>
            <div v-else class="px-5 sm:px-6">
                <div
                    class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,.65fr)_24px] gap-5 border-b border-line/70 py-3.5 text-[11px] font-semibold tracking-wide text-ink-muted uppercase md:grid dark:border-border/70 dark:text-muted-foreground"
                >
                    <span>{{ t('invoices.customer') }}</span
                    ><span>{{ t('common.email') }}</span
                    ><span>{{ t('common.phone') }}</span
                    ><span class="sr-only">{{
                        t('interface.view_customer')
                    }}</span>
                </div>
                <ul class="divide-y divide-line/70">
                    <li
                        v-for="customer in visible"
                        :key="customer.id"
                        class="group grid items-center gap-x-5 gap-y-2 py-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,.65fr)_24px]"
                    >
                        <Link
                            :href="show(customer.id)"
                            class="flex min-w-0 items-center gap-3 text-sm font-semibold text-brand-forest hover:text-brand-emerald-dark dark:text-foreground dark:hover:text-[#a8e8c9]"
                            ><span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full border border-brand-emerald/10 bg-brand-mint font-display text-base font-semibold text-brand-forest dark:bg-secondary dark:text-foreground"
                                aria-hidden="true"
                                >{{
                                    customer.name.slice(0, 1).toUpperCase()
                                }}</span
                            ><span class="min-w-0 break-words">{{
                                customer.name
                            }}</span
                            ><ArrowUpRight
                                class="ml-auto size-4 shrink-0 text-brand-emerald-dark md:hidden dark:text-[#8bdbb4]"
                                aria-hidden="true"
                        /></Link>
                        <p
                            class="flex min-w-0 items-center gap-2 text-sm break-words text-ink-muted max-md:pl-[52px] dark:text-muted-foreground"
                        >
                            <Mail
                                class="size-3.5 shrink-0 text-brand-emerald-dark/65 md:hidden dark:text-[#8bdbb4]"
                                aria-hidden="true"
                            /><span class="min-w-0 break-all">{{
                                customer.email || t('interface.no_email')
                            }}</span>
                        </p>
                        <p
                            class="flex min-w-0 items-center gap-2 text-sm break-words text-ink-muted max-md:pl-[52px] dark:text-muted-foreground"
                        >
                            <Phone
                                class="size-3.5 shrink-0 text-brand-emerald-dark/65 md:hidden dark:text-[#8bdbb4]"
                                aria-hidden="true"
                            />{{ customer.phone || t('interface.no_phone') }}
                        </p>
                        <Link
                            :href="show(customer.id)"
                            class="hidden size-8 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-brand-mint hover:text-brand-emerald-dark md:flex dark:text-muted-foreground dark:hover:bg-secondary dark:hover:text-[#a8e8c9]"
                            :aria-label="
                                t('customers.viewName', { name: customer.name })
                            "
                            ><ArrowUpRight class="size-4" aria-hidden="true"
                        /></Link>
                    </li>
                </ul>
            </div>
            <footer
                v-if="visible.length"
                class="border-t border-line/80 bg-canvas/70 px-5 py-3.5 text-xs text-ink-muted sm:px-6 dark:border-border/80 dark:bg-background/40 dark:text-muted-foreground"
            >
                {{ visible.length }}{{ t('interface.of') }}{{ customers.length
                }}{{
                    t(
                        'interface.customers_select_a_customer_to_view_or_edit_their_details',
                    )
                }}
            </footer>
        </section>
    </div>
</template>

<style scoped>
.customer-empty-symbol {
    box-shadow: 0 0 0 10px rgb(229 244 237 / 35%);
}
@media (prefers-reduced-motion: no-preference) {
    .customer-form-enter-active,
    .customer-form-leave-active {
        transition:
            opacity 180ms ease,
            transform 180ms ease;
    }
    .customer-form-enter-from,
    .customer-form-leave-to {
        opacity: 0;
        transform: translateY(-8px);
    }
}
</style>
