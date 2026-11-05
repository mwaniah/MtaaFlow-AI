<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import InvoiceStatusBadge from '@/components/InvoiceStatusBadge.vue'
import { formatMoney } from '@/lib/money'
import { sampleInvoices } from '@/mock/invoices'
import type { Invoice, InvoiceActivity, InvoiceBusiness, InvoiceCustomer } from '@/types/invoice'

const props = defineProps<{
  invoice?: Invoice
  business?: InvoiceBusiness
  customer?: InvoiceCustomer
  payment_url?: string
  activity?: InvoiceActivity[]
}>()

// Fallbacks until the backend sends real props
const invoice = computed<Invoice>(() => props.invoice ?? sampleInvoices[0])
const business = computed<InvoiceBusiness>(
  () => props.business ?? { name: 'Mwangi Traders', address: 'Kasarani Road, Nairobi', phone: '0700 111 222' },
)
const customer = computed<InvoiceCustomer>(
  () => props.customer ?? { name: invoice.value.customer_name, phone: '0712 345 678', email: 'brian.kamau@gmail.com' },
)
const paymentUrl = computed(() => props.payment_url ?? `pay.mtaaflow.app/${invoice.value.number.toLowerCase()}`)
const activity = computed<InvoiceActivity[]>(
  () =>
    props.activity ?? [
      { label: 'Invoice approved', time: '30 Oct, 4:12 pm', done: true },
      { label: 'Checkout link created', time: '30 Oct, 4:12 pm', done: true },
      { label: `Link shared with ${customer.value.name.split(' ')[0]}`, time: '30 Oct, 4:20 pm', done: true },
      { label: 'Waiting for payment', time: 'Now', done: false },
    ],
)

const items = computed(() => invoice.value.items ?? [])
const subtotal = computed(() => items.value.reduce((s, i) => s + i.quantity * i.unit_price, 0) || invoice.value.amount)

const fullDate = (iso: string) =>
  new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const shortDate = (iso: string) =>
  new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })

const paymentNote = computed(() => {
  const inv = invoice.value
  if (inv.status === 'paid') return 'Paid in full'
  if (inv.status === 'cancelled') return 'Invoice cancelled'
  if (inv.status === 'draft') return 'Draft · not sent yet'
  const days = Math.round((new Date(inv.due_date + 'T00:00:00').getTime() - new Date().setHours(0, 0, 0, 0)) / 86400000)
  if (days < 0) return `Awaiting payment · ${Math.abs(days)} days overdue`
  return `Awaiting payment · due in ${days} days`
})

const print = () => window.print()

// Share modal
const showShare = ref(false)
const copied = ref<'link' | 'message' | null>(null)
const message = ref('')
const openShare = () => {
  const inv = invoice.value
  message.value = `Hi ${customer.value.name.split(' ')[0]}, here is your invoice ${inv.number} for ${formatMoney(inv.amount)}, due ${shortDate(inv.due_date)}. You can pay securely here: ${paymentUrl.value}`
  showShare.value = true
}
const copy = async (text: string, what: 'link' | 'message') => {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = what
    setTimeout(() => (copied.value = null), 1500)
  } catch {
    /* clipboard unavailable */
  }
}
</script>

