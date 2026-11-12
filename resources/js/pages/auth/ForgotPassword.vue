<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        get title() {
            return t('interface.forgot_password');
        },
        get description() {
            return t(
                'interface.enter_your_email_to_receive_a_password_reset_link',
            );
        },
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('interface.forgot_password')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ t('common.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    :placeholder="t('interface.email_example_com')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />{{
                        t('interface.email_password_reset_link')
                    }}</Button
                >
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ t('interface.or_return_to') }}</span>
            <TextLink :href="login()">{{ t('interface.log_in') }}</TextLink>
        </div>
    </div>
</template>
