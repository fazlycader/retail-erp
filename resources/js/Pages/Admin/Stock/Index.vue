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
    variants: Object,
    matrixLocations: Array,
    allLocations: Array,
    categories: Array,
    filters: Object,
    canViewCost: Boolean,
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    category_id: props.filters.category_id ?? '',
    location_id: props.filters.location_id ?? '',
    low_stock: Boolean(props.filters.low_stock),
});

const applyFilters = () => {
    router.get(
        route('stock.index'),
        {
            search: filterForm.search || undefined,
            category_id: filterForm.category_id || undefined,
            location_id: filterForm.location_id || undefined,
            low_stock: filterForm.low_stock ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const clearFilters = () => {
    filterForm.search = '';
    filterForm.category_id = '';
    filterForm.location_id = '';
    filterForm.low_stock = false;
    applyFilters();
};
</script>

<template>
    <AdminLayout title="Stock overview">
        <StockNav>
            <Link
                :href="route('transfers.create')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                New transfer
            </Link>
        </StockNav>

        <Card class="mb-6">
            <form class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="applyFilters">
                <div class="lg:col-span-2">
                    <TextInput
                        v-model="filterForm.search"
                        placeholder="Search SKU, barcode or product…"
                        class="w-full"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <SelectInput v-model="filterForm.category_id" class="w-full" @change="applyFilters">
                        <option value="">All categories</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.label }}</option>
                    </SelectInput>
                </div>
                <div>
                    <SelectInput v-model="filterForm.location_id" class="w-full" @change="applyFilters">
                        <option value="">All accessible locations</option>
                        <option v-for="loc in allLocations" :key="loc.id" :value="loc.id">{{ loc.code }} — {{ loc.name }}</option>
                    </SelectInput>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
                        <input
                            v-model="filterForm.low_stock"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            @change="applyFilters"
                        />
                        Low stock only
                    </label>
                    <button
                        v-if="filterForm.search || filterForm.category_id || filterForm.location_id || filterForm.low_stock"
                        type="button"
                        class="text-xs font-medium text-gray-500 hover:text-gray-800"
                        @click="clearFilters"
                    >
                        Reset
                    </button>
                </div>
            </form>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Item / SKU</th>
                            <th
                                v-for="loc in matrixLocations"
                                :key="loc.id"
                                class="px-4 py-3 text-right"
                                :title="loc.name"
                            >
                                {{ loc.code }}
                                <span class="block text-[10px] font-normal text-gray-400 normal-case">On hand / Avail</span>
                            </th>
                            <th class="px-4 py-3 text-right">Total On Hand</th>
                            <th class="px-4 py-3 text-right">Reserved</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Avg Cost</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Stock Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="row in variants.data" :key="row.id" class="hover:bg-gray-50/70">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="route('stock.show', row.id)"
                                        class="font-semibold text-indigo-600 hover:text-indigo-800"
                                    >
                                        {{ row.sku }}
                                    </Link>
                                    <Badge v-if="row.is_low_stock" tone="amber">Low stock</Badge>
                                    <Badge v-if="row.is_serialized" tone="indigo">Serialized</Badge>
                                </div>
                                <p class="text-xs text-gray-600">
                                    {{ row.product_name }}
                                    <span v-if="row.variant_name" class="text-gray-400">— {{ row.variant_name }}</span>
                                </p>
                            </td>
                            <td
                                v-for="loc in matrixLocations"
                                :key="loc.id"
                                class="px-4 py-3 text-right tabular-nums"
                            >
                                <span class="font-medium text-gray-900">
                                    {{ formatQuantity(row.locations[loc.id]?.on_hand ?? 0) }}
                                </span>
                                <span
                                    v-if="(row.locations[loc.id]?.reserved ?? 0) > 0"
                                    class="block text-xs text-amber-600"
                                >
                                    Avail: {{ formatQuantity(row.locations[loc.id]?.available ?? 0) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-900">
                                {{ formatQuantity(row.totals.on_hand) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-amber-700">
                                {{ formatQuantity(row.totals.reserved) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-emerald-700">
                                {{ formatQuantity(row.totals.available) }}
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right tabular-nums text-gray-600">
                                {{ formatMoney(row.average_cost) }}
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right font-medium tabular-nums text-gray-900">
                                {{ formatMoney(row.total_value) }}
                            </td>
                        </tr>
                        <tr v-if="variants.data.length === 0">
                            <td :colspan="matrixLocations.length + (canViewCost ? 6 : 4)" class="px-4 py-8 text-center text-gray-500">
                                No variants match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="variants.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

