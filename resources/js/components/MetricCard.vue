<script setup lang="ts">
import { i18n } from '@/lib/i18n';
defineProps<{ label: string; amount: number }>();

const formatKsh = (n: number) =>
    `KSh ${n.toLocaleString(i18n.global.locale.value)}`;
</script>

<template>
    <div
        class="flex flex-col gap-1 rounded-[18px] border border-line bg-paper p-5"
    >
        <p class="text-xs text-ink-muted">{{ label }}</p>
        <p class="text-display text-ink">{{ formatKsh(amount) }}</p>
    </div>
</template>
