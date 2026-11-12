<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import { translate as t } from '@/lib/i18n';
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, FileText, Search } from '@lucide/vue';
import { create, show } from '@/routes/invoices';
import RemindersTab from '@/components/invoices/RemindersTab.vue';
import { balance, money, totals, type InvoiceRecord } from '@/types/business';
const props = defineProps<{ invoices: InvoiceRecord[] }>();
const tab = ref('invoices');
const search = ref('');
const status = ref('all');
const visible = computed(() =>
    props.invoices.filter(
        (i) =>
            (status.value === 'all' || i.status === status.value) &&
            `${i.invoice_number} ${i.customer?.name ?? ''}`
                .toLowerCase()
                .includes(search.value.toLowerCase()),
    ),
);
const billed = computed(() => totals(props.invoices));
</script>
<template>
    <div class="mf-page flex flex-col gap-7">
        <Head :title="t('nav.invoices')" />
        <header v-reveal>
            <div>
                <p class="mf-kicker mb-2">
                    {{ t('interface.your_work_beautifully_billed') }}
                </p>
                <h1
                    class="font-display text-3xl font-semibold tracking-tight text-[#123d32] sm:text-4xl dark:text-foreground"
                >
                    {{ t('nav.invoices') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'interface.create_share_and_follow_every_invoice_from_one_place',
                        )
                    }}
                </p>
            </div>
        </header>
        <div
            v-reveal
            class="mf-glass grid gap-5 rounded-3xl p-6 sm:grid-cols-[1fr_auto] sm:items-center"
        >
            <div class="flex items-center gap-4">
                <span
                    class="grid size-12 shrink-0 place-items-center rounded-2xl bg-[#e5f4ed] text-[#15966b] dark:bg-primary/15"
                    ><FileText class="size-5" aria-hidden="true"
                /></span>
                <div>
                    <p
                        class="font-display text-2xl font-semibold text-[#123d32] dark:text-foreground"
                    >
                        {{ invoices.length }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('interface.invoices_in_your_account') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-x-8 gap-y-3">
                <div v-for="row in billed" :key="row.currency">
                    <p
                        class="font-display text-xl font-semibold text-[#123d32] dark:text-foreground"
                    >
                        {{ money(row.total, row.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('interface.total_invoiced_including_drafts') }}
                    </p>
                </div>
                <p v-if="!billed.length" class="text-sm text-muted-foreground">
                    {{ t('interface.your_first_invoice_starts_here') }}
                </p>
            </div>
        </div>
        <nav
            class="flex w-fit gap-1 rounded-2xl border border-border bg-white/65 p-1.5 dark:bg-card"
            :aria-label="t('interface.invoice_workspace')"
        >
            <button
                class="rounded-xl px-4 py-2.5 text-sm font-medium transition"
                :class="
                    tab === 'invoices'
                        ? 'bg-[#123d32] text-white shadow-sm'
                        : 'text-muted-foreground hover:bg-[#e5f4ed] dark:hover:bg-primary/15'
                "
                :aria-pressed="tab === 'invoices'"
                @click="tab = 'invoices'"
            >
                {{ t('nav.invoices') }}</button
            ><button
                class="rounded-xl px-4 py-2.5 text-sm font-medium transition"
                :class="
                    tab === 'reminders'
                        ? 'bg-[#123d32] text-white shadow-sm'
                        : 'text-muted-foreground hover:bg-[#e5f4ed] dark:hover:bg-primary/15'
                "
                :aria-pressed="tab === 'reminders'"
                @click="tab = 'reminders'"
            >
                {{ t('reminders.title') }}
            </button>
        </nav>
        <RemindersTab v-if="tab === 'reminders'" :invoices="invoices" />
        <section v-else class="mf-card overflow-hidden">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-5 sm:p-6"
            >
                <label class="relative min-w-0 flex-1 sm:max-w-sm"
                    ><Search
                        class="pointer-events-none absolute top-3 left-3.5 size-4 text-muted-foreground"
                        aria-hidden="true" />
                    <input
                        v-model="search"
                        :aria-label="t('interface.search_invoices')"
                        :placeholder="
                            t('interface.search_invoices_or_customers')
                        "
                        class="h-11 w-full rounded-xl border border-border bg-background/60 pr-3 pl-10 text-sm transition outline-none focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/15" /></label
                ><select
                    v-model="status"
                    :aria-label="t('interface.invoice_status')"
                    class="h-11 rounded-xl border border-border bg-background/60 px-3 text-sm outline-none focus:border-[#15966b]"
                >
                    <option value="all">
                        {{ t('interface.all_statuses') }}
                    </option>
                    <option
                        v-for="state in ['draft', 'approved', 'paid']"
                        :key="state"
                        :value="state"
                    >
                        {{ statusLabel(state) }}
                    </option>
                </select>
            </div>
            <div
                v-if="!visible.length"
                class="flex flex-col items-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="grid size-16 place-items-center rounded-2xl bg-[#e5f4ed] text-[#15966b] dark:bg-primary/15"
                    ><FileText class="size-7" aria-hidden="true"
                /></span>
                <h2 class="font-display text-xl font-semibold">
                    {{
                        invoices.length
                            ? t('interface.no_matching_invoices')
                            : t('interface.make_your_next_project_official')
                    }}
                </h2>
                <p class="max-w-sm text-sm leading-6 text-muted-foreground">
                    {{
                        invoices.length
                            ? t(
                                  'interface.try_a_different_search_or_status_to_find_your_invoice',
                              )
                            : t(
                                  'interface.create_your_first_invoice_manually_or_let_ai_help_with_a_draft_you_stay_in_control',
                              )
                    }}
                </p>
                <Link
                    v-if="!invoices.length"
                    :href="create({ query: { mode: 'manual' } })"
                    class="mf-button mt-2"
                    >{{ t('interface.create_an_invoice')
                    }}<ArrowUpRight class="size-4"
                /></Link>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead
                        class="bg-[#f3f7f2] text-xs tracking-wide text-[#63776e] dark:bg-muted"
                    >
                        <tr>
                            <th scope="col" class="px-6 py-4 font-medium">
                                {{ t('interface.invoice') }}
                            </th>
                            <th scope="col" class="px-3 py-4 font-medium">
                                {{ t('invoices.customer') }}
                            </th>
                            <th scope="col" class="px-3 py-4 font-medium">
                                {{ t('common.total') }}
                            </th>
                            <th scope="col" class="px-3 py-4 font-medium">
                                {{ t('invoices.outstanding') }}
                            </th>
                            <th scope="col" class="px-6 py-4 font-medium">
                                {{ t('common.status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="inv in visible"
                            :key="inv.id"
                            class="border-t border-border transition-colors hover:bg-[#f6faf6] dark:hover:bg-muted/50"
                        >
                            <td class="px-6 py-5">
                                <Link
                                    :href="show(inv.id)"
                                    class="inline-flex items-center gap-2 font-semibold text-[#0f7d58] hover:underline dark:text-primary"
                                    >{{ inv.invoice_number }}</Link
                                >
                            </td>
                            <td class="px-3 py-5">{{ inv.customer?.name }}</td>
                            <td class="px-3 py-5 font-medium tabular-nums">
                                {{ money(inv.total, inv.currency) }}
                            </td>
                            <td class="px-3 py-5 tabular-nums">
                                {{
                                    inv.status === 'draft'
                                        ? t('interface.not_approved')
                                        : money(balance(inv), inv.currency)
                                }}
                            </td>
                            <td class="px-6 py-5">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                                    :class="
                                        inv.status === 'paid'
                                            ? 'bg-[#e5f4ed] text-[#0f7d58] dark:bg-primary/15 dark:text-primary'
                                            : inv.status === 'approved'
                                              ? 'bg-[#fff3d7] text-[#805c1d] dark:bg-amber-500/15 dark:text-amber-200'
                                              : 'bg-muted text-muted-foreground'
                                    "
                                    ><span
                                        class="size-1.5 rounded-full bg-current"
                                    />{{ statusLabel(inv.status) }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
