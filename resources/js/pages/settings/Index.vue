<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { sampleSettings, type SettingsData } from '@/mock/settings'

const props = defineProps<{ settings?: SettingsData }>()
const data = computed<SettingsData>(() => props.settings ?? sampleSettings)

type Section = 'business' | 'account' | 'invoices'
const section = ref<Section>('business')
const menu: { key: Section; label: string }[] = [
  { key: 'business', label: 'Business profile' },
  { key: 'account', label: 'Account & security' },
  { key: 'invoices', label: 'Invoices & payments' },
]

// ---- main form (one Save bar for every section) ----
const s = data.value
const form = useForm({
  business: { name: s.business.name, type: s.business.type, phone: s.business.phone, email: s.business.email, address: s.business.address, kra_pin: s.business.kra_pin },
  region: { ...s.region },
  account: { name: s.account.name, email: s.account.email, phone: s.account.phone },
  invoice: { ...s.invoice },
  payments: { paypal_link_on_invoices: s.payments.paypal_link_on_invoices, auto_mark_paid: s.payments.auto_mark_paid },
  logo: null as File | null,
})

interface Field {
  group: 'business' | 'region' | 'account' | 'invoice'
  key: string
  label: string
  type?: 'text' | 'email' | 'tel' | 'select'
  options?: { value: string | number; label: string }[]
  numeric?: boolean
  full?: boolean
  hint?: string
  readonly?: boolean
}

const businessFields: Field[] = [
  { group: 'business', key: 'name', label: 'Business name' },
  { group: 'business', key: 'type', label: 'Business type' },
  { group: 'business', key: 'phone', label: 'Phone', type: 'tel' },
  { group: 'business', key: 'email', label: 'Business email', type: 'email' },
  { group: 'business', key: 'address', label: 'Address', full: true },
  { group: 'business', key: 'kra_pin', label: 'KRA PIN (optional)', full: true, hint: 'Shown on invoices if you add it.' },
]
const regionFields: Field[] = [
  { group: 'region', key: 'currency', label: 'Preferred display currency', type: 'select', options: [
    { value: 'KES', label: 'KES · Kenyan shilling' }, { value: 'USD', label: 'USD · US dollar' },
    { value: 'EUR', label: 'EUR · Euro' }, { value: 'GBP', label: 'GBP · British pound' } ] },
  { group: 'region', key: 'timezone', label: 'Timezone', type: 'select', options: [
    { value: 'Africa/Nairobi', label: 'Africa/Nairobi (EAT)' }, { value: 'Africa/Kampala', label: 'Africa/Kampala (EAT)' },
    { value: 'Africa/Dar_es_Salaam', label: 'Africa/Dar es Salaam (EAT)' } ] },
  { group: 'region', key: 'date_format', label: 'Date format', type: 'select', options: [
    { value: 'd M Y', label: '30 Oct 2026' }, { value: 'd/m/Y', label: '30/10/2026' }, { value: 'Y-m-d', label: '2026-10-30' } ] },
  { group: 'region', key: 'number_format', label: 'Number format', type: 'select', options: [
    { value: '1,234.00', label: '1,234.00' }, { value: '1.234,00', label: '1.234,00' }, { value: '1 234.00', label: '1 234.00' } ] },
]
const accountFields: Field[] = [
  { group: 'account', key: 'name', label: 'Full name' },
  { group: 'account', key: 'role', label: 'Role', readonly: true },
  { group: 'account', key: 'email', label: 'Email', type: 'email' },
  { group: 'account', key: 'phone', label: 'Phone', type: 'tel' },
]
const invoiceFields: Field[] = [
  { group: 'invoice', key: 'prefix', label: 'Invoice prefix' },
  { group: 'invoice', key: 'next_number', label: 'Next number' },
  { group: 'invoice', key: 'due_days', label: 'Default due date', type: 'select', numeric: true, options: [
    { value: 7, label: '7 days after issue' }, { value: 14, label: '14 days after issue' },
    { value: 30, label: '30 days after issue' }, { value: 0, label: 'On receipt' } ] },
  { group: 'invoice', key: 'vat_rate', label: 'VAT rate', type: 'select', numeric: true, options: [
    { value: 0, label: '0% (not charged)' }, { value: 16, label: '16%' } ] },
  { group: 'invoice', key: 'note', label: 'Note shown on every invoice', full: true },
]

const val = (f: Field) => (f.readonly ? (data.value.account as any)[f.key] : (form as any)[f.group][f.key])
const setVal = (f: Field, e: Event) => {
  const v = (e.target as HTMLInputElement).value
  ;(form as any)[f.group][f.key] = f.numeric ? Number(v) : v
}
const err = (f: Field) => (form.errors as Record<string, string>)[`${f.group}.${f.key}`]

