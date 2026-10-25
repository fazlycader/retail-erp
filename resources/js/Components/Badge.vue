<script setup>
defineProps({
    color: {
        type: String,
        default: 'gray',
    },
});

const colors = {
    gray: 'bg-gray-100 text-gray-700 ring-gray-500/20',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    red: 'bg-red-50 text-red-700 ring-red-600/20',
    amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    brand: 'bg-brand-50 text-brand-700 ring-brand-600/20',
    blue: 'bg-blue-50 text-blue-700 ring-blue-600/20',
};
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="colors[color]">
        <slot />
    </span>
</template>
