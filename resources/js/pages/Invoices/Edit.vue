<script setup lang="ts">
import InvoiceForm from './Create.vue';
import type { InvoiceRecord } from '@/types/business';
defineProps<{
    invoice: InvoiceRecord;
    customers: { id: number; name: string }[];
    currencies: unknown;
}>();
</script>
<template>
    <InvoiceForm
        :invoice="invoice"
        :customers="customers"
        :currencies="currencies"
        editing
    />
</template>