const inputClass =
  'w-full rounded-lg border-[1.5px] border-line bg-paper px-3 py-2.5 text-sm text-brand-forest focus:border-[#15966b] focus:outline-none read-only:bg-black/[0.03]'
const btnPrimary =
  'rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white hover:bg-[#0d6b4a] disabled:opacity-50'
const btnOutline =
  'rounded-xl border-[1.5px] border-brand-forest bg-paper px-[18px] py-2.5 text-sm font-semibold text-brand-forest hover:bg-[#e5f4ed] disabled:opacity-50'

// ---- logo ----
const fileInput = ref<HTMLInputElement | null>(null)
const logoPreview = ref<string | null>(null)
const logoSrc = computed(() => logoPreview.value ?? data.value.business.logo_url)
const initials = computed(() =>
  form.business.name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join(''),
)
function onLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0] ?? null
  form.logo = file
  logoPreview.value = file ? URL.createObjectURL(file) : null
}

// ---- save / discard ----
function save() {
  // Backend route to add: PUT /business-settings (multipart because of the logo)
  form.transform((d) => ({ ...d, _method: 'put' })).post('/business-settings', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => form.defaults(),
  })
}
function discard() {
  form.reset()
  logoPreview.value = null
}
const statusText = computed(() => (form.isDirty ? 'You have unsaved changes' : 'All changes saved'))

// ---- password (uses the starter kit's PUT /settings/password) ----
const pw = useForm({ current_password: '', password: '', password_confirmation: '' })
function updatePassword() {
  pw.password_confirmation = pw.password // Figma has no confirm field
  pw.put('/settings/password', { preserveScroll: true, onSuccess: () => pw.reset() })
}

// ---- delete account (starter kit's DELETE /settings/profile needs the password) ----
const confirmingDelete = ref(false)
const del = useForm({ password: '' })
function deleteAccount() {
  del.delete('/settings/profile', { preserveScroll: true, onFinish: () => del.reset() })
}
</script>

