<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthSplit from '@/components/AuthSplit.vue';

// These props come from the starter kit's login view; declared so they don't fall through as HTML attributes.
defineProps<{ status?: string; canResetPassword?: boolean; canRegister?: boolean }>();

const form = useForm({ email: '', password: '' });

function submit() {
    // TODO: switch to the generated route helper once routes are final.
    form.post('/login', { onFinish: () => form.reset('password') });
}

const input = 'w-full rounded-lg border-[1.5px] bg-white px-3 py-[11px] text-sm text-[#202a27] outline-none focus:border-[#15966b] focus:ring-2 focus:ring-[#15966b]/20';
</script>

<template>
    <Head title="Log in" />
    <AuthSplit>
        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <h1 class="font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em] text-[#202a27]">Welcome back</h1>
                <p class="text-sm text-[#6b7773]">Log in to manage your business.</p>
            </div>

            <p v-if="status" class="rounded-lg bg-[#e5f4ed] px-3 py-2 text-sm text-[#0d6b4a]" role="status">{{ status }}</p>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-xs text-[#6b7773]">Email</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" autofocus required placeholder="you@example.com"
                    :class="[input, form.errors.email ? 'border-[#b4432f]' : 'border-[#e6e4dc]']" />
                <p v-if="form.errors.email" class="text-xs text-[#b4432f]" role="alert">{{ form.errors.email }}</p>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-xs text-[#6b7773]">Password</label>
                <input id="password" v-model="form.password" type="password" autocomplete="current-password" required placeholder="••••••••••"
                    :class="[input, form.errors.password ? 'border-[#b4432f]' : 'border-[#e6e4dc]']" />
                <p v-if="form.errors.password" class="text-xs text-[#b4432f]" role="alert">{{ form.errors.password }}</p>
            </div>

            <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-[#15966b] px-[18px] py-2.5 text-sm font-semibold text-white disabled:opacity-60">
                {{ form.processing ? 'Logging in…' : 'Log in' }}
            </button>

            <p v-if="canRegister !== false" class="text-xs text-[#6b7773]">
                New to MtaaFlow? <Link href="/register" class="font-semibold text-[#0d6b4a] underline-offset-2 hover:underline">Create a business account</Link>
            </p>
        </form>
    </AuthSplit>
</template>
