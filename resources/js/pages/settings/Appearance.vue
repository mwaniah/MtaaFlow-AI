<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                get title() {
                    return t('interface.appearance_settings');
                },
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="t('interface.appearance_settings')" />

    <h1 class="sr-only">{{ t('interface.appearance_settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('interface.appearance_settings')"
            :description="
                t('interface.update_the_appearance_settings_for_your_account')
            "
        />
        <AppearanceTabs />
    </div>
</template>
