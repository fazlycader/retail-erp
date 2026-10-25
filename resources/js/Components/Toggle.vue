<script setup>
const model = defineModel({ type: Boolean, default: false });

defineProps({
    disabled: Boolean,
});
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :disabled="disabled"
        class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        :class="model ? 'bg-brand-600' : 'bg-gray-200'"
        @click="model = !model"
    >
        <span
            aria-hidden="true"
            class="pointer-events-none inline-block size-5 rounded-full bg-white shadow ring-0 transition"
            :class="model ? 'translate-x-5' : 'translate-x-0'"
        />
    </button>
</template>
