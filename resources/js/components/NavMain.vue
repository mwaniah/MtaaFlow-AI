<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();
</script>

<template>
    <SidebarGroup class="px-3 py-0 group-data-[collapsible=icon]:px-1.5">
        <SidebarGroupLabel
            class="mb-2 px-3 text-[10px] font-semibold tracking-[0.18em] uppercase"
            >{{ t('nav.workspace') }}</SidebarGroupLabel
        >
        <SidebarMenu class="gap-1.5">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    class="h-11 rounded-xl px-3 text-[13px] font-medium"
                    :tooltip="item.title"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
                        "
                        @click="setOpenMobile(false)"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
