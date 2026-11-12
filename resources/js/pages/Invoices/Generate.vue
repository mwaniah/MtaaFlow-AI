<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head :title="t('nav.invoices')" />
    <div class="flex flex-col gap-5 p-7">
        <h1 class="text-xl font-semibold">
            {{ t('interface.generate_with_ai') }}
        </h1>
    </div>
</template>
