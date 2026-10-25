<script setup>
import { decimalToCents, formatMoney } from '@/lib/money';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Decimal price input ("1250.50") with the currency shown. The server converts to cents.
 */
const model = defineModel({ type: [String, Number], default: '' });

defineProps({
    preview: Boolean,
});

const currency = computed(() => usePage().props.company?.currency ?? 'LKR');
const cents = computed(() => decimalToCents(model.value));
</script>

<template>
    <div>
        <div class="flex rounded-lg shadow-xs">
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">
                {{ currency }}
            </span>
            <input
                v-model="model"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                placeholder="0.00"
                class="block w-full min-w-0 rounded-r-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500"
                v-bind="$attrs"
            />
        </div>
        <p v-if="preview && model !== '' && model !== null" class="mt-1 text-xs" :class="cents === null ? 'text-red-600' : 'text-gray-500'">
            {{ cents === null ? 'Enter a number like 1250 or 1250.50' : formatMoney(cents, currency) }}
        </p>
    </div>
</template>
