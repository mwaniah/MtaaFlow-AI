<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BarChart3, FileText, LayoutGrid, Package, Plus, Settings, Sparkles, Users, Wallet } from '@lucide/vue';
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
import { create as invoicesCreate, index as invoicesIndex } from '@/routes/invoices';
import { index as paymentsIndex } from '@/routes/payments';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'New Invoice', href: invoicesCreate(), icon: Plus },
    { title: 'Invoices', href: invoicesIndex(), icon: FileText },
    { title: 'Customers', href: customersIndex(), icon: Users },
    { title: 'Payments', href: paymentsIndex(), icon: Wallet },
    { title: 'Inventory', href: '/inventory', icon: Package },
    { title: 'Ask MtaaFlow', href: '/ask', icon: Sparkles },
    { title: 'Insights & Reports', href: '/reports', icon: BarChart3 },
    // Plain URL until the backend adds a named route; then swap for a Wayfinder import like the rest
    { title: 'Business Settings', href: '/settings', icon: Settings },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
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

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>