<template>
  <Head :title="invoice.number" />
  <div class="px-6 py-6">
    <!-- Header -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-3">
          <h1 class="text-[26px] font-bold leading-8 text-brand-forest">{{ invoice.number }}</h1>
          <InvoiceStatusBadge :status="invoice.status" />
        </div>
        <p class="mt-1 text-sm text-brand-forest/60">
          {{ customer.name }} · Issued {{ fullDate(invoice.issue_date) }} · Due {{ fullDate(invoice.due_date) }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <!-- TODO: wire to a PDF download route when the backend has one -->
        <button type="button" class="rounded-xl border-[1.5px] border-brand-forest px-4 py-2.5 text-sm font-semibold text-brand-forest">
          Download PDF
        </button>
        <button type="button" class="rounded-xl border-[1.5px] border-brand-forest px-4 py-2.5 text-sm font-semibold text-brand-forest" @click="print">
          Print
        </button>
        <button type="button" class="rounded-xl bg-[#16996B] px-4 py-2.5 text-sm font-semibold text-white" @click="openShare">
          Share invoice
        </button>
      </div>
    </div>

    <div class="mt-6 grid items-start gap-4 lg:grid-cols-[1fr_428px]">
      <!-- Invoice document -->
      <section class="rounded-2xl border border-line bg-paper p-8 text-brand-forest">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xl font-bold text-[#0F3D2E]">{{ business.name }}</p>
            <p class="mt-0.5 text-xs text-brand-forest/60">
              {{ [business.address, business.phone].filter(Boolean).join(' · ') }}
            </p>
          </div>
          <div class="text-right">
            <p class="text-xs text-brand-forest/60">INVOICE</p>
            <p class="text-xl font-bold">{{ invoice.number }}</p>
          </div>
        </div>

        <div class="mt-6">
          <p class="text-xs text-brand-forest/60">Bill to</p>
          <p class="mt-0.5 text-sm font-semibold">{{ customer.name }}</p>
          <p class="mt-0.5 text-xs text-brand-forest/60">
            {{ [customer.phone, customer.email].filter(Boolean).join(' · ') }}
          </p>
        </div>

        <table class="mt-7 w-full text-sm">
          <thead>
            <tr class="border-b border-line text-xs text-brand-forest/60">
              <th class="py-2 text-left font-normal">Item</th>
              <th class="w-16 py-2 text-right font-normal">Qty</th>
              <th class="w-28 py-2 text-right font-normal">Price</th>
              <th class="w-32 py-2 text-right font-normal">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, i) in items" :key="i" class="border-b border-line">
              <td class="py-3">{{ item.description }}</td>
              <td class="py-3 text-right">{{ item.quantity }}</td>
              <td class="py-3 text-right">{{ formatMoney(item.unit_price) }}</td>
              <td class="py-3 text-right font-bold">{{ formatMoney(item.quantity * item.unit_price) }}</td>
            </tr>
            <tr v-if="items.length === 0">
              <td colspan="4" class="py-6 text-center text-brand-forest/60">No line items.</td>
            </tr>
          </tbody>
        </table>

        <div class="ml-auto mt-5 w-64 space-y-1.5 text-sm">
          <div class="flex justify-between"><span class="text-xs text-brand-forest/60">Subtotal</span><span>{{ formatMoney(subtotal) }}</span></div>
          <div class="flex justify-between"><span class="text-xs text-brand-forest/60">VAT 0%</span><span>{{ formatMoney(0) }}</span></div>
          <div class="flex items-baseline justify-between pt-1">
            <span class="text-lg font-bold">Total due</span>
            <span class="text-lg font-bold">{{ formatMoney(invoice.amount) }}</span>
          </div>
        </div>
      </section>

      <!-- Right column -->
      <div class="space-y-4">
        <section class="rounded-2xl border border-line bg-paper p-5 text-brand-forest">
          <h2 class="text-lg font-bold">Payment</h2>
          <p class="mt-3 text-[32px] font-bold leading-10">{{ formatMoney(invoice.amount) }}</p>
          <p class="mt-1 text-xs text-brand-forest/60">{{ paymentNote }}</p>

          <div class="mt-4 rounded-xl bg-[#E6F4EC] p-3.5">
            <div class="flex items-center gap-2 text-xs text-brand-forest/60">
              <span>Checkout link</span>
              <span class="rounded bg-white px-2 py-0.5">PayPal</span>
            </div>
            <p class="mt-2 text-sm font-semibold">{{ paymentUrl }}</p>
          </div>

          <!-- TODO: point at the real PayPal checkout URL from the backend -->
          <a
            :href="paymentUrl.startsWith('http') ? paymentUrl : '#'"
            target="_blank"
            rel="noopener"
            class="mt-4 inline-block rounded-xl bg-[#16996B] px-4 py-2.5 text-sm font-semibold text-white"
          >
            Open PayPal checkout
          </a>
        </section>

        <section class="rounded-2xl border border-line bg-paper p-5 text-brand-forest">
          <h2 class="text-lg font-bold">Activity</h2>
          <ul class="mt-4 space-y-3">
            <li v-for="(a, i) in activity" :key="i" class="flex items-center gap-3">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="a.done ? 'bg-[#16996B]' : 'bg-[#E3E3DC]'" />
              <div>
                <p class="text-sm leading-5">{{ a.label }}</p>
                <p class="text-xs text-brand-forest/60">{{ a.time }}</p>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </div>

    <Link href="/invoices" class="mt-6 inline-block text-sm text-brand-forest/60 hover:text-brand-forest">← Back to invoices</Link>

    <!-- Share modal -->
    <div v-if="showShare" class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-28" @click.self="showShare = false">
      <div class="w-full max-w-[520px] rounded-2xl bg-paper p-6 text-brand-forest shadow-xl">
        <h2 class="text-lg font-bold">Share invoice</h2>
        <p class="mt-3 text-sm text-brand-forest/60">
          Send {{ customer.name.split(' ')[0] }} the invoice with a secure PayPal checkout link.
        </p>

        <label class="mt-5 block text-xs text-brand-forest/60">Payment link</label>
        <div class="mt-1.5 rounded-lg border border-line px-3.5 py-2.5 text-sm">{{ paymentUrl }}</div>

        <label class="mt-4 block text-xs text-brand-forest/60">Message</label>
        <textarea v-model="message" rows="3" class="mt-1.5 w-full rounded-lg border border-line bg-paper px-3.5 py-2.5 text-sm focus:outline-none" />

        <div class="mt-5 flex gap-3">
          <button type="button" class="rounded-xl bg-[#16996B] px-4 py-2.5 text-sm font-semibold text-white" @click="copy(message, 'message')">
            {{ copied === 'message' ? 'Copied' : 'Copy message' }}
          </button>
          <button type="button" class="rounded-xl border border-line px-4 py-2.5 text-sm font-semibold" @click="copy(paymentUrl, 'link')">
            {{ copied === 'link' ? 'Copied' : 'Copy link' }}
          </button>
          <button type="button" class="ml-auto rounded-xl px-4 py-2.5 text-sm font-semibold text-brand-forest/60" @click="showShare = false">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
