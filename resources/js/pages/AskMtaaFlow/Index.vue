<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

type Segment = { label: string; amount: number }
type Row = { label: string; value: number }
type Answer = { title: string; body: string; segments?: Segment[]; rows?: Row[]; note?: string; followups?: string[] }
type Msg =
  | { role: 'user'; text: string }
  | { role: 'ai'; thinking: true }
  | { role: 'ai'; thinking?: false; answer: Answer }

const props = defineProps<{
  history?: { group: string; items: { id: number | string; title: string }[] }[]
}>()

const page = usePage<any>()
const firstName = computed(() => page.props.auth?.user?.name?.split(' ')[0] ?? 'there')

// ---- set to false once the backend endpoint exists ----
const USE_MOCK = true
const ENDPOINT = '/ask/message'

const history = computed(() => props.history ?? [
  { group: 'TODAY', items: [{ id: 1, title: 'Outstanding payments' }, { id: 2, title: 'Best sellers' }] },
  { group: 'YESTERDAY', items: [{ id: 3, title: 'October sales so far' }] },
  { group: 'THIS WEEK', items: [{ id: 4, title: 'Overdue invoices' }, { id: 5, title: 'Restocking advice' }] },
])
const activeId = ref<number | string>(1)

const suggestions = [
  { tag: 'SALES', text: 'Convert my earnings to KES' },
  { tag: 'CUSTOMERS', text: 'Which customers haven’t paid?' },
  { tag: 'PRODUCTS', text: 'What are my best-selling products?' },
  { tag: 'INVOICES', text: 'Which invoices are overdue?' },
  { tag: 'OVERVIEW', text: 'How is my business performing?' },
]

const messages = ref<Msg[]>([])
const input = ref('')
const busy = ref(false)
const showClear = ref(false)
const scroller = ref<HTMLElement | null>(null)

const money = (n: number) => `KSh ${Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 })}`
const segColors = ['#b4432f', '#15966b', '#7bc9a7', '#bfe3d3']
const segTotal = (s: Segment[]) => s.reduce((a, x) => a + x.amount, 0) || 1
const rowMax = (r: Row[]) => Math.max(...r.map((x) => x.value), 1)

function xsrf() {
  const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

// ---- sample answers (used while USE_MOCK = true) ----
function mockAnswer(q: string): Answer {
  const t = q.toLowerCase()
  if (t.includes('paid') || t.includes('overdue') || t.includes('unpaid'))
    return {
      title: `${money(18500)} still unpaid`,
      body: 'Four customers have open invoices. Peter Otieno is your largest unpaid amount and is 4 days late.',
      segments: [
        { label: 'Peter Otieno', amount: 7000 }, { label: 'Brian Kamau', amount: 6100 },
        { label: 'Daniel Kiprop', amount: 3100 }, { label: 'Lucy Achieng', amount: 2300 },
      ],
      note: 'From your invoices · Updated today',
      followups: ['Draft reminders', 'Show the overdue one'],
    }
  if (t.includes('best') || t.includes('product') || t.includes('stock'))
    return {
      title: 'Hoodies lead',
      body: 'Hoodies are your best seller this month with 58 sold. Caps follow with 41. Only 4 black M hoodies are left, so they are worth restocking soon.',
      rows: [{ label: 'Hoodies · 58 sold', value: 58 }, { label: 'Caps · 41 sold', value: 41 }, { label: 'Tote bags · 22 sold', value: 22 }],
      note: 'Based on 141 units sold in October',
      followups: ['See restock advice', 'Compare with September'],
    }
  if (t.includes('earn') || t.includes('kes') || t.includes('convert') || t.includes('shilling'))
    return {
      title: `Estimated ${money(124300)}`,
      body: 'This is what you have collected in verified payments this month. Actual results use your payment records and the latest dated exchange rate. Fees and withdrawals may differ.',
      note: 'Estimate only · Not a payout quote',
      followups: ['Show unpaid invoices', 'Compare with September'],
    }
  return {
    title: 'Your business is growing',
    body: 'Revenue is up 12% on September, 87% of billed amounts are collected, and 4 invoices are still outstanding.',
    note: 'Based on your October records',
    followups: ['Which customers haven’t paid?', 'What are my best-selling products?'],
  }
}

async function getAnswer(q: string): Promise<Answer> {
  if (USE_MOCK) {
    await new Promise((r) => setTimeout(r, 1400))
    return mockAnswer(q)
  }
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    body: JSON.stringify({ message: q }),
  })
  if (!res.ok) throw new Error('Request failed')
  return res.json()
}

const toBottom = () => nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' }))

