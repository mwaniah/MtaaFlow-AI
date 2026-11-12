<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
defineProps<{ label?: string }>();
</script>

<template>
    <div
        class="assistant-thinking"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <span class="assistant-thinking__dots" aria-hidden="true">
            <span
                v-for="dot in 3"
                :key="dot"
                class="assistant-thinking__dot"
                :style="{ animationDelay: `${(dot - 1) * 160}ms` }"
            />
        </span>
        <span class="text-xs font-medium text-muted-foreground"
            >{{ label ?? t('ask.thinking')
            }}<span aria-hidden="true">…</span></span
        >
    </div>
</template>

<style scoped>
.assistant-thinking {
    display: inline-flex;
    min-height: 3.4rem;
    align-items: center;
    gap: 0.85rem;
    border: 1px solid rgb(21 150 107 / 14%);
    border-radius: 1.25rem;
    border-top-left-radius: 0.3rem;
    background: rgb(229 244 237 / 65%);
    padding: 1rem 1.2rem;
    box-shadow: 0 7px 25px -18px rgb(21 150 107 / 55%);
    backdrop-filter: blur(14px);
}
.assistant-thinking__dots {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.assistant-thinking__dot {
    height: 0.4rem;
    width: 0.4rem;
    border-radius: 999px;
    background: #15966b;
    box-shadow: 0 0 7px rgb(21 150 107 / 20%);
    animation: assistant-dot-wave 1.3s ease-in-out infinite;
}
@keyframes assistant-dot-wave {
    0%,
    65%,
    100% {
        opacity: 0.48;
        transform: translateY(0);
    }
    30% {
        opacity: 1;
        transform: translateY(-3px);
    }
}
:global(.dark) .assistant-thinking {
    background: rgb(18 61 50 / 70%);
    border-color: rgb(139 219 180 / 18%);
}
:global(.dark) .assistant-thinking__dot {
    background: #8bdbb4;
}
@media (prefers-reduced-motion: reduce) {
    .assistant-thinking__dot {
        animation: none;
        opacity: 1;
        transform: none;
    }
}
</style>
