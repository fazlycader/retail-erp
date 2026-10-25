<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import WorkshopNav from '@/Pages/Admin/Workshop/Partials/WorkshopNav.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    materials: { type: Array, default: () => [] },
    workshops: { type: Array, default: () => [] },
    selectedLocationId: { type: Number, default: null },
    filters: { type: Object, default: () => ({}) },
    canViewCosts: { type: Boolean, default: false },
});

const search = ref(props.filters.search ?? '');
const lowStock = ref(Boolean(props.filters.low_stock));
const locationId = ref(props.selectedLocationId ?? '');

const applyFilters = () => {
    router.get(
        route('workshop.materials.index'),
        {
            search: search.value || undefined,
            low_stock: lowStock.value ? 1 : undefined,
            location_id: locationId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <AdminLayout title="Workshop Raw Materials">
        <WorkshopNav />

        <Card title="Workshop Raw Materials Stock" description="Products in categories flagged as Raw Materials. Counted and issued in milli-units (metres, sqft, sheets, kg).">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <TextInput
                    v-model="search"
                    class="w-64 text-sm"
                    placeholder="Search SKU, barcode, material name…"
                    @keyup.enter="applyFilters"
                />
                <SelectInput v-if="workshops.length > 1" v-model="locationId" class="text-sm" @change="applyFilters">
                    <option v-for="w in workshops" :key="w.id" :value="w.id">{{ w.name }} ({{ w.code }})</option>
                </SelectInput>
                <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-700">
                    <Toggle v-model="lowStock" @update:model-value="applyFilters" />
                    Low stock only
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">SKU</th>
                            <th class="px-4 py-3">Material</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3 text-right">On Hand</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th class="px-4 py-3 text-right">Reorder Level</th>
                            <th v-if="canViewCosts" class="px-4 py-3 text-right">Avg Cost / Unit</th>
                            <th v-if="canViewCosts" class="px-4 py-3 text-right">Stock Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="m in materials" :key="m.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-900">{{ m.sku }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-gray-900">{{ m.name }}</span>
                                <Badge v-if="m.is_low" tone="red" class="ml-2">Low stock</Badge>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ m.category_path }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ m.on_hand_label }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold" :class="m.is_low ? 'text-red-700' : 'text-emerald-700'">
                                {{ m.available_label }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-500">{{ m.reorder_level_label || '—' }}</td>
                            <td v-if="canViewCosts" class="px-4 py-3 text-right font-mono text-xs text-gray-700">
                                {{ formatMoney(m.average_cost) }} / {{ m.unit }}
                            </td>
                            <td v-if="canViewCosts" class="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                                {{ formatMoney(m.stock_value) }}
                            </td>
                        </tr>
                        <tr v-if="!materials.length">
                            <td :colspan="canViewCosts ? 8 : 6" class="px-4 py-8 text-center text-sm text-gray-500">
                                No raw materials found. Mark a category as "Raw materials" under Catalog → Categories.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>

