<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthSplit from '@/components/AuthSplit.vue';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
const show = ref(false);

function submit() {
    // Figma has a single password field, so the confirmation Laravel expects is sent as a copy of it.
    form
        .transform((d) => ({ ...d, password_confirmation: d.password }))
        .post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
}

const input = 'w-full rounded-lg border-[1.5px] bg-white px-3 py-[11px] text-sm text-[#202a27] outline-none focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/20';
</script>

<template>
    <Head title="Create account" />
    <AuthSplit>
        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">Create your account</h1>
                <p class="text-sm text-[#6b7773]">Start managing your freelance business.</p>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-xs text-[#6b7773]">Full name</label>
                <input id="name" v-model="form.name" type="text" autocomplete="name" autofocus required placeholder="Wanjiru Mwangi"
                    :class="[input, form.errors.name ? 'border-[#b4432f]' : 'border-[#e6e4dc]']" />
                <p v-if="form.errors.name" class="text-xs text-[#b4432f]" role="alert">{{ form.errors.name }}</p>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-xs text-[#6b7773]">Email</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required placeholder="you@example.com"
                    :class="[input, form.errors.email ? 'border-[#b4432f]' : 'border-[#e6e4dc]']" />
                <p v-if="form.errors.email" class="text-xs text-[#b4432f]" role="alert">{{ form.errors.email }}</p>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-xs text-[#6b7773]">Password</label>
                <div class="relative">
                    <input id="password" v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="new-password" required minlength="8" placeholder="••••••••••"
                        :class="[input, 'pr-16', form.errors.password ? 'border-[#b4432f]' : 'border-[#e6e4dc]']" />
                    <button type="button" class="absolute inset-y-0 right-3 text-xs font-semibold text-[#6b7773] hover:text-[#202a27]" :aria-pressed="show" @click="show = !show">
                        {{ show ? 'Hide' : 'Show' }}
                    </button>
                </div>
                <p v-if="form.errors.password" class="text-xs text-[#b4432f]" role="alert">{{ form.errors.password }}</p>
                <p v-else class="text-xs text-[#6b7773]">Use at least 8 characters.</p>
            </div>

            <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-60">
                {{ form.processing ? 'Creating account…' : 'Create account' }}
            </button>

            <p class="text-xs text-[#6b7773]">
                Already have an account? <Link href="/login" class="font-semibold text-[#0d6b4a] underline-offset-2 hover:underline">Log in</Link>
            </p>
        </form>
    </AuthSplit>
</template>
