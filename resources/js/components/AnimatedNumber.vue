<script setup lang="ts">
import { i18n } from '@/lib/i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number;
        format?: (value: number) => string;
        duration?: number;
        animate?: boolean;
    }>(),
    { duration: 650, animate: true },
);
const current = ref(props.value);
let frame = 0;
let media: MediaQueryList | undefined;
let mounted = false;
const formatted = (value: number) =>
    props.format
        ? props.format(value)
        : new Intl.NumberFormat(i18n.global.locale.value, {
              maximumFractionDigits: 0,
          }).format(value);
const actual = computed(() => formatted(props.value));
function update(from: number, to: number) {
    window.cancelAnimationFrame(frame);
    if (!props.animate || media?.matches || !Number.isFinite(to)) {
        current.value = to;
        return;
    }
    const start = performance.now();
    const duration = Math.min(1000, Math.max(1, props.duration));
    const tick = (now: number) => {
        const progress = Math.min(1, (now - start) / duration);
        current.value = from + (to - from) * (1 - (1 - progress) ** 3);
        if (progress < 1) frame = window.requestAnimationFrame(tick);
        else current.value = to;
    };
    frame = window.requestAnimationFrame(tick);
}
const reduceMotion = () => {
    if (media?.matches) {
        window.cancelAnimationFrame(frame);
        current.value = props.value;
    }
};
onMounted(() => {
    mounted = true;
    media = window.matchMedia('(prefers-reduced-motion: reduce)');
    media.addEventListener('change', reduceMotion);
    update(0, props.value);
});
watch(
    () => props.value,
    (value) => {
        if (mounted) update(current.value, value);
        else current.value = value;
    },
);
onBeforeUnmount(() => {
    window.cancelAnimationFrame(frame);
    media?.removeEventListener('change', reduceMotion);
});
</script>

<template>
    <span class="tabular-nums"
        ><span aria-hidden="true">{{ formatted(current) }}</span
        ><span class="sr-only">{{ actual }}</span></span
    >
</template>
