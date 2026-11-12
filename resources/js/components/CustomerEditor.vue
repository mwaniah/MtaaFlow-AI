<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { Check, LoaderCircle, Save, UserRound } from '@lucide/vue';
import { store, update } from '@/routes/customers';
import type { CustomerRecord } from '@/types/business';

const props = defineProps<{ customer?: CustomerRecord }>();
const emit = defineEmits<{ saved: [] }>();
const form = useForm({
    name: props.customer?.name ?? '',
    email: props.customer?.email ?? '',
    phone: props.customer?.phone ?? '',
    address: props.customer?.address ?? '',
});
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (!props.customer) form.reset();
            emit('saved');
        },
    };
    if (props.customer) form.put(update.url(props.customer.id), options);
    else form.post(store.url(), options);
}
</script>

<template>
    <form class="mf-card overflow-hidden" @submit.prevent="save">
        <header
            class="flex items-start gap-3 border-b border-line/80 px-5 py-5 sm:px-6 dark:border-border/80"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-mint text-brand-emerald-dark dark:bg-secondary dark:text-[#8bdbb4]"
                ><UserRound
                    class="size-[18px]"
                    :stroke-width="1.5"
                    aria-hidden="true"
            /></span>
            <div>
                <h2
                    class="font-display text-xl font-bold tracking-tight text-brand-forest dark:text-foreground"
                >
                    {{
                        customer
                            ? t('customers.contact')
                            : t('customers.connection')
                    }}
                </h2>
                <p
                    class="mt-1 text-xs leading-relaxed text-ink-muted dark:text-muted-foreground"
                >
                    {{
                        customer
                            ? t(
                                  'interface.update_the_information_you_use_to_work_together',
                              )
                            : t(
                                  'interface.add_a_customer_to_start_creating_their_invoices',
                              )
                    }}
                </p>
            </div>
        </header>
        <div class="grid gap-x-5 gap-y-5 p-5 sm:grid-cols-2 sm:p-6">
            <div
                v-for="field in ['name', 'email', 'phone', 'address'] as const"
                :key="field"
                class="flex min-w-0 flex-col gap-2"
            >
                <label
                    :for="`customer-${customer?.id ?? 'new'}-${field}`"
                    class="text-xs font-semibold text-brand-forest capitalize dark:text-foreground"
                    >{{ t('common.' + field)
                    }}<span
                        v-if="field === 'name' || field === 'phone'"
                        class="ml-1 text-brand-emerald-dark dark:text-[#8bdbb4]"
                        aria-hidden="true"
                        >*</span
                    ></label
                >
                <input
                    :id="`customer-${customer?.id ?? 'new'}-${field}`"
                    v-model="form[field]"
                    :type="
                        field === 'email'
                            ? 'email'
                            : field === 'phone'
                              ? 'tel'
                              : 'text'
                    "
                    :required="field === 'name' || field === 'phone'"
                    :aria-invalid="Boolean(form.errors[field])"
                    :aria-describedby="
                        form.errors[field]
                            ? `customer-${customer?.id ?? 'new'}-${field}-error`
                            : undefined
                    "
                    :autocomplete="
                        field === 'name'
                            ? 'name'
                            : field === 'email'
                              ? 'email'
                              : field === 'phone'
                                ? 'tel'
                                : 'street-address'
                    "
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 py-3 text-sm text-brand-forest transition-colors placeholder:text-ink-muted/70 focus:border-brand-emerald focus:ring-3 focus:ring-brand-emerald/10 focus:outline-none dark:border-border dark:bg-card dark:text-foreground"
                    :class="form.errors[field] ? 'border-[#b4432f]/60' : ''"
                />
                <span
                    v-if="form.errors[field]"
                    :id="`customer-${customer?.id ?? 'new'}-${field}-error`"
                    class="text-xs leading-relaxed text-[#b4432f]"
                    role="alert"
                    >{{ form.errors[field] }}</span
                >
            </div>
        </div>
        <footer
            class="flex flex-wrap items-center justify-between gap-4 border-t border-line/80 bg-canvas/60 px-5 py-4 sm:px-6 dark:border-border/80 dark:bg-background/40"
        >
            <p
                v-if="form.recentlySuccessful"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-emerald-dark dark:text-[#8bdbb4]"
                role="status"
            >
                <Check class="size-4" aria-hidden="true" />{{
                    t('interface.customer_saved')
                }}
            </p>
            <p v-else class="text-xs text-ink-muted dark:text-muted-foreground">
                <span
                    class="font-semibold text-brand-emerald-dark dark:text-[#8bdbb4]"
                    aria-hidden="true"
                    >*</span
                >{{ t('interface.required_fields') }}
            </p>
            <button
                :disabled="form.processing"
                class="mf-button w-full sm:w-auto"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 motion-safe:animate-spin"
                    aria-hidden="true"
                /><Save v-else class="size-4" aria-hidden="true" />{{
                    form.processing
                        ? t('interface.saving')
                        : t('interface.save_customer')
                }}
            </button>
        </footer>
    </form>
</template>
