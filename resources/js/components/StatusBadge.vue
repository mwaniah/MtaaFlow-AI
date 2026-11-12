<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { computed } from 'vue';

type BadgeStatus =
    | 'paid'
    | 'pending'
    | 'partial'
    | 'overdue'
    | 'draft'
    | 'cancelled'
    | 'up_to_date';

const props = defineProps<{ status: BadgeStatus }>();

const styles: Record<BadgeStatus, { label: string; classes: string }> = {
    paid: {
        get label() {
            return t('status.paid');
        },
        classes: 'bg-brand-mint text-paid',
    },
    up_to_date: {
        get label() {
            return t('interface.up_to_date');
        },
        classes: 'bg-brand-mint text-paid',
    },
    pending: {
        get label() {
            return t('status.pending');
        },
        classes: 'bg-pending-bg text-pending',
    },
    partial: {
        get label() {
            return t('interface.partially_paid');
        },
        classes: 'bg-partial-bg text-partial',
    },
    overdue: {
        get label() {
            return t('interface.overdue');
        },
        classes: 'bg-overdue-bg text-overdue',
    },
    draft: {
        get label() {
            return t('status.draft');
        },
        classes: 'bg-draft-bg text-draft',
    },
    cancelled: {
        get label() {
            return t('status.cancelled');
        },
        classes: 'bg-draft-bg text-cancelled',
    },
};

const current = computed(() => styles[props.status]);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-[3px] text-xs font-semibold whitespace-nowrap"
        :class="current.classes"
    >
        {{ current.label }}
    </span>
</template>
