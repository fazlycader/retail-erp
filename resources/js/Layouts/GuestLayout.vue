<script setup>
import CompanyMark from '@/Components/CompanyMark.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const company = computed(() => usePage().props.company);
const brand = computed(() => usePage().props.brand);
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-10">
        <div class="flex flex-col items-center gap-3">
            <CompanyMark size="size-14" />
            <p class="text-lg font-semibold text-gray-900">{{ company?.name }}</p>
        </div>

        <div class="mt-6 w-full rounded-xl border border-gray-200 bg-white px-6 py-6 shadow-xs sm:max-w-md">
            <slot />
        </div>

        <p class="mt-6 text-xs text-gray-400">Staff only. Ask a manager if you need an account.</p>
        <p v-if="brand?.enabled" class="mt-2 text-center text-xs text-gray-400">
            {{ brand.product }} by <a :href="brand.website_url" target="_blank" rel="noopener" class="font-semibold text-gray-500 hover:underline">{{ brand.name }}</a>
            · Support <a :href="brand.whatsapp_url" target="_blank" rel="noopener" class="font-semibold text-gray-500 hover:underline">{{ brand.whatsapp }}</a>
        </p>
    </div>
</template>
