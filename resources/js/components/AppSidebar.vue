<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    BarChart3,
    FileText,
    LayoutGrid,
    Package,
    Settings,
    Sparkles,
    Users,
    Wallet,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as customersIndex } from '@/routes/customers';
import { index as invoicesIndex } from '@/routes/invoices';
import { index as paymentsIndex } from '@/routes/payments';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as askIndex } from '@/routes/ask';
import { index as reportsIndex } from '@/routes/reports';
import { edit as profileEdit } from '@/routes/profile';
import type { NavItem } from '@/types';

const mainNavItems = computed<NavItem[]>(() => [
    { title: t('nav.dashboard'), href: dashboard(), icon: LayoutGrid },
    { title: t('nav.invoices'), href: invoicesIndex(), icon: FileText },
    { title: t('nav.customers'), href: customersIndex(), icon: Users },
    { title: t('nav.payments'), href: paymentsIndex(), icon: Wallet },
    { title: t('nav.inventory'), href: inventoryIndex(), icon: Package },
    { title: 'Ask MtaaFlow', href: askIndex(), icon: Sparkles },
    { title: t('nav.reports'), href: reportsIndex(), icon: BarChart3 },
    { title: t('nav.settings'), href: profileEdit(), icon: Settings },
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        class="mf-sidebar"
        data-app-chrome
    >
        <SidebarHeader
            class="px-3 pt-5 pb-6 group-data-[collapsible=icon]:px-1.5"
        >
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-6">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter
            class="mx-3 mb-3 rounded-2xl border border-white/10 bg-white/5 p-1 group-data-[collapsible=icon]:mx-0.5"
        >
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
