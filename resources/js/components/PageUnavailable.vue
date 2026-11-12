<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    title: string;
    description: string;
    back: BreadcrumbItem;
}>();
</script>

<template>
    <div class="flex flex-col gap-5 p-7">
        <Head :title="title" />
        <section
            class="rounded-2xl border border-border bg-card p-6 text-card-foreground"
        >
            <Heading :title="title" :description="description" />
            <Button as-child variant="outline">
                <Link :href="back.href">{{ back.title }}</Link>
            </Button>
        </section>
    </div>
</template>
