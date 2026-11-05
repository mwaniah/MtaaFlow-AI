<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import InvoiceStatusBadge from '@/components/InvoiceStatusBadge.vue'
import { formatMoney } from '@/lib/money'
import { sampleInvoices } from '@/mock/invoices'
import RemindersTab from '@/components/invoices/RemindersTab.vue'
import { sampleReminders, sampleReminderSummary, type Reminder, type ReminderSummary } from '@/mock/reminders'
import { invoiceStatusLabels, outstandingStatuses, type Invoice, type InvoiceStatus } from '@/types/invoice'

const props = defineProps<{ invoices?: Invoice[]; reminderCount?: number; reminders?: Reminder[]; reminderSummary?: ReminderSummary }>()
const invoices = computed<Invoice[]>(() => props.invoices ?? sampleInvoices)

type Filter = 'all' | InvoiceStatus
const filterOrder: InvoiceStatus[] = ['draft', 'pending', 'partially_paid', 'paid', 'overdue', 'cancelled']

const activeTab = ref<'invoices' | 'reminders'>('invoices')
const filter = ref<Filter>('all')
const query = ref('')

const counts = computed(() => {
  const c: Record<string, number> = { all: invoices.value.length }
  for (const s of filterOrder) c[s] = invoices.value.filter((i) => i.status === s).length
  return c
})

const outstanding = computed(() =>
  invoices.value.filter((i) => outstandingStatuses.includes(i.status)).reduce((sum, i) => sum + (i.amount - i.paid), 0),
)

const reminderList = computed<Reminder[]>(() => props.reminders ?? sampleReminders)
const reminderSummary = computed<ReminderSummary>(() => props.reminderSummary ?? sampleReminderSummary)
const reminderTotal = computed(
  () => props.reminderCount ?? reminderList.value.filter((r) => r.group !== 'sent').length,
)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return invoices.value.filter((i) => {
    if (filter.value !== 'all' && i.status !== filter.value) return false
    if (!q) return true
    return i.number.toLowerCase().includes(q) || i.customer_name.toLowerCase().includes(q)
  })
})

const formatDate = (iso: string) =>
  new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
</script>

<template>
  <Head title="Invoices" />
  <div class="px-6 py-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-bold leading-8 text-brand-forest">Invoices</h1>
        <p v-if="activeTab === 'invoices'" class="mt-1 text-sm text-brand-forest/60">
          {{ invoices.length }} invoices this month · {{ formatMoney(outstanding) }} outstanding
        </p>
        <p v-else class="mt-1 text-sm text-brand-forest/60">
          {{ formatMoney(outstanding) }} outstanding · {{ reminderSummary.sent_this_week.count }} reminders sent this week
        </p>
      </div>
      <button v-if="activeTab === 'invoices'" type="button" class="rounded-xl bg-[#16996B] px-4 py-2.5 text-sm font-semibold text-white">
        Create invoice
      </button>
      <button v-else type="button" class="rounded-xl border-[1.5px] border-brand-forest px-4 py-2.5 text-sm font-semibold text-brand-forest">
        Reminder settings
      </button>
    </div>

    <div class="mt-5 inline-flex rounded-xl border border-line bg-paper p-[5px]">
      <button type="button" class="rounded-lg px-4 py-1.5 text-sm font-medium"
        :class="activeTab === 'invoices' ? 'bg-brand-forest text-white' : 'text-brand-forest'"
        @click="activeTab = 'invoices'">Invoices</button>
      <button type="button" class="rounded-lg px-4 py-1.5 text-sm font-medium"
        :class="activeTab === 'reminders' ? 'bg-brand-forest text-white' : 'text-brand-forest'"
        @click="activeTab = 'reminders'">Reminders {{ reminderTotal }}</button>
    </div>

    <section v-if="activeTab === 'invoices'" class="mt-5 rounded-2xl border border-line bg-paper p-5">
      <div class="flex flex-wrap items-center gap-2">
        <input v-model="query" type="search" placeholder="Search by number or customer"
          class="mr-1 h-[38px] w-[260px] rounded-lg border border-line bg-paper px-3.5 text-sm text-brand-forest focus:outline-none" />
        <button type="button" class="rounded-full border px-3 py-1.5 text-sm"
          :class="filter === 'all' ? 'border-brand-forest bg-brand-forest font-medium text-white' : 'border-line text-brand-forest'"
          @click="filter = 'all'">All {{ counts.all }}</button>
        <button v-for="s in filterOrder" :key="s" type="button" class="rounded-full border px-3 py-1.5 text-sm"
          :class="filter === s ? 'border-brand-forest bg-brand-forest font-medium text-white' : 'border-line text-brand-forest'"
          @click="filter = s">{{ invoiceStatusLabels[s] }} {{ counts[s] }}</button>
      </div>

      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm text-brand-forest">
          <thead>
            <tr class="border-b border-line text-left text-xs text-brand-forest/60">
              <th class="py-2 pr-3 font-normal">Invoice</th>
              <th class="py-2 pr-3 font-normal">Customer</th>
              <th class="py-2 pr-3 font-normal">Issued</th>
              <th class="py-2 pr-3 font-normal">Due</th>
              <th class="py-2 pr-3 text-right font-normal">Amount</th>
              <th class="py-2 pr-3 text-right font-normal">Paid</th>
              <th class="py-2 font-normal">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in filtered" :key="inv.id" class="border-b border-line last:border-0">
              <td class="py-3.5 pr-3"><Link :href="`/invoices/${inv.id}`" class="hover:underline">{{ inv.number }}</Link></td>
              <td class="py-3.5 pr-3 font-semibold">{{ inv.customer_name }}</td>
              <td class="py-3.5 pr-3">{{ formatDate(inv.issue_date) }}</td>
              <td class="py-3.5 pr-3">{{ formatDate(inv.due_date) }}</td>
              <td class="py-3.5 pr-3 text-right" :class="inv.status === 'cancelled' ? 'text-brand-forest/50' : 'font-bold'">
                {{ formatMoney(inv.amount) }}
              </td>
              <td class="py-3.5 pr-3 text-right">{{ formatMoney(inv.paid) }}</td>
              <td class="py-3.5"><InvoiceStatusBadge :status="inv.status" /></td>
            </tr>
            <tr v-if="filtered.length === 0">
              <td colspan="7" class="py-10 text-center text-brand-forest/60">No invoices match your search.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-else class="mt-5">
      <RemindersTab :reminders="reminderList" :summary="reminderSummary" />
    </section>
  </div>
</template>
