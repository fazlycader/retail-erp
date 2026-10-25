<script setup>
defineProps({
    title: String,
    description: String,
});
</script>

<template>
    <section class="rounded-xl border border-gray-200 bg-white shadow-xs">
        <header v-if="title || $slots.actions" class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-sm text-gray-500">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div class="p-5">
            <slot />
        </div>
    </section>
</template>
