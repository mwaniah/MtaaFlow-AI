<script setup lang="ts">
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { update } from '@/routes/language';
import { requestJson } from '@/lib/api';
import { setLocale } from '@/lib/i18n';
import { isLocale } from '@/lib/locale';

const { t, locale } = useI18n();
const page = usePage();
defineProps<{ compact?: boolean }>();
const inputId = useId();
const saving = ref(false);
const error = ref('');
async function change(event: Event) {
    const selected = (event.target as HTMLSelectElement).value;
    if (!isLocale(selected)) return;
    const previous = locale.value;
    setLocale(selected, true);
    error.value = '';
    if (!page.props.auth?.user) return;
    saving.value = true;
    try {
        await requestJson(update(), { locale: selected });
        Object.assign(page.props.auth.user, { locale: selected });
    } catch (failure) {
        if (isLocale(previous)) setLocale(previous, true);
        error.value =
            failure instanceof Error ? failure.message : t('common.error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-2">
        <label
            :for="inputId"
            :class="compact ? 'sr-only' : 'text-sm font-medium'"
            >{{ t('settings.language') }}</label
        >
        <select
            :id="inputId"
            :value="locale"
            :disabled="saving"
            class="mf-input block w-full max-w-xs rounded-lg border border-border bg-background px-3 py-2 text-foreground"
            @change="change"
        >
            <option value="en">English</option>
            <option value="fr">Français</option>
            <option value="de">Deutsch</option>
            <option value="es">Español</option>
        </select>
        <p v-if="!compact" class="text-xs text-muted-foreground">
            {{ t('settings.languageHint') }}
        </p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
