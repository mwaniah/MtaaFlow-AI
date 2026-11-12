<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        get title() {
            return t('interface.email_verification_disabled');
        },
        get description() {
            return t(
                'interface.email_verification_is_not_required_to_use_your_account',
            );
        },
    },
});
</script>

<template>
    <div>
        <Head :title="t('interface.email_verification_disabled')" />
        <p>
            {{
                t(
                    'interface.email_verification_is_not_required_to_use_your_account',
                )
            }}
        </p>
    </div>
</template>
