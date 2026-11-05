<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

type Group = 'overdue' | 'upcoming' | 'sent';
type Status = 'draft_ready' | 'not_sent' | 'scheduled' | 'sent';

interface Reminder {
    id: number;
    invoice_id: number;
    invoice_number: string;
    customer_name: string;
    customer_phone: string | null;
    amount: number;
    currency: string;
    group: Group;
    status: Status;
    status_label: string;
    due_label: string;
    message: string;
}

interface Kpi {
    amount: number;
    count: number;
    currency: string;
}

interface ReminderSummary {
    overdue: Kpi;
    due_this_week: Kpi;
    due_later: Kpi;
    sent_this_week: { count: number; paid_after_reminding: number };
}

const props = defineProps<{
    reminders: Reminder[];
    summary: ReminderSummary;
}>();

const filter = ref<Group>('overdue');

const counts = computed(() => ({
    overdue: props.reminders.filter((r) => r.group === 'overdue').length,
    upcoming: props.reminders.filter((r) => r.group === 'upcoming').length,
    sent: props.reminders.filter((r) => r.group === 'sent').length,
}));

const chips = computed(() => [
    { key: 'overdue' as Group, label: `Overdue ${counts.value.overdue}` },
    { key: 'upcoming' as Group, label: `Upcoming ${counts.value.upcoming}` },
    { key: 'sent' as Group, label: `Sent ${counts.value.sent}` },
]);

const visible = computed(() =>
    props.reminders.filter((r) => (filter.value === 'overdue' ? r.group !== 'sent' : r.group === filter.value)),
);

const selectedId = ref<number | null>(null);
const drafts = reactive<Record<number, string>>({});

const selected = computed(
    () => visible.value.find((r) => r.id === selectedId.value) ?? visible.value[0] ?? null,
);

watch(
    () => props.reminders,
    (list) => {
        for (const id of Object.keys(drafts)) {
            if (!list.some((r) => r.id === Number(id))) delete drafts[Number(id)];
        }
    },
);

const messageFor = (r: Reminder) => drafts[r.id] ?? r.message;

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]!.toUpperCase())
        .join('');

const money = (amount: number, currency: string) =>
    `${currency} ${new Intl.NumberFormat('en-KE', { maximumFractionDigits: 0 }).format(amount)}`;

const statusClass: Record<Status, string> = {
    draft_ready: 'bg-[#fbf0da] text-[#b7791f]',
    not_sent: 'bg-[#ecebe5] text-[#58625e]',
    scheduled: 'bg-[#ecebe5] text-[#58625e]',
    sent: 'bg-[#e5f4ed] text-[#0d6b4a]',
};

const sections = computed(() => {
    if (filter.value === 'upcoming') return [{ title: 'Upcoming', items: visible.value }];
    if (filter.value === 'sent') return [{ title: 'Sent', items: visible.value }];
    return [
        { title: 'Overdue', items: visible.value.filter((r) => r.group === 'overdue') },
        { title: 'Upcoming', items: visible.value.filter((r) => r.group === 'upcoming') },
    ];
});

function plural(n: number, word: string) {
    return `${n} ${word}${n === 1 ? '' : 's'}`;
}

const kpis = computed(() => [
    {
        label: 'Overdue',
        value: money(props.summary.overdue.amount, props.summary.overdue.currency),
        note: plural(props.summary.overdue.count, 'invoice'),
        tone: 'text-[#b4432f]',
    },
    {
        label: 'Due this week',
        value: money(props.summary.due_this_week.amount, props.summary.due_this_week.currency),
        note: plural(props.summary.due_this_week.count, 'invoice'),
        tone: 'text-[#b7791f]',
    },
    {
        label: 'Due later',
        value: money(props.summary.due_later.amount, props.summary.due_later.currency),
        note: plural(props.summary.due_later.count, 'invoice'),
        tone: 'text-[#202a27]',
    },
    {
        label: 'Sent this week',
        value: String(props.summary.sent_this_week.count),
        note: `${props.summary.sent_this_week.paid_after_reminding} paid after reminding`,
        tone: 'text-[#0d6b4a]',
    },
]);

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;
const modalOpen = ref(false);

