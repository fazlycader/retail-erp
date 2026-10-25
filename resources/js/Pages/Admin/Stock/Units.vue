<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    units: Object,
    selectedUnit: Object,
    unitMovements: Array,
    statuses: Array,
    conditions: Array,
    locations: Array,
    filters: Object,
    canViewCost: Boolean,
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    condition: props.filters.condition ?? '',
    location_id: props.filters.location_id ?? '',
});

const applyFilters = () => {
    router.get(
        route('stock.units.index'),
        {
            search: filterForm.search || undefined,
            status: filterForm.status || undefined,
            condition: filterForm.condition || undefined,
            location_id: filterForm.location_id || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const changeCondition = (unitId, condition) => {
    router.patch(route('stock.units.update', unitId), { condition }, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout title="Serialized stock units">
        <StockNav />

        <Card class="mb-6">
            <form class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
                <div>
                    <TextInput
                        v-model="filterForm.search"
                        placeholder="Search serial number or SKU…"
                        class="w-full"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <SelectInput v-model="filterForm.status" class="w-full" @change="applyFilters">
                        <option value="">All statuses</option>
                        <option v-for="st in statuses" :key="st.value" :value="st.value">{{ st.label }}</option>
                    </SelectInput>
                </div>
                <div>
                    <SelectInput v-model="filterForm.condition" class="w-full" @change="applyFilters">
                        <option value="">All conditions</option>
                        <option v-for="c in conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </SelectInput>
                </div>
                <div>
                    <SelectInput v-model="filterForm.location_id" class="w-full" @change="applyFilters">
                        <option value="">All locations</option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            {{ loc.code }} — {{ loc.name }}
                        </option>
                    </SelectInput>
                </div>
            </form>
        </Card>

        <Card v-if="selectedUnit" :title="`Serial history: ${selectedUnit.serial_number}`" class="mb-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 pb-3 text-sm">
                <div>
                    <span class="font-semibold text-gray-900">{{ selectedUnit.sku }}</span> — {{ selectedUnit.product_name }}
                    <span v-if="selectedUnit.variant_name" class="text-gray-500">({{ selectedUnit.variant_name }})</span>
                    <div class="mt-1 text-xs text-gray-500">
                        Current location: <strong>{{ selectedUnit.location_name || 'Not at a location' }}</strong>
                        · Status: <strong>{{ selectedUnit.status_label }}</strong>
                        · Condition: <strong>{{ selectedUnit.condition_label }}</strong>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500">Condition:</span>
                    <SelectInput
                        :model-value="selectedUnit.condition"
                        class="text-xs"
                        @change="changeCondition(selectedUnit.id, $event.target.value)"
                    >
                        <option v-for="c in conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </SelectInput>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead>
                        <tr class="text-left font-semibold text-gray-500 uppercase">
                            <th class="pb-2">Date</th>
                            <th class="pb-2">Location</th>
                            <th class="pb-2">Movement</th>
                            <th class="pb-2 text-right">Qty</th>
                            <th class="pb-2">By</th>
                            <th class="pb-2">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="m in unitMovements" :key="m.id">
                            <td class="py-2">{{ m.business_date }}</td>
                            <td class="py-2">{{ m.location_code }} — {{ m.location_name }}</td>
                            <td class="py-2">{{ m.type_label }}</td>
                            <td class="py-2 text-right font-semibold tabular-nums">
                                {{ m.quantity > 0 ? '+' : '' }}{{ formatQuantity(m.quantity) }}
                            </td>
                            <td class="py-2">{{ m.user_name || '—' }}</td>
                            <td class="py-2 text-gray-500">{{ m.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Serial Number</th>
                            <th class="px-4 py-3">Item / SKU</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Condition</th>
                            <th class="px-4 py-3">Location</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Unit Cost</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="u in units.data" :key="u.id" class="hover:bg-gray-50/70">
                            <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ u.serial_number }}</td>
                            <td class="px-4 py-3">
                                <Link :href="route('stock.show', u.product_variant_id)" class="font-medium text-indigo-600 hover:underline">
                                    {{ u.sku }}
                                </Link>
                                <div class="text-xs text-gray-500">{{ u.product_name }} {{ u.variant_name }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="u.status === 'in_stock' ? 'green' : u.status === 'in_transit' ? 'amber' : 'slate'">
                                    {{ u.status_label }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="u.condition === 'new' ? 'green' : u.condition === 'display' ? 'amber' : 'rose'">
                                    {{ u.condition_label }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ u.location_code ? `${u.location_code} — ${u.location_name}` : '—' }}
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right tabular-nums">
                                {{ formatMoney(u.unit_cost) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('stock.units.index', { ...filters, unit_id: u.id })"
                                    class="text-xs font-semibold text-indigo-600 hover:underline"
                                >
                                    History
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="units.data.length === 0">
                            <td :colspan="canViewCost ? 7 : 6" class="px-4 py-8 text-center text-gray-500">
                                No serialized stock units found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="units.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

