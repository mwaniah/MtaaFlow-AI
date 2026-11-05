<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BarChart3, FileText, LayoutGrid, Package, Plus, Sparkles, Users, Wallet } from '@lucide/vue';
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
import { index as askIndex } from '@/routes/ask';
import { index as customersIndex } from '@/routes/customers';
import { index as insightsIndex } from '@/routes/insights';
import { index as inventoryIndex } from '@/routes/inventory';
import { create as invoicesCreate, index as invoicesIndex } from '@/routes/invoices';
import { index as paymentsIndex } from '@/routes/payments';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'New Invoice', href: invoicesCreate(), icon: Plus },
    { title: 'Invoices', href: invoicesIndex(), icon: FileText },
    { title: 'Customers', href: customersIndex(), icon: Users },
    { title: 'Payments', href: paymentsIndex(), icon: Wallet },
    { title: 'Inventory', href: inventoryIndex(), icon: Package },
    { title: 'Ask MtaaFlow', href: askIndex(), icon: Sparkles },
    { title: 'Insights & Reports', href: insightsIndex(), icon: BarChart3 },
];

const footerNavItems: NavItem[] = [];
</script>