async function copyMessage(r: Reminder) {
    try {
        await navigator.clipboard.writeText(messageFor(r));
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
        modalOpen.value = true;
    }
}

const marking = ref(false);

function markAsSent(r: Reminder) {
    marking.value = true;
    // Backend route to be added in routes/web.php:
    // POST /invoices/{invoice}/reminders/mark-sent  { message: string }
    router.post(
        `/invoices/${r.invoice_id}/reminders/mark-sent`,
        { message: messageFor(r) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (modalOpen.value = false),
            onFinish: () => (marking.value = false),
        },
    );
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') modalOpen.value = false;
}

watch(modalOpen, (open) => {
    if (open) window.addEventListener('keydown', onKeydown);
    else window.removeEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(copiedTimer);
});

const btnPrimary =
    'inline-flex items-center justify-center rounded-[12px] bg-[#15966b] px-[18px] py-[10px] text-[14px] font-semibold text-white transition hover:bg-[#0d6b4a] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#15966b] disabled:opacity-60';
const btnOutline =
    'inline-flex items-center justify-center rounded-[12px] border-[1.5px] border-[#123d32] bg-white px-[18px] py-[10px] text-[14px] font-semibold text-[#123d32] transition hover:bg-[#e5f4ed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#123d32] disabled:opacity-60';
</script>

