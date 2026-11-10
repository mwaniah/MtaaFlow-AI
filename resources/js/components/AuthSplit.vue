<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="grid min-h-screen bg-white lg:grid-cols-[4fr_5fr]">
        <aside class="hidden flex-col justify-between px-16 py-14 lg:flex" style="background-image: linear-gradient(102deg, #123d32 0%, #1b5b49 76.9%)">
            <Link href="/" class="flex items-center gap-2.5">
                <span class="size-[34px] rounded-tl-[10px] rounded-tr-[10px] rounded-br-[10px] rounded-bl-[2px]" style="background-image: linear-gradient(107deg, #15966b 0%, #7be0b6 76.9%)" />
                <span class="font-display text-xl font-bold tracking-[-0.01em] text-white">MtaaFlow AI</span>
            </Link>
            <div class="flex flex-col gap-3.5">
                <h2 class="font-display text-[40px] leading-[1.15] font-bold tracking-[-0.02em] text-white">Describe the sale. Get paid.</h2>
                <p class="max-w-[480px] text-sm leading-[1.45] text-[#a9cdbf]">Create invoices in plain English, collect payments with PayPal and see how your business is doing.</p>
            </div>
            <p class="text-xs text-[#7bc9a7]">Built for small businesses</p>
        </aside>

        <main class="flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-[380px]">
                <Link href="/" class="mb-8 flex items-center gap-2.5 lg:hidden">
                    <span class="size-[30px] rounded-tl-[9px] rounded-tr-[9px] rounded-br-[9px] rounded-bl-[2px]" style="background-image: linear-gradient(107deg, #15966b 0%, #7be0b6 76.9%)" />
                    <span class="font-display text-xl font-bold tracking-[-0.01em] text-[#123d32]">MtaaFlow AI</span>
                </Link>
                <slot />
            </div>
        </main>
    </div>
</template>