async function send(text?: string) {
  const q = (text ?? input.value).trim()
  if (!q || busy.value) return
  input.value = ''
  busy.value = true
  messages.value.push({ role: 'user', text: q }, { role: 'ai', thinking: true })
  toBottom()
  try {
    const answer = await getAnswer(q)
    messages.value[messages.value.length - 1] = { role: 'ai', answer }
  } catch {
    messages.value[messages.value.length - 1] = {
      role: 'ai',
      answer: { title: 'Something went wrong', body: 'I couldn’t check your records just now. Please try again.' },
    }
  } finally {
    busy.value = false
    toBottom()
  }
}

const newChat = () => { messages.value = []; input.value = '' }
const confirmClear = () => { newChat(); showClear.value = false }
</script>

<template>
  <div class="flex h-[calc(100vh-8rem)] min-h-[560px] gap-4">
    <!-- History -->
    <aside class="hidden w-[240px] shrink-0 flex-col gap-2.5 overflow-y-auto rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:flex">
      <button class="w-max rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white" @click="newChat">New chat</button>
      <template v-for="g in history" :key="g.group">
        <p class="text-xs text-[#6b7773]">{{ g.group }}</p>
        <button
          v-for="i in g.items" :key="i.id"
          class="w-full rounded-[10px] px-2.5 py-2 text-left text-sm"
          :class="activeId === i.id ? 'bg-[#e5f4ed] font-semibold text-[#123d32]' : 'bg-white text-[#202a27] hover:bg-[#faf9f6]'"
          @click="activeId = i.id"
        >{{ i.title }}</button>
      </template>
      <div class="flex-1" />
      <button class="w-max px-2.5 py-2 text-left text-sm font-medium text-[#b4432f]" @click="showClear = true">Clear conversation</button>
    </aside>

    <!-- Chat -->
    <section class="flex min-w-0 flex-1 flex-col overflow-hidden rounded-[18px] border border-[#e6e4dc] bg-white">
      <header class="flex items-center gap-2.5 border-b border-[#e6e4dc] px-6 py-4">
        <div class="size-[26px] rounded-lg rounded-bl-[2px] bg-gradient-to-br from-[#15966b] to-[#7be0b6]" />
        <div class="flex flex-col leading-[1.45]">
          <h1 class="font-display text-base font-medium tracking-[-0.01em] text-[#202a27]">Ask MtaaFlow</h1>
          <p class="text-xs text-[#6b7773]">Answers come from your own invoices, customers and stock</p>
        </div>
      </header>

      <div ref="scroller" class="flex flex-1 flex-col gap-5 overflow-y-auto p-6">
        <!-- Welcome -->
        <div v-if="!messages.length" class="flex flex-1 flex-col items-center justify-center gap-3.5 text-center">
          <div class="size-14 rounded-[17px] rounded-bl-[2px] bg-gradient-to-br from-[#15966b] to-[#7be0b6]" />
          <h2 class="font-display text-[28px] font-bold leading-[1.15] tracking-[-0.02em] text-[#202a27]">Habari, {{ firstName }}. What would you like to know?</h2>
          <p class="max-w-[640px] text-sm text-[#6b7773]">Ask about your verified earnings, local currency conversions, invoices or customers. I’ll check your records and explain.</p>
          <div class="grid w-full max-w-[860px] grid-cols-2 gap-3 pt-4 sm:grid-cols-3 xl:grid-cols-5">
            <button
              v-for="s in suggestions" :key="s.tag"
              class="flex w-full flex-col items-start gap-1.5 rounded-[14px] border border-[#e6e4dc] bg-white px-4 py-3.5 text-left hover:border-[#15966b]"
              @click="send(s.text)"
            >
              <span class="text-xs text-[#15966b]">{{ s.tag }}</span>
              <span class="text-sm font-medium text-[#202a27]">{{ s.text }}</span>
            </button>
          </div>
        </div>

        <!-- Conversation -->
        <template v-for="(m, idx) in messages" :key="idx">
          <div v-if="m.role === 'user'" class="flex justify-end">
            <div class="max-w-[75%] rounded-[18px] rounded-tr-[4px] bg-[#123d32] px-4 py-2.5 text-sm text-white">{{ m.text }}</div>
          </div>

          <div v-else class="flex items-start gap-3">
            <div class="size-7 shrink-0 rounded-lg rounded-bl-[2px] bg-gradient-to-br from-[#15966b] to-[#7be0b6]" />

            <!-- Thinking -->
            <div v-if="m.thinking" class="flex min-w-0 flex-1 flex-col gap-3.5 rounded-[18px] rounded-tl-[4px] border border-[#e6e4dc] bg-white p-5">
              <h3 class="font-display text-xl font-bold tracking-[-0.01em] text-[#202a27]">Checking your records</h3>
              <p v-for="(s, i) in ['Looking through your invoices', 'Adding up what is still unpaid', 'Writing your answer']" :key="s" class="flex items-center gap-2.5 text-sm text-[#202a27]">
                <i class="size-3 animate-pulse rounded-full bg-[#15966b]" :style="{ animationDelay: i * 300 + 'ms' }" />{{ s }}
              </p>
              <div class="h-3 w-full animate-pulse rounded-full bg-[#ecebe5]" />
              <div class="h-3 w-3/4 animate-pulse rounded-full bg-[#ecebe5]" />
            </div>

            <!-- Answer -->
            <div v-else class="flex min-w-0 flex-1 flex-col items-start gap-3.5 rounded-[18px] rounded-tl-[4px] border border-[#e6e4dc] bg-white p-5">
              <h3 class="font-display text-xl font-bold tracking-[-0.01em] text-[#202a27]">{{ m.answer.title }}</h3>
              <p class="text-sm text-[#202a27]">{{ m.answer.body }}</p>

              <template v-if="m.answer.segments">
                <div class="flex h-3 w-full gap-0.5 overflow-hidden">
                  <div
                    v-for="(s, i) in m.answer.segments" :key="s.label" class="h-3"
                    :class="[i === 0 ? 'rounded-l-md' : '', i === m.answer.segments.length - 1 ? 'rounded-r-md' : '']"
                    :style="{ width: (s.amount / segTotal(m.answer.segments)) * 100 + '%', background: segColors[i % 4] }"
                  />
                </div>
                <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                  <span v-for="(s, i) in m.answer.segments" :key="s.label" class="flex items-center gap-1.5 text-xs text-[#202a27]">
                    <i class="size-2 rounded-full" :style="{ background: segColors[i % 4] }" />{{ s.label }} · {{ money(s.amount) }}
                  </span>
                </div>
              </template>

              <template v-if="m.answer.rows">
                <div v-for="r in m.answer.rows" :key="r.label" class="flex w-full flex-col gap-1">
                  <span class="text-sm font-medium text-[#202a27]">{{ r.label }}</span>
                  <div class="h-2 w-full overflow-hidden rounded-[9px] bg-[#e6e4dc]">
                    <div class="h-2 rounded-[9px] bg-[#15966b]" :style="{ width: (r.value / rowMax(m.answer.rows)) * 100 + '%' }" />
                  </div>
                </div>
              </template>

              <span v-if="m.answer.note" class="rounded-full bg-[#e5f4ed] px-2 py-0.5 text-xs text-[#0d6b4a]">{{ m.answer.note }}</span>

              <div v-if="m.answer.followups?.length" class="flex flex-wrap gap-2">
                <button
                  v-for="f in m.answer.followups" :key="f"
                  class="rounded-full border border-[#e6e4dc] bg-white px-3 py-1.5 text-sm font-medium text-[#202a27] hover:border-[#15966b]"
                  @click="send(f)"
                >{{ f }}</button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Input -->
      <footer class="flex flex-col gap-2 border-t border-[#e6e4dc] px-6 pb-4 pt-3.5">
        <div class="flex items-center gap-2.5">
          <input
            v-model="input" type="text" placeholder="Ask about your sales, customers or stock"
            class="flex-1 rounded-[14px] border-[1.5px] border-[#15966b] bg-[#faf9f6] px-4 py-3 text-sm text-[#202a27] outline-none placeholder:text-[#6b7773]"
            @keydown.enter="send()"
          />
          <button class="rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !input.trim()" @click="send()">Ask</button>
        </div>
        <p class="text-xs text-[#6b7773]">MtaaFlow reads your records but can make mistakes. Check important numbers before acting on them.</p>
      </footer>
    </section>

    <!-- Clear modal -->
    <div v-if="showClear" class="fixed inset-0 z-50 flex items-start justify-center bg-[rgba(18,61,50,0.55)] pt-60" @click.self="showClear = false">
      <div class="flex w-[440px] max-w-[90vw] flex-col gap-3.5 rounded-[20px] border border-[#e6e4dc] bg-white p-6 shadow-[0_12px_32px_rgba(18,61,51,0.18)]">
        <h3 class="font-display text-xl font-bold tracking-[-0.01em] text-[#202a27]">Clear this conversation?</h3>
        <p class="text-sm text-[#6b7773]">This removes the messages from this chat. Your invoices, customers and stock are not affected.</p>
        <div class="flex gap-3">
          <button class="rounded-xl bg-[#b4432f] px-[18px] py-2.5 text-sm font-semibold text-white" @click="confirmClear">Clear conversation</button>
          <button class="rounded-xl border-[1.5px] border-[#123d32] px-[18px] py-2.5 text-sm font-semibold text-[#123d32]" @click="showClear = false">Keep it</button>
        </div>
      </div>
    </div>
  </div>
</template>
