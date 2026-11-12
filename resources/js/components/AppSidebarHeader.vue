<script setup lang="ts">
import LanguageSelector from '@/components/LanguageSelector.vue';
import { translate as t } from '@/lib/i18n';
import { Link, usePage } from '@inertiajs/vue3';
import { Plus, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { index as askIndex } from '@/routes/ask';
import { create as createInvoice } from '@/routes/invoices';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});
const page = usePage();
const section = computed(
    () =>
        ({
            Dashboard: 'Your workspace',
            Invoices: t('nav.invoicing'),
            Customers: t('nav.customers'),
            Inventory: t('nav.inventory'),
            Payments: t('nav.payments'),
            Reports: t('nav.reports'),
            AskMtaaFlow: t('nav.aiWorkspace'),
            settings: t('nav.accountSettings'),
        })[page.component.split('/')[0]] ?? 'Your workspace',
);
</script>

<template>
    <header
        data-app-chrome
        class="mf-topbar sticky top-0 z-20 flex min-h-20 shrink-0 items-center justify-between gap-3 border-b border-border/70 px-4 sm:px-6 lg:px-8"
    >
        <div class="flex min-w-0 items-center gap-3">
            <SidebarTrigger
                class="-ml-1 size-10 rounded-xl border border-border/80 bg-card/70 text-foreground"
            />
            <div class="min-w-0">
                <Breadcrumbs
                    v-if="breadcrumbs.length"
                    :breadcrumbs="breadcrumbs"
                />
                <template v-else>
                    <p class="mf-kicker hidden sm:block">MtaaFlow AI</p>
                    <p class="truncate text-sm font-semibold text-foreground">
                        {{ section }}
                    </p>
                </template>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <LanguageSelector compact />
            <div class="hidden sm:block">
                <Link
                    :href="askIndex()"
                    class="mf-button-secondary min-h-10 gap-2 px-3"
                    :aria-label="t('interface.open_ask_mtaaflow')"
                >
                    <Sparkles
                        class="size-4 text-[#0f7d58] dark:text-primary"
                        aria-hidden="true"
                    /><span class="hidden lg:inline">Ask MtaaFlow</span>
                </Link>
            </div>
            <Link
                :href="createInvoice()"
                :aria-label="t('invoices.new')"
                class="mf-button min-h-10 gap-2 px-3.5 text-xs sm:text-sm"
            >
                <Plus class="size-4" aria-hidden="true" /><span
                    class="hidden sm:inline"
                    >{{ t('invoices.new') }}</span
                >
            </Link>
        </div>
    </header>
</template>
