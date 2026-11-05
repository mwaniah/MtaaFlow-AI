<script setup lang="ts">
import { computed } from 'vue'
import { invoiceStatusLabels, type InvoiceStatus } from '@/types/invoice'

const props = defineProps<{ status: InvoiceStatus }>()

const styles: Record<InvoiceStatus, string> = {
  draft: 'bg-[#ECECE8] text-[#6B6B66]',
  pending: 'bg-[#FBEFD0] text-[#B26B0A]',
  partially_paid: 'bg-[#DCEBFA] text-[#2A5DA8]',
  paid: 'bg-[#DDF2E6] text-brand-forest',
  overdue: 'bg-overdue-bg text-overdue',
  cancelled: 'bg-[#E8E8E4] text-[#8A8A84]',
}
const classes = computed(() => styles[props.status])
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium leading-5" :class="classes">
    {{ invoiceStatusLabels[status] }}
  </span>
</template>