<template>
    <div class="flex flex-col gap-5 font-['DM_Sans',sans-serif]">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="flex flex-col gap-[6px] rounded-[18px] border border-[#e6e4dc] bg-white p-5"
            >
                <p class="text-[12px] text-[#6b7773]">{{ kpi.label }}</p>
                <p
                    class="font-['Bricolage_Grotesque',sans-serif] text-[28px] leading-[1.15] font-bold tracking-[-0.56px]"
                    :class="kpi.tone"
                >
                    {{ kpi.value }}
                </p>
                <p class="text-[12px] text-[#6b7773]">{{ kpi.note }}</p>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-5">
            <section class="flex flex-col gap-[14px] rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-3">
                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Reminder groups">
                    <button
                        v-for="chip in chips"
                        :key="chip.key"
                        type="button"
                        role="tab"
                        :aria-selected="filter === chip.key"
                        class="rounded-full px-[14px] py-[7px] text-[14px] font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#123d32]"
                        :class="
                            filter === chip.key
                                ? 'bg-[#123d32] text-white'
                                : 'border border-[#e6e4dc] bg-white text-[#202a27] hover:bg-[#faf9f6]'
                        "
                        @click="
                            filter = chip.key;
                            selectedId = null;
                        "
                    >
                        {{ chip.label }}
                    </button>
                </div>

                <template v-for="section in sections" :key="section.title">
                    <p class="text-[12px] tracking-wide text-[#6b7773] uppercase">{{ section.title }}</p>

                    <p v-if="!section.items.length" class="py-6 text-center text-[14px] text-[#6b7773]">
                        Nothing here right now.
                    </p>

                    <button
                        v-for="r in section.items"
                        :key="r.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-[14px] p-3 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#15966b]"
                        :class="
                            selected?.id === r.id
                                ? 'border-[1.5px] border-[#15966b] bg-[#e5f4ed]'
                                : 'border-b border-[#e6e4dc] hover:bg-[#faf9f6]'
                        "
                        :aria-pressed="selected?.id === r.id"
                        @click="selectedId = r.id"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#e5f4ed] text-[13px] font-semibold text-[#123d32]"
                        >
                            {{ initials(r.customer_name) }}
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-[14px] font-semibold text-[#202a27]">{{ r.customer_name }}</span>
                            <span
                                class="truncate text-[12px]"
                                :class="r.group === 'overdue' ? 'text-[#b4432f]' : 'text-[#6b7773]'"
                            >
                                {{ r.invoice_number }} · {{ r.due_label }}
                            </span>
                        </span>
                        <span class="shrink-0 text-[14px] font-semibold text-[#202a27]">
                            {{ money(r.amount, r.currency) }}
                        </span>
                        <span
                            class="shrink-0 rounded-full px-[10px] py-[3px] text-[12px] font-semibold whitespace-nowrap"
                            :class="statusClass[r.status]"
                        >
                            {{ r.status_label }}
                        </span>
                    </button>
                </template>
            </section>

            <aside
                v-if="selected"
                class="flex flex-col items-start gap-[14px] rounded-[18px] border border-[#e6e4dc] bg-white p-5 lg:col-span-2"
            >
                <span class="rounded-full bg-[#e5f4ed] px-[10px] py-[2px] text-[12px] text-[#0d6b4a]">
                    Written by MtaaFlow AI
                </span>
                <h2 class="font-['Bricolage_Grotesque',sans-serif] text-[20px] leading-[1.15] font-bold tracking-[-0.2px] text-[#202a27]">
                    Reminder for {{ selected.customer_name }}
                </h2>

                <p
                    class="w-full rounded-[14px] border border-[#e6e4dc] bg-[#faf9f6] p-[14px] text-[14px] leading-[1.45] whitespace-pre-line text-[#202a27]"
                >
                    {{ messageFor(selected) }}
                </p>

                <div class="flex gap-[10px]">
                    <button type="button" :class="btnPrimary" @click="copyMessage(selected)">
                        {{ copied ? 'Copied' : 'Copy message' }}
                    </button>
                    <button type="button" :class="btnOutline" @click="modalOpen = true">Edit</button>
                </div>

                <button
                    v-if="selected.status !== 'sent'"
                    type="button"
                    :class="btnOutline"
                    :disabled="marking"
                    @click="markAsSent(selected)"
                >
                    Mark as sent
                </button>

                <p class="text-[12px] leading-[1.45] text-[#6b7773]" aria-live="polite">
                    MtaaFlow doesn’t send messages for you. Copy it into WhatsApp, SMS or email, then mark it as sent.
                </p>
            </aside>
        </div>

        <Teleport to="body">
            <div
                v-if="modalOpen && selected"
                class="fixed inset-0 z-50 flex items-center justify-center bg-[#123d32]/40 p-4"
                @click.self="modalOpen = false"
            >
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="review-reminder-title"
                    class="flex w-full max-w-[560px] flex-col gap-4 rounded-[20px] border border-[#e6e4dc] bg-white p-6 shadow-[0_12px_32px_rgba(18,61,51,0.18)]"
                >
                    <h2
                        id="review-reminder-title"
                        class="font-['Bricolage_Grotesque',sans-serif] text-[20px] leading-[1.15] font-bold tracking-[-0.2px] text-[#202a27]"
                    >
                        Review reminder
                    </h2>
                    <p class="text-[12px] text-[#6b7773]">
                        Read it through and edit anything before you use it. Nothing is sent from MtaaFlow.
                    </p>

                    <div class="flex items-center gap-[10px]">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#e5f4ed] text-[13px] font-semibold text-[#123d32]"
                        >
                            {{ initials(selected.customer_name) }}
                        </span>
                        <div class="flex flex-col">
                            <span class="text-[14px] font-semibold text-[#202a27]">{{ selected.customer_name }}</span>
                            <span class="text-[12px] text-[#6b7773]">
                                {{ [selected.customer_phone, selected.invoice_number, money(selected.amount, selected.currency)]
                                    .filter(Boolean)
                                    .join(' · ') }}
                            </span>
                        </div>
                    </div>

                    <label class="sr-only" for="reminder-message">Reminder message</label>
                    <textarea
                        id="reminder-message"
                        rows="4"
                        class="w-full resize-none rounded-[12px] border-[1.5px] border-[#15966b] bg-white p-[14px] text-[14px] leading-[1.45] text-[#202a27] outline-none"
                        :value="messageFor(selected)"
                        @input="drafts[selected.id] = ($event.target as HTMLTextAreaElement).value"
                    />

                    <div class="flex flex-wrap gap-[10px]">
                        <button type="button" :class="btnPrimary" @click="copyMessage(selected)">
                            {{ copied ? 'Copied' : 'Copy message' }}
                        </button>
                        <button
                            v-if="selected.status !== 'sent'"
                            type="button"
                            :class="btnOutline"
                            :disabled="marking"
                            @click="markAsSent(selected)"
                        >
                            Mark as sent
                        </button>
                        <button type="button" :class="btnOutline" @click="modalOpen = false">Close</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
