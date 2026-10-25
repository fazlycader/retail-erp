<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Small "Powered by MVRK+ · mvrk.lk" credit (hidden when the credit is switched off). */
defineProps({ dark: Boolean });

const brand = computed(() => usePage().props.brand);
</script>

<template>
    <p v-if="brand?.enabled" class="py-4 text-center text-xs" :class="dark ? 'text-gray-500' : 'text-gray-400'">
        Powered by
        <a :href="brand.website_url" target="_blank" rel="noopener" class="font-semibold underline-offset-2 hover:underline" :class="dark ? 'text-gray-400' : 'text-gray-500'">{{ brand.name }}</a>
        · {{ brand.website }}
    </p>
</template>
