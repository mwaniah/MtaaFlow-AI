<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { edit } from '@/routes/profile';
</script>
<template>
    <div class="p-7">
        <h1 class="font-display text-2xl font-bold">{{ t('nav.settings') }}</h1>
        <Link :href="edit()" class="text-[#15966b] underline">{{
            t('interface.manage_profile_security_and_appearance')
        }}</Link>
        <p class="mt-3">
            {{
                t(
                    'interface.business_branding_and_invoice_defaults_are_not_supported_by_the_current_backend',
                )
            }}
        </p>
    </div>
</template>
