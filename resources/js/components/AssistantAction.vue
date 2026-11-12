<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { ActionProposal } from '@/composables/useAskConversation';
import { requestJson } from '@/lib/api';
import { fieldLabel } from '@/lib/i18n';
import { confirm, cancel } from '@/routes/ai/actions';
import { confirm as confirmInvoice } from '@/routes/ai/invoice';
import { show as showInvoice } from '@/routes/invoices';
import { show as showCustomer } from '@/routes/customers';
import { index as inventory } from '@/routes/inventory';

const props = defineProps<{ proposal: ActionProposal }>();
const { t } = useI18n();
const busy = ref(false);
const finished = ref(false);
const notice = ref('');
const error = ref('');
const destination = ref<string>();
const labels: Record<string, string> = {
    name: 'common.name',
    email: 'common.email',
    phone: 'common.phone',
    address: 'common.address',
    quantity: 'common.quantity',
    currency: 'common.currency',
    issue_date: 'invoices.issueDate',
    due_date: 'invoices.dueDate',
    description: 'common.description',
    unit_price: 'invoices.unitPrice',
    notes: 'inventory.notes',
    low_stock_threshold: 'inventory.threshold',
    sku: 'inventory.sku',
    message: 'reminders.message',
};
function rows(value: unknown, prefix = ''): { label: string; value: string }[] {
    if (!value || typeof value !== 'object') return [];
    return Object.entries(value).flatMap(([key, item]) => {
        if (item === null || key.endsWith('_id') || key === 'operation')
            return [];
        if (typeof item === 'object')
            return rows(
                item,
                Array.isArray(value) ? `${Number(key) + 1}. ` : prefix,
            );
        return [
            {
                label:
                    prefix + (labels[key] ? t(labels[key]) : fieldLabel(key)),
                value: String(item),
            },
        ];
    });
}
const details = computed(() => rows(props.proposal.preview));
async function submit(confirmed: boolean) {
    const token = props.proposal.confirmation.token;
    if (busy.value || finished.value || !token) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await requestJson<{
            invoice_id?: number;
            result?: { customer?: { id: number }; product?: { id: number } };
        }>(
            confirmed
                ? props.proposal.intent === 'invoice'
                    ? confirmInvoice()
                    : confirm()
                : cancel(),
            { confirmation_token: token },
        );
        finished.value = true;
        notice.value = t(confirmed ? 'ask.confirmed' : 'ask.cancelled');
        if (confirmed) {
            if (result.invoice_id)
                destination.value = showInvoice.url(result.invoice_id);
            else if (result.result?.customer)
                destination.value = showCustomer.url(result.result.customer.id);
            else if (result.result?.product)
                destination.value = inventory.url();
        }
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : t('common.error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-primary/30 bg-primary/5 p-4"
        :aria-busy="busy"
    >
        <h3 class="text-sm font-semibold">{{ t('ask.review') }}</h3>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <template v-for="(detail, index) in details" :key="index"
                ><dt class="text-muted-foreground">{{ detail.label }}</dt>
                <dd class="break-words whitespace-pre-wrap">
                    {{ detail.value }}
                </dd></template
            >
        </dl>
        <p class="text-xs text-muted-foreground">{{ t('ask.noChanges') }}</p>
        <div
            v-if="!finished && props.proposal.confirmation.token"
            class="flex flex-wrap gap-2"
        >
            <button
                type="button"
                class="mf-button"
                :disabled="busy"
                @click="submit(true)"
            >
                {{ t('common.confirm') }}
            </button>
            <button
                type="button"
                class="mf-button-secondary"
                :disabled="busy"
                @click="submit(false)"
            >
                {{ t('common.cancel') }}
            </button>
        </div>
        <p v-if="notice" role="status" class="text-sm">{{ notice }}</p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <Link
            v-if="destination"
            :href="destination"
            class="mf-button-secondary"
            >{{ t('ask.openRecord') }}</Link
        >
    </section>
</template>
