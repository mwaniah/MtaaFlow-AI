<script setup lang="ts">
import { h } from 'vue';
import { renderAssistantMarkdown } from '@/lib/assistantMarkdown';

const props = defineProps<{ text: string }>();
const Content = () =>
    h('div', { class: 'assistant-prose' }, renderAssistantMarkdown(props.text));
</script>

<template>
    <Content />
</template>

<style scoped>
.assistant-prose {
    min-width: 0;
    font-size: 0.875rem;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.assistant-prose :deep(> * + *) {
    margin-top: 0.85rem;
}
.assistant-prose :deep(h3) {
    font-weight: 650;
    font-size: 1rem;
    line-height: 1.5;
}
.assistant-prose :deep(strong) {
    font-weight: 650;
}
.assistant-prose :deep(ul),
.assistant-prose :deep(ol) {
    padding-left: 1.5rem;
}
.assistant-prose :deep(ul) {
    list-style: disc;
}
.assistant-prose :deep(ol) {
    list-style: decimal;
}
.assistant-prose :deep(li + li) {
    margin-top: 0.4rem;
}
.assistant-prose :deep(a) {
    color: var(--mf-action);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.assistant-prose :deep(code) {
    border-radius: 0.35rem;
    background: var(--secondary);
    padding: 0.12rem 0.3rem;
    font-size: 0.85em;
}
.assistant-prose :deep(pre) {
    max-width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 0.85rem;
    background: var(--secondary);
    padding: 1rem;
    line-height: 1.7;
}
.assistant-prose :deep(pre code) {
    padding: 0;
    background: transparent;
    white-space: pre;
}
:global(.dark) .assistant-prose :deep(a) {
    color: #8bdbb4;
}
</style>
