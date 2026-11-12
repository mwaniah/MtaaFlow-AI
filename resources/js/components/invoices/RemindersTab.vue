<script setup lang="ts">
import { statusLabel } from '@/lib/i18n';
import { dateTime, translate as t } from '@/lib/i18n';
import { onMounted, ref } from 'vue';
import { index, store, update, destroy } from '@/routes/payment-reminders';
import { store as deliver } from '@/routes/payment-reminders/deliveries';
import { requestJson } from '@/lib/api';
import type { InvoiceRecord } from '@/types/business';
type Reminder = {
    id: number;
    invoice_id: number;
    message: string;
    status: string;
    delivery_channel: string | null;
    sent_at: string | null;
    prepared_at: string | null;
    invoice: InvoiceRecord;
};
defineProps<{ invoices: InvoiceRecord[] }>();
const reminders = ref<Reminder[]>([]);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const whatsappUrl = ref('');
const invoiceId = ref<number | null>(null);
const message = ref('');
const editing = ref<number | null>(null);
async function load() {
    reminders.value = (
        await requestJson<{ reminders: Reminder[] }>(index())
    ).reminders;
}
onMounted(async () => {
    try {
        await load();
    } catch (e) {
        error.value = String(e);
    } finally {
        loading.value = false;
    }
});
async function run(action: () => Promise<void>) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    whatsappUrl.value = '';
    try {
        await action();
        await load();
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}
function save() {
    void run(async () => {
        if (editing.value)
            await requestJson(update(editing.value), {
                message: message.value,
            });
        else
            await requestJson(store(), {
                invoice_id: invoiceId.value,
                message: message.value,
            });
        message.value = '';
        editing.value = null;
        notice.value = t('interface.reminder_draft_saved_it_has_not_been_sent');
    });
}
function edit(r: Reminder) {
    editing.value = r.id;
    invoiceId.value = r.invoice_id;
    message.value = r.message;
}
function remove(r: Reminder) {
    if (window.confirm(t('reminders.deletePrompt')))
        void run(async () => {
            await requestJson(destroy(r.id));
            notice.value = t('interface.reminder_deleted');
        });
}
function send(r: Reminder, channel: 'email' | 'whatsapp') {
    void run(async () => {
        const result = await requestJson<{
            delivery_status: string;
            whatsapp_url?: string;
        }>(deliver(r.id), { channel });
        notice.value =
            result.delivery_status === 'sent'
                ? t('reminders.emailAccepted')
                : t('reminders.whatsappPrepared');
        whatsappUrl.value = result.whatsapp_url ?? '';
    });
}
</script>
<template>
    <section
        class="space-y-5 rounded-[18px] border border-[#e6e4dc] bg-white p-5"
    >
        <h2 class="font-display text-xl font-bold">
            {{ t('reminders.title') }}
        </h2>
        <p v-if="error" role="alert" class="text-[#b4432f]">{{ error }}</p>
        <p v-if="notice" role="status">{{ notice }}</p>
        <a
            v-if="whatsappUrl"
            :href="whatsappUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="text-[#15966b] underline"
            >{{ t('interface.open_prepared_whatsapp_message') }}</a
        >
        <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <label class="flex flex-col gap-1"
                >{{ t('interface.approved_invoice')
                }}<select
                    v-model="invoiceId"
                    :disabled="!!editing"
                    required
                    class="rounded-xl border px-3 py-2"
                >
                    <option :value="null" disabled>
                        {{ t('interface.select_an_invoice') }}
                    </option>
                    <option
                        v-for="inv in invoices.filter(
                            (i) => i.status === 'approved',
                        )"
                        :key="inv.id"
                        :value="inv.id"
                    >
                        {{ inv.invoice_number }} · {{ inv.customer?.name }}
                    </option>
                </select></label
            ><label class="flex flex-col gap-1"
                >{{ t('reminders.message')
                }}<textarea
                    v-model="message"
                    required
                    maxlength="10000"
                    class="rounded-xl border px-3 py-2"
                />
            </label>
            <div class="flex gap-3">
                <button
                    :disabled="busy"
                    class="w-fit rounded-xl bg-[#15966b] px-5 py-2 text-white"
                >
                    {{
                        editing
                            ? t('interface.update_draft')
                            : t('interface.create_reminder_draft')
                    }}</button
                ><button
                    v-if="editing"
                    type="button"
                    @click="
                        editing = null;
                        message = '';
                    "
                >
                    {{ t('interface.cancel_edit') }}
                </button>
            </div>
        </form>
        <p v-if="loading">{{ t('interface.loading_reminders') }}</p>
        <p v-else-if="!reminders.length && !error">
            {{ t('interface.no_reminders_recorded') }}
        </p>
        <article
            v-for="r in reminders"
            :key="r.id"
            class="space-y-2 border-t py-4"
        >
            <h3 class="font-semibold">
                {{ r.invoice?.invoice_number }} ·
                {{ r.invoice?.customer?.name }}
            </h3>
            <p class="whitespace-pre-wrap">{{ r.message }}</p>
            <p class="text-sm text-[#6b7773]">
                {{ statusLabel(r.status) }} ·
                {{
                    (r.delivery_channel === 'email'
                        ? t('reminders.email')
                        : r.delivery_channel) || t('interface.not_sent')
                }}<span v-if="r.sent_at"> · {{ dateTime(r.sent_at) }}</span>
            </p>
            <div v-if="r.status === 'draft'" class="flex flex-wrap gap-4">
                <button :disabled="busy" class="underline" @click="edit(r)">
                    {{ t('common.edit') }}</button
                ><button
                    :disabled="busy"
                    class="text-[#b4432f] underline"
                    @click="remove(r)"
                >
                    {{ t('common.delete') }}</button
                ><button
                    :disabled="busy"
                    class="text-[#15966b] underline"
                    @click="send(r, 'email')"
                >
                    {{ t('interface.send_email') }}</button
                ><button
                    :disabled="busy"
                    class="text-[#15966b] underline"
                    @click="send(r, 'whatsapp')"
                >
                    {{ t('interface.prepare_whatsapp') }}
                </button>
            </div>
        </article>
    </section>
</template>
