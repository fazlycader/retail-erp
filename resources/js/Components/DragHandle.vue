<script setup>
import Icon from '@/Components/Icon.vue';

defineProps({
    disabled: Boolean,
});
</script>

<template>
    <span
        :data-drag-handle="disabled ? undefined : ''"
        class="flex size-8 shrink-0 touch-none items-center justify-center rounded-md text-gray-400"
        :class="disabled ? 'cursor-not-allowed opacity-40' : 'cursor-grab hover:bg-gray-100 hover:text-gray-600 active:cursor-grabbing'"
        :title="disabled ? 'Clear search and filters to reorder' : 'Drag to reorder'"
    >
        <Icon name="grip" class="size-5" />
        <span class="sr-only">Drag to reorder</span>
    </span>
</template>
