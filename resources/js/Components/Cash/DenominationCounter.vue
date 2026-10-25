<script setup>
import { formatMoney } from '@/lib/money';
import { computed } from 'vue';

/**
 * Count cash by LKR note and coin. v-model = {"500000": 2, ...} (denomination in cents => count).
 */
const props = defineProps({
    denominations: { type: Array, required: true },
    currency: { type: String, default: 'LKR' },
});

const counts = defineModel({ type: Object, default: () => ({}) });

const total = computed(() => props.denominations.reduce((sum, d) => sum + d.value * (Number(counts.value[d.value]) || 0), 0));

const set = (value, count) => {
    counts.value = { ...counts.value, [value]: Math.max(0, Math.min(100000, Number.parseInt(count, 10) || 0)) };
};
const step = (value, by) => set(value, (Number(counts.value[value]) || 0) + by);

defineExpose({ total });
</script>

<template>
    <div>
        <div class="grid gap-2 sm:grid-cols-2">
            <div v-for="d in denominations" :key="d.value" class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 ring-1 ring-gray-200">
                <span class="w-16 shrink-0 text-sm font-bold" :class="d.kind === 'coin' ? 'text-amber-700' : 'text-gray-900'">
                    {{ d.label }}<span class="block text-[10px] font-semibold uppercase text-gray-400">{{ d.kind }}</span>
                </span>
                <button type="button" class="size-10 rounded-lg bg-gray-100 text-xl font-bold" :aria-label="`One less ${d.label}`" @click="step(d.value, -1)">−</button>
                <input
                    :value="counts[d.value] || ''"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    class="min-h-10 w-16 rounded-lg border-gray-300 text-center text-lg font-semibold tabular-nums"
                    :aria-label="`Number of ${d.label}`"
                    placeholder="0"
                    @input="set(d.value, $event.target.value)"
                />
                <button type="button" class="size-10 rounded-lg bg-gray-100 text-xl font-bold" :aria-label="`One more ${d.label}`" @click="step(d.value, 1)">+</button>
                <span class="ml-auto text-right text-sm tabular-nums text-gray-600">{{ formatMoney(d.value * (Number(counts[d.value]) || 0), currency) }}</span>
            </div>
        </div>
        <p class="mt-3 text-right text-lg font-black tabular-nums text-gray-900">Total {{ formatMoney(total, currency) }}</p>
    </div>
</template>
