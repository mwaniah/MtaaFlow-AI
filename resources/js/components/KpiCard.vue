<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    hint?: string;
    danger?: boolean;
}>();
</script>

<template>
    <div
        class="flex min-w-0 flex-1 flex-col gap-1.5 rounded-[18px] border border-line bg-paper p-5"
    >
        <p class="text-xs text-ink-muted">{{ label }}</p>
        <p class="text-h1" :class="danger ? 'text-overdue' : 'text-ink'">
            {{ value }}
        </p>
        <p v-if="hint" class="text-xs text-ink-muted">{{ hint }}</p>
    </div>
</template>
