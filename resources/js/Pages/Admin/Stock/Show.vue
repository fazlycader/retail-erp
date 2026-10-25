<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    variant: Object,
    locationCards: Array,
    movements: Object,
    reservations: Array,
    units: Array,
    canViewCost: Boolean,
});
</script>

<template>
    <AdminLayout :title="`Stock card: ${variant.sku}`">
        <StockNav>
            <Link
                :href="route('stock.index')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Back to overview
            </Link>
        </StockNav>

        <Card class="mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-gray-900">{{ variant.sku }}</h2>
                        <Badge v-if="variant.is_serialized" tone="indigo">Serialized</Badge>
                    </div>
                    <p class="mt-0.5 text-sm text-gray-600">
                        {{ variant.product_name }}
                        <span v-if="variant.name" class="text-gray-400">— {{ variant.name }}</span>
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        Barcode: <span class="font-mono text-gray-600">{{ variant.barcode }}</span>
                        · Reorder level: {{ formatQuantity(variant.reorder_level) }}
                        · Reorder qty: {{ formatQuantity(variant.reorder_qty) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-6 text-right text-sm">
                    <div>
                        <span class="block text-xs text-gray-400">Selling price</span>
                        <span class="font-semibold text-gray-900">{{ formatMoney(variant.price) }}</span>
                    </div>
                    <div v-if="canViewCost">
                        <span class="block text-xs text-gray-400">Weighted avg cost</span>
                        <span class="font-semibold text-gray-900">{{ formatMoney(variant.average_cost) }}</span>
                    </div>
                    <div v-if="canViewCost && variant.last_cost !== null">
                        <span class="block text-xs text-gray-400">Last cost</span>
                        <span class="font-semibold text-gray-900">{{ formatMoney(variant.last_cost) }}</span>
                    </div>
                </div>
            </div>
        </Card>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="loc in locationCards" :key="loc.location_id">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-900">{{ loc.location_name }}</span>
                    <Badge tone="slate">{{ loc.location_code }}</Badge>
                </div>
                <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-gray-50 p-2">
                        <dt class="text-[11px] text-gray-500">On Hand</dt>
                        <dd class="text-base font-bold tabular-nums text-gray-900">{{ formatQuantity(loc.on_hand) }}</dd>
                    </div>
                    <div class="rounded-lg bg-amber-50/70 p-2">
                        <dt class="text-[11px] text-amber-700">Reserved</dt>
                        <dd class="text-base font-bold tabular-nums text-amber-800">{{ formatQuantity(loc.reserved) }}</dd>
                    </div>
                    <div class="rounded-lg bg-emerald-50/70 p-2">
                        <dt class="text-[11px] text-emerald-700">Available</dt>
                        <dd class="text-base font-bold tabular-nums text-emerald-800">{{ formatQuantity(loc.available) }}</dd>
                    </div>
                </dl>
                <p v-if="canViewCost" class="mt-2 text-right text-xs text-gray-500">
                    Value: <span class="font-semibold text-gray-800">{{ formatMoney(loc.value) }}</span>
                </p>
            </Card>
        </div>

        <Card v-if="reservations.length > 0" title="Active reservations" class="mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-gray-500 uppercase">
                            <th class="pb-2">Location</th>
                            <th class="pb-2">Serial</th>
                            <th class="pb-2 text-right">Qty</th>
                            <th class="pb-2">Reserved by</th>
                            <th class="pb-2">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="res in reservations" :key="res.id">
                            <td class="py-2">{{ res.location_code }} — {{ res.location_name }}</td>
                            <td class="py-2 font-mono text-xs">{{ res.serial_number || '—' }}</td>
                            <td class="py-2 text-right font-semibold tabular-nums">{{ formatQuantity(res.quantity) }}</td>
                            <td class="py-2 text-gray-600">{{ res.user_name || '—' }}</td>
                            <td class="py-2 text-gray-500">{{ res.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-if="variant.is_serialized && units.length > 0" title="Serialized units" class="mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-gray-500 uppercase">
                            <th class="pb-2">Serial Number</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2">Condition</th>
                            <th class="pb-2">Location</th>
                            <th v-if="canViewCost" class="pb-2 text-right">Unit Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="unit in units" :key="unit.id">
                            <td class="py-2 font-mono font-medium">
                                <Link
                                    :href="route('stock.units.index', { search: unit.serial_number, unit_id: unit.id })"
                                    class="text-indigo-600 hover:underline"
                                >
                                    {{ unit.serial_number }}
                                </Link>
                            </td>
                            <td class="py-2">{{ unit.status_label }}</td>
                            <td class="py-2">{{ unit.condition_label }}</td>
                            <td class="py-2">{{ unit.location_name || '—' }}</td>
                            <td v-if="canViewCost" class="py-2 text-right tabular-nums">{{ formatMoney(unit.unit_cost) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card title="Immutable movement ledger" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3 text-right">Change</th>
                            <th class="px-4 py-3 text-right">Balance After</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Unit Cost</th>
                            <th class="px-4 py-3">Document / Note</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="m in movements.data" :key="m.id">
                            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600">
                                {{ m.business_date }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="font-medium text-gray-900">{{ m.location?.code }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <Badge :tone="m.quantity > 0 ? 'green' : 'amber'">{{ m.type_label }}</Badge>
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="m.quantity > 0 ? 'text-emerald-700' : 'text-rose-600'"
                            >
                                {{ m.quantity > 0 ? '+' : '' }}{{ formatQuantity(m.quantity) }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-gray-900">
                                {{ formatQuantity(m.balance_after) }}
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right tabular-nums text-gray-600">
                                {{ formatMoney(m.unit_cost) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <Link
                                    v-if="m.reference_url"
                                    :href="m.reference_url"
                                    class="font-medium text-indigo-600 hover:underline"
                                >
                                    View {{ m.reference_type?.replace('_', ' ') }} #{{ m.reference_id }}
                                </Link>
                                <span v-if="m.serial_number" class="ml-1 font-mono text-gray-500">[{{ m.serial_number }}]</span>
                                <span v-if="m.note" class="block text-gray-500">{{ m.note }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ m.user_name || '—' }}</td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td :colspan="canViewCost ? 8 : 7" class="px-4 py-8 text-center text-gray-500">
                                No stock movements recorded yet for this variant.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="movements.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

