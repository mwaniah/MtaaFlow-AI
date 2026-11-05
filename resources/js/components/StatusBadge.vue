<script setup lang="ts">
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
    paid: { label: 'Paid', classes: 'bg-brand-mint text-paid' },
    up_to_date: { label: 'Up to date', classes: 'bg-brand-mint text-paid' },
    pending: { label: 'Pending', classes: 'bg-pending-bg text-pending' },
    partial: { label: 'Partially paid', classes: 'bg-partial-bg text-partial' },
    overdue: { label: 'Overdue', classes: 'bg-overdue-bg text-overdue' },
    draft: { label: 'Draft', classes: 'bg-draft-bg text-draft' },
    cancelled: { label: 'Cancelled', classes: 'bg-draft-bg text-cancelled' },
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