<template>
  <Head title="Business Settings" />
  <div class="px-6 py-6">
    <h1 class="text-[26px] font-bold leading-8 text-brand-forest">Business Settings</h1>
    <p class="mt-1 text-sm text-brand-forest/60">Manage your business, account and how MtaaFlow works for you.</p>

    <div class="mt-5 grid items-start gap-5 lg:grid-cols-[230px_1fr]">
      <nav class="flex flex-col gap-1 rounded-[18px] border border-line bg-paper p-3" aria-label="Settings sections">
        <button v-for="m in menu" :key="m.key" type="button"
          class="rounded-[10px] px-3 py-[9px] text-left text-sm"
          :class="section === m.key ? 'bg-[#e5f4ed] font-semibold text-brand-forest' : 'text-brand-forest hover:bg-black/[0.03]'"
          :aria-current="section === m.key ? 'page' : undefined" @click="section = m.key">
          {{ m.label }}
        </button>
      </nav>

      <div class="flex flex-col gap-4">
        <!-- Business profile -->
        <template v-if="section === 'business'">
          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Business profile</h2>
              <p class="text-xs text-brand-forest/60">This appears on every invoice you send.</p>
            </header>
            <div class="flex items-center gap-4">
              <div class="flex size-[72px] shrink-0 items-center justify-center overflow-hidden rounded-[18px] bg-[#e5f4ed] text-[20px] font-bold text-brand-forest">
                <img v-if="logoSrc" :src="logoSrc" alt="Business logo" class="size-full object-cover" />
                <span v-else>{{ initials }}</span>
              </div>
              <div class="flex flex-col items-start gap-1">
                <button type="button" :class="btnOutline" @click="fileInput?.click()">Upload logo</button>
                <p class="text-xs text-brand-forest/60">PNG or JPG, at least 200 px square</p>
                <input ref="fileInput" type="file" accept="image/png,image/jpeg" class="hidden" @change="onLogo" />
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="f in businessFields" :key="f.key" class="flex flex-col gap-1.5" :class="f.full ? 'sm:col-span-2' : ''">
                <span class="text-xs text-brand-forest/60">{{ f.label }}</span>
                <input :type="f.type ?? 'text'" :value="val(f)" :class="inputClass" @input="setVal(f, $event)" />
                <span v-if="f.hint" class="text-xs text-brand-forest/60">{{ f.hint }}</span>
                <span v-if="err(f)" class="text-xs text-[#b4432f]">{{ err(f) }}</span>
              </label>
            </div>
          </section>

          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Currency and region</h2>
              <p class="text-xs text-brand-forest/60">Choose how amounts and dates are shown.</p>
            </header>
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="f in regionFields" :key="f.key" class="flex flex-col gap-1.5">
                <span class="text-xs text-brand-forest/60">{{ f.label }}</span>
                <select :value="val(f)" :class="inputClass" @change="setVal(f, $event)">
                  <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <span v-if="err(f)" class="text-xs text-[#b4432f]">{{ err(f) }}</span>
              </label>
            </div>
            <p class="text-xs leading-[1.45] text-brand-forest/60">
              Local Currency View: estimated equivalents use dated exchange rates. PayPal invoices retain USD, EUR or GBP; actual payouts may differ after fees and conversion.
            </p>
          </section>
        </template>

        <!-- Account & security -->
        <template v-else-if="section === 'account'">
          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Personal account</h2>
              <p class="text-xs text-brand-forest/60">Your own sign-in details.</p>
            </header>
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="f in accountFields" :key="f.key" class="flex flex-col gap-1.5">
                <span class="text-xs text-brand-forest/60">{{ f.label }}</span>
                <input :type="f.type ?? 'text'" :value="val(f)" :readonly="f.readonly" :class="inputClass" @input="setVal(f, $event)" />
                <span v-if="err(f)" class="text-xs text-[#b4432f]">{{ err(f) }}</span>
              </label>
            </div>
          </section>

          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Password and security</h2>
              <p class="text-xs text-brand-forest/60">Keep your business and customers’ details safe.</p>
            </header>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs text-brand-forest/60">Current password</span>
                <input v-model="pw.current_password" type="password" autocomplete="current-password" :class="inputClass" />
                <span v-if="pw.errors.current_password" class="text-xs text-[#b4432f]">{{ pw.errors.current_password }}</span>
              </label>
              <label class="flex flex-col gap-1.5">
                <span class="text-xs text-brand-forest/60">New password</span>
                <input v-model="pw.password" type="password" autocomplete="new-password" placeholder="At least 8 characters" :class="inputClass" />
                <span v-if="pw.errors.password" class="text-xs text-[#b4432f]">{{ pw.errors.password }}</span>
              </label>
            </div>
            <div><button type="button" :class="btnPrimary" :disabled="pw.processing || !pw.current_password || !pw.password" @click="updatePassword">Update password</button></div>

            <div v-for="row in [
              { title: 'Two-step verification', note: 'Ask for a code when you sign in on a new device.', later: true },
              { title: 'Sign out of other devices', note: 'Ends every session except this one.', later: false },
            ]" :key="row.title" class="flex items-center gap-4 border-b border-line py-3">
              <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-sm font-semibold text-brand-forest">
                  {{ row.title }}
                  <span v-if="row.later" class="rounded-full bg-[#ecebe5] px-2 py-px text-xs font-normal text-[#58625e]">Coming later</span>
                </p>
                <p class="text-xs text-brand-forest/60">{{ row.note }}</p>
              </div>
              <button type="button" role="switch" aria-checked="false" disabled :aria-label="row.title"
                class="relative h-6 w-[42px] shrink-0 rounded-full bg-[#d9d8d0] opacity-70">
                <span class="absolute top-[3px] left-[3px] size-[18px] rounded-full bg-white"></span>
              </button>
            </div>
          </section>

          <section class="flex flex-wrap items-center gap-3 rounded-[14px] bg-[#f9e4df] px-4 py-3.5">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-[#b4432f]">Delete this account</p>
              <p class="text-xs text-brand-forest/60">Removes your business, invoices and customers. This cannot be undone.</p>
            </div>
            <button v-if="!confirmingDelete" type="button" :class="btnOutline" @click="confirmingDelete = true">Delete account</button>
            <div v-else class="flex flex-wrap items-center gap-2">
              <input v-model="del.password" type="password" placeholder="Confirm with your password" :class="[inputClass, 'w-[240px]']" />
              <button type="button" class="rounded-xl bg-[#b4432f] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                :disabled="del.processing || !del.password" @click="deleteAccount">Delete for good</button>
              <button type="button" :class="btnOutline" @click="confirmingDelete = false; del.reset()">Cancel</button>
              <span v-if="del.errors.password" class="w-full text-xs text-[#b4432f]">{{ del.errors.password }}</span>
            </div>
          </section>
        </template>

        <!-- Invoices & payments -->
        <template v-else>
          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Invoice settings</h2>
              <p class="text-xs text-brand-forest/60">Defaults used by the AI Invoice Generator.</p>
            </header>
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="f in invoiceFields" :key="f.key" class="flex flex-col gap-1.5" :class="f.full ? 'sm:col-span-2' : ''">
                <span class="text-xs text-brand-forest/60">{{ f.label }}</span>
                <select v-if="f.type === 'select'" :value="val(f)" :class="inputClass" @change="setVal(f, $event)">
                  <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <input v-else type="text" :value="val(f)" :class="inputClass" @input="setVal(f, $event)" />
                <span v-if="err(f)" class="text-xs text-[#b4432f]">{{ err(f) }}</span>
              </label>
            </div>
          </section>

          <section class="flex flex-col gap-3.5 rounded-[18px] border border-line bg-paper p-6">
            <header>
              <h2 class="text-[20px] font-bold leading-[1.15] text-brand-forest">Payment settings</h2>
              <p class="text-xs text-brand-forest/60">How customers pay you.</p>
            </header>

            <div class="flex flex-wrap items-center gap-3.5 rounded-[14px] border border-line bg-[#faf9f6] px-4 py-3.5">
              <span class="rounded-md bg-[#eceff5] px-2 py-0.5 text-sm font-semibold text-[#2a5f8f]">PayPal</span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand-forest">
                  {{ data.payments.paypal.connected ? 'Connected' : 'Not connected' }}<template v-if="data.payments.paypal.mode === 'sandbox'"> · sandbox mode</template>
                </p>
                <p class="truncate text-xs text-brand-forest/60">
                  {{ data.payments.paypal.email }}<template v-if="data.payments.paypal.mode === 'sandbox'"> · payments here are for testing only</template>
                </p>
              </div>
              <span class="rounded-full px-2.5 py-[3px] text-xs font-semibold"
                :class="data.payments.paypal.connected ? 'bg-[#e5f4ed] text-[#0d6b4a]' : 'bg-[#ecebe5] text-[#58625e]'">
                {{ data.payments.paypal.connected ? 'Connected' : 'Not connected' }}
              </span>
              <button type="button" :class="btnOutline">{{ data.payments.paypal.connected ? 'Manage' : 'Connect' }}</button>
            </div>

            <div v-for="t in [
              { key: 'paypal_link_on_invoices', title: 'Add a PayPal checkout link to every invoice', note: 'Customers can pay straight from the invoice.' },
              { key: 'auto_mark_paid', title: 'Mark invoices as paid automatically', note: 'When PayPal confirms the payment.' },
            ]" :key="t.key" class="flex items-center gap-4 border-b border-line py-3">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand-forest">{{ t.title }}</p>
                <p class="text-xs text-brand-forest/60">{{ t.note }}</p>
              </div>
              <button type="button" role="switch" :aria-checked="(form.payments as any)[t.key]" :aria-label="t.title"
                class="relative h-6 w-[42px] shrink-0 rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#15966b]"
                :class="(form.payments as any)[t.key] ? 'bg-[#15966b]' : 'bg-[#d9d8d0]'"
                @click="(form.payments as any)[t.key] = !(form.payments as any)[t.key]">
                <span class="absolute top-[3px] size-[18px] rounded-full bg-white transition-all"
                  :class="(form.payments as any)[t.key] ? 'left-[21px]' : 'left-[3px]'"></span>
              </button>
            </div>

            <div class="flex items-center gap-4 border-b border-line py-3">
              <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-sm font-semibold text-brand-forest">
                  Accept M-Pesa
                  <span class="rounded-full bg-[#ecebe5] px-2 py-px text-xs font-normal text-[#58625e]">Coming later</span>
                </p>
                <p class="text-xs text-brand-forest/60">Planned for a later release.</p>
              </div>
              <button type="button" role="switch" aria-checked="false" disabled aria-label="Accept M-Pesa"
                class="relative h-6 w-[42px] shrink-0 rounded-full bg-[#d9d8d0] opacity-70">
                <span class="absolute top-[3px] left-[3px] size-[18px] rounded-full bg-white"></span>
              </button>
            </div>
          </section>
        </template>

        <!-- Save bar (shared by every section) -->
        <div class="flex items-center justify-end gap-3">
          <p class="text-xs text-brand-forest/60" aria-live="polite">{{ statusText }}</p>
          <button type="button" :class="btnOutline" :disabled="!form.isDirty || form.processing" @click="discard">Discard</button>
          <button type="button" :class="btnPrimary" :disabled="!form.isDirty || form.processing" @click="save">Save changes</button>
        </div>
      </div>
    </div>
  </div>
</template>
