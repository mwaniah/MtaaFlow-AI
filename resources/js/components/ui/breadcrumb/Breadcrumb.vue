<script lang="ts" setup>
import { translate as t } from '@/lib/i18n';
import type { HTMLAttributes } from "vue"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <nav
    :aria-label="t('interface.breadcrumb')"
    data-slot="breadcrumb"
    :class="props.class"
  >
    <slot />
  </nav>
</template>
