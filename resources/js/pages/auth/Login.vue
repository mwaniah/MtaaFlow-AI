<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, LockKeyhole, Mail } from '@lucide/vue';
import AuthSplit from '@/components/AuthSplit.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';

withDefaults(
    defineProps<{
        status?: string;
        canResetPassword?: boolean;
        canRegister?: boolean;
    }>(),
    { canRegister: true },
);

const form = useForm({ email: '', password: '' });

function submit() {
    form.post(store.url(), { onFinish: () => form.reset('password') });
}

const input =
    'min-h-12 w-full rounded-xl border bg-white py-3 pl-11 pr-3 text-sm text-[#202a27] shadow-sm shadow-[#123d32]/3 outline-none transition-[border-color,box-shadow] placeholder:text-[#6b7773] focus:border-[#15966b] focus:ring-4 focus:ring-[#15966b]/10';
</script>

<template>
    <AuthSplit>
        <Head :title="t('auth.login')" />
        <form
            class="flex flex-col gap-6"
            :aria-busy="form.processing"
            @submit.prevent="submit"
        >
            <div>
                <p class="mf-kicker">
                    {{ t('interface.your_workspace_awaits') }}
                </p>
                <h1
                    class="mt-3 font-display text-[38px] leading-[1.08] font-semibold tracking-[-0.045em] text-[#123d32] sm:text-[44px]"
                >
                    {{ t('auth.welcome') }}
                </h1>
                <p class="mt-3 text-sm leading-relaxed text-[#53645c]">
                    {{
                        t(
                            'interface.pick_up_where_you_left_off_log_in_to_manage_your_business',
                        )
                    }}
                </p>
            </div>

            <p
                v-if="status"
                class="rounded-xl border border-[#15966b]/15 bg-[#e5f4ed] px-4 py-3 text-sm leading-relaxed text-[#0d6b4a]"
                role="status"
            >
                {{ status }}
            </p>

            <div class="mt-1 flex flex-col gap-2">
                <label
                    for="email"
                    class="text-xs font-semibold text-[#364a40]"
                    >{{ t('common.email') }}</label
                >
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute top-3.5 left-3.5 size-4 text-[#6b7773]"
                        aria-hidden="true"
                    /><input
                        id="email"
                        v-model="form.email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        autofocus
                        required
                        :placeholder="t('interface.you_example_com')"
                        :aria-invalid="!!form.errors.email"
                        :aria-describedby="
                            form.errors.email ? 'email-error' : undefined
                        "
                        :class="[
                            input,
                            form.errors.email
                                ? 'border-[#b4432f]'
                                : 'border-[#dce3da]',
                        ]"
                    />
                </div>
                <p
                    v-if="form.errors.email"
                    id="email-error"
                    class="text-xs leading-relaxed text-[#b4432f]"
                    role="alert"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <label
                    for="password"
                    class="text-xs font-semibold text-[#364a40]"
                    >{{ t('auth.password') }}</label
                >
                <div class="relative">
                    <LockKeyhole
                        class="pointer-events-none absolute top-3.5 left-3.5 size-4 text-[#6b7773]"
                        aria-hidden="true"
                    /><input
                        id="password"
                        v-model="form.password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :placeholder="t('interface.enter_your_password')"
                        :aria-invalid="!!form.errors.password"
                        :aria-describedby="
                            form.errors.password ? 'password-error' : undefined
                        "
                        :class="[
                            input,
                            form.errors.password
                                ? 'border-[#b4432f]'
                                : 'border-[#dce3da]',
                        ]"
                    />
                </div>
                <p
                    v-if="form.errors.password"
                    id="password-error"
                    class="text-xs leading-relaxed text-[#b4432f]"
                    role="alert"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="mf-button mt-1 inline-flex min-h-12 w-full items-center justify-center gap-3 rounded-xl px-5 py-3 text-sm font-semibold disabled:cursor-wait disabled:opacity-60"
            >
                {{
                    form.processing
                        ? t('interface.logging_in')
                        : t('auth.login')
                }}
                <ArrowRight
                    v-if="!form.processing"
                    class="size-4"
                    aria-hidden="true"
                /><span
                    v-else
                    class="size-4 animate-pulse rounded-full border-2 border-white/50"
                    aria-hidden="true"
                />
            </button>

            <p
                v-if="canRegister !== false"
                class="text-center text-sm leading-relaxed text-[#53645c]"
            >
                {{ t('interface.new_to_mtaaflow')
                }}<Link
                    :href="register()"
                    class="font-semibold text-[#0f7d58] underline decoration-[#15966b]/35 underline-offset-4 hover:decoration-current"
                    >{{ t('interface.create_a_business_account') }}</Link
                >
            </p>
        </form>
    </AuthSplit>
</template>
