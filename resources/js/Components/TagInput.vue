<script setup>
import Icon from '@/Components/Icon.vue';
import { ref } from 'vue';

/**
 * Free-text tags (e.g. allergens). Enter or comma adds; backspace on empty removes the last one.
 */
const model = defineModel({ type: Array, default: () => [] });

defineProps({
    placeholder: String,
    suggestions: {
        type: Array,
        default: () => [],
    },
});

const draft = ref('');

const add = (value = draft.value) => {
    const tag = value.trim().replace(/,$/, '').trim();

    if (tag && !model.value.some((existing) => existing.toLowerCase() === tag.toLowerCase())) {
        model.value = [...model.value, tag];
    }

    draft.value = '';
};

const remove = (index) => {
    model.value = model.value.filter((_, i) => i !== index);
};

const onKeydown = (event) => {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        add();
    } else if (event.key === 'Backspace' && draft.value === '' && model.value.length) {
        remove(model.value.length - 1);
    }
};
</script>

<template>
    <div>
        <div class="flex min-h-10 flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 py-1.5 shadow-xs focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500">
            <span v-for="(tag, index) in model" :key="tag" class="inline-flex items-center gap-1 rounded-md bg-gray-100 py-0.5 pl-2 pr-1 text-sm text-gray-800">
                {{ tag }}
                <button type="button" class="rounded p-0.5 text-gray-500 hover:bg-gray-200 hover:text-gray-800" @click="remove(index)">
                    <Icon name="close" class="size-3.5" />
                    <span class="sr-only">Remove {{ tag }}</span>
                </button>
            </span>
            <input
                v-model="draft"
                type="text"
                class="min-w-24 flex-1 border-0 p-1 text-sm focus:ring-0"
                :placeholder="model.length ? '' : placeholder"
                @keydown="onKeydown"
                @blur="add()"
            />
        </div>
        <div v-if="suggestions.length" class="mt-2 flex flex-wrap gap-1.5">
            <button
                v-for="suggestion in suggestions.filter((s) => !model.some((t) => t.toLowerCase() === s.toLowerCase()))"
                :key="suggestion"
                type="button"
                class="rounded-full border border-dashed border-gray-300 px-2.5 py-0.5 text-xs text-gray-600 hover:border-gray-400 hover:text-gray-900"
                @click="add(suggestion)"
            >
                + {{ suggestion }}
            </button>
        </div>
    </div>
</template>
