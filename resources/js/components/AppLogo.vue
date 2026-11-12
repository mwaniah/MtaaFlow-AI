<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { usePage } from '@inertiajs/vue3';
const configuredName = usePage().props.name;
const name =
    configuredName && configuredName !== 'Laravel'
        ? configuredName
        : 'MtaaFlow AI';
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-2.5">
        <div
            class="relative flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-[11px_11px_11px_3px] bg-gradient-to-br from-[#15966b] to-[#123d32] shadow-[0_5px_18px_#071c1640]"
        >
            <span
                class="h-[21px] w-[17px] rotate-[29deg] rounded-[10px_2px_10px_2px] border-2 border-[#c7f0da]"
                aria-hidden="true"
            />
        </div>
        <div
            class="grid min-w-0 flex-1 text-left group-data-[collapsible=icon]:hidden"
        >
            <span
                class="truncate font-display text-[17px] font-semibold tracking-tight text-white"
                >{{ name }}</span
            >
            <span
                class="text-[10px] tracking-[0.14em] text-[#b6d5c8] uppercase"
                >{{ t('interface.your_work_in_flow') }}</span
            >
        </div>
    </div>
</template>
