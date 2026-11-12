<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, LockKeyhole, Mail, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import AuthSplit from '@/components/AuthSplit.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const show = ref(false);

function submit() {
    form.transform((data) => ({
        ...data,
        password_confirmation: data.password,
    })).post(store.url(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

const input =
    'min-h-12 w-full rounded-xl border bg-white py-3 pl-11 pr-3 text-sm text-[#202a27] shadow-sm shadow-[#123d32]/3 outline-none transition-[border-color,box-shadow] placeholder:text-[#6b7773] focus:border-[#15966b] focus:ring-4 focus:ring-[#15966b]/10';
</script>

<template>
    <AuthSplit>
        <Head :title="t('auth.create')" />
        <form
            class="flex flex-col gap-5"
            :aria-busy="form.processing"
            @submit.prevent="submit"
        >
            <div class="mb-1">
                <p class="mf-kicker">
                    {{ t('interface.a_fresh_start_for_your_business') }}
                </p>
                <h1
                    class="mt-3 font-display text-[38px] leading-[1.08] font-semibold tracking-[-0.045em] text-[#123d32] sm:text-[44px]"
                >
                    {{ t('interface.find_your_flow') }}
                </h1>
                <p class="mt-3 text-sm leading-relaxed text-[#53645c]">
                    {{
                        t(
                            'interface.create_your_account_and_make_space_for_your_next_chapter',
                        )
                    }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <label
                    for="name"
                    class="text-xs font-semibold text-[#364a40]"
                    >{{ t('customers.fullName') }}</label
                >
                <div class="relative">
                    <UserRound
                        class="pointer-events-none absolute top-3.5 left-3.5 size-4 text-[#6b7773]"
                        aria-hidden="true"
                    /><input
                        id="name"
                        v-model="form.name"
                        name="name"
                        type="text"
                        autocomplete="name"
                        autofocus
                        required
                        :placeholder="t('interface.your_full_name')"
                        :aria-invalid="!!form.errors.name"
                        :aria-describedby="
                            form.errors.name ? 'name-error' : undefined
                        "
                        :class="[
                            input,
                            form.errors.name
                                ? 'border-[#b4432f]'
                                : 'border-[#dce3da]',
                        ]"
                    />
                </div>
                <p
                    v-if="form.errors.name"
                    id="name-error"
                    class="text-xs leading-relaxed text-[#b4432f]"
                    role="alert"
                >
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
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
                    />
                    <input
                        id="password"
                        v-model="form.password"
                        name="password"
                        :type="show ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        :placeholder="t('interface.create_a_password')"
                        :aria-invalid="!!form.errors.password"
                        aria-describedby="password-help"
                        :class="[
                            input,
                            'pr-16',
                            form.errors.password
                                ? 'border-[#b4432f]'
                                : 'border-[#dce3da]',
                        ]"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-1 min-w-14 rounded-lg px-2 text-xs font-semibold text-[#53645c] hover:text-[#123d32]"
                        :aria-pressed="show"
                        :aria-label="
                            show
                                ? t('interface.hide_password')
                                : t('interface.show_password')
                        "
                        @click="show = !show"
                    >
                        {{ show ? t('interface.hide') : t('interface.show') }}
                    </button>
                </div>
                <p
                    v-if="form.errors.password"
                    id="password-help"
                    class="text-xs leading-relaxed text-[#b4432f]"
                    role="alert"
                >
                    {{ form.errors.password }}
                </p>
                <p v-else id="password-help" class="text-xs text-[#6b7773]">
                    {{ t('interface.use_at_least_8_characters') }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="mf-button mt-1 inline-flex min-h-12 w-full items-center justify-center gap-3 rounded-xl px-5 py-3 text-sm font-semibold disabled:cursor-wait disabled:opacity-60"
            >
                {{
                    form.processing
                        ? t('interface.creating_account')
                        : t('auth.create')
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

            <p class="text-center text-sm leading-relaxed text-[#53645c]">
                {{ t('interface.already_have_an_account')
                }}<Link
                    :href="login()"
                    class="font-semibold text-[#0f7d58] underline decoration-[#15966b]/35 underline-offset-4 hover:decoration-current"
                    >{{ t('auth.login') }}</Link
                >
            </p>
        </form>
    </AuthSplit>
</template>
