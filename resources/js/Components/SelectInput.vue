<script setup>
const model = defineModel({ type: [String, Number, null], default: '' });
</script>

<template>
    <select
        v-model="model"
        class="rounded-lg border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500"
    >
        <slot />
    </select>
</template>
