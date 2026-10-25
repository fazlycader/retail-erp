<script setup>
import { formatValue, isNumeric } from '@/lib/format';
import { computed, ref, watch } from 'vue';

/** A report table with sorting and simple pagination (50 rows a page). */
const props = defineProps({
    table: { type: Object, required: true },
    currency: { type: String, default: 'LKR' },
});

defineEmits(['export']);

const sortKey = ref(null);
const desc = ref(true);
const page = ref(1);
const perPage = 50;
watch(() => props.table, () => ((page.value = 1), (sortKey.value = null)));

const rows = computed(() => {
    if (!sortKey.value) return props.table.rows;
    const key = sortKey.value;

    return [...props.table.rows].sort((a, b) => {
        const x = a[key] ?? '';
        const y = b[key] ?? '';
        const order = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));

        return desc.value ? -order : order;
    });
});

const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / perPage)));
const visible = computed(() => rows.value.slice((page.value - 1) * perPage, page.value * perPage));

const sort = (key) => {
    desc.value = sortKey.value === key ? !desc.value : true;
    sortKey.value = key;
};
</script>

<template>
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
        <header class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3">
            <h3 class="flex-1 font-semibold text-gray-900">{{ table.title }} <span class="text-sm font-normal text-gray-400">({{ table.rows.length }})</span></h3>
            <slot name="actions" />
        </header>
        <p v-if="table.rows.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">Nothing in this period.</p>
        <div v-else class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th v-for="c in table.columns" :key="c.key" class="whitespace-nowrap px-3 py-2 font-semibold" :class="isNumeric(c.format) ? 'text-right' : 'text-left'">
                            <button type="button" class="inline-flex items-center gap-1 hover:text-gray-900" @click="sort(c.key)">
                                {{ c.label }}<span v-if="sortKey === c.key">{{ desc ? '↓' : '↑' }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="(row, i) in visible" :key="i" class="hover:bg-gray-50">
                        <td v-for="c in table.columns" :key="c.key" class="px-3 py-2" :class="isNumeric(c.format) ? 'whitespace-nowrap text-right tabular-nums' : ''">
                            {{ formatValue(row[c.key], c.format, currency) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="table.totals" class="border-t-2 border-gray-900 font-bold">
                    <tr>
                        <td v-for="c in table.columns" :key="c.key" class="px-3 py-2" :class="isNumeric(c.format) ? 'whitespace-nowrap text-right tabular-nums' : ''">
                            {{ table.totals[c.key] === null ? '' : formatValue(table.totals[c.key], c.format, currency) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <footer v-if="pages > 1" class="flex items-center justify-between border-t border-gray-100 px-4 py-2 text-sm">
            <span class="text-gray-500">Page {{ page }} of {{ pages }}</span>
            <div class="flex gap-1">
                <button type="button" class="rounded-md px-3 py-1 ring-1 ring-gray-300 disabled:opacity-40" :disabled="page === 1" @click="page--">Previous</button>
                <button type="button" class="rounded-md px-3 py-1 ring-1 ring-gray-300 disabled:opacity-40" :disabled="page === pages" @click="page++">Next</button>
            </div>
        </footer>
    </section>
</template>
