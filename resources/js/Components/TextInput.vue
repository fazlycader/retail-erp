<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: [String, Number],
    default: '',
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-lg border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500"
        v-model="model"
        ref="input"
    />
</template>
