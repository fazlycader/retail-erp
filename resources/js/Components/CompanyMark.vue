<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    size: {
        type: String,
        default: 'size-9',
    },
});

const company = computed(() => usePage().props.company);

const initials = computed(() =>
    (company.value?.name ?? 'C')
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <img
        v-if="company?.logo_url"
        :src="company.logo_url"
        :alt="company.name"
        class="shrink-0 rounded-lg bg-white object-contain"
        :class="size"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-lg bg-brand-500 text-sm font-bold text-white"
        :class="size"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
