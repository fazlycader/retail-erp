<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    receipts: { type: Object, required: true },
    filters: { type: Object, required: true },
    suppliers: { type: Array, required: true },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const supplierId = ref(props.filters.supplier_id || '');
const expandedId = ref(null);

function applyFilters() {
    router.get(
        route('purchasing.receipts.index'),
        {
            search: search.value || undefined,
            supplier_id: supplierId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function formatMoney(cents) {
    if (cents === undefined || cents === null) return '—';
    return 'Rs. ' + (cents / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatQty(milli) {
    if (!milli) return '0';
    return (milli / 1000).toLocaleString('en-LK', { maximumFractionDigits: 3 });
}
</script>

<template>
    <Head title="Goods Receipts (GRN)" />

    <AdminLayout title="Goods Receipts (GRN)">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <Link
                v-if="canReceive"
                :href="route('purchasing.receive')"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                + Receive stock
            </Link>
        </PurchasingNav>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="search"
                placeholder="Search GRN #, PO #, delivery note, supplier…"
                class="w-72 rounded-lg border-gray-300 text-sm"
                @keyup.enter="applyFilters"
            />
            <select v-model="supplierId" class="rounded-lg border-gray-300 text-sm" @change="applyFilters">
                <option value="">All suppliers</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">GRN #</th>
                        <th class="px-4 py-3">PO #</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Location</th>
                        <th class="px-4 py-3">Delivery note</th>
                        <th class="px-4 py-3">Received by</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-right">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template v-for="grn in receipts.data" :key="grn.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono font-medium text-slate-900">{{ grn.number }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-blue-700">
                                <Link
                                    v-if="grn.purchase_order_id"
                                    :href="route('purchasing.orders.show', grn.purchase_order_id)"
                                    class="hover:underline"
                                >
                                    {{ grn.purchase_order_number }}
                                </Link>
                                <span v-else class="text-gray-400">Direct</span>
                            </td>
                            <td class="px-4 py-3 text-gray-900">{{ grn.supplier_name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ grn.location_name }} ({{ grn.location_code }})</td>
                            <td class="px-4 py-3 text-gray-600">{{ grn.supplier_delivery_note || '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ grn.received_by_name }}
                                <span v-if="grn.approved_by_name" class="block text-xs text-amber-700">
                                    Approved by {{ grn.approved_by_name }}
                                </span>
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right font-mono font-medium text-gray-900">
                                {{ formatMoney(grn.total_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-slate-700 hover:text-slate-900"
                                    @click="expandedId = expandedId === grn.id ? null : grn.id"
                                >
                                    {{ expandedId === grn.id ? 'Hide lines' : `Lines (${grn.lines.length})` }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expandedId === grn.id" class="bg-gray-50/70">
                            <td :colspan="canViewCost ? 8 : 7" class="px-6 py-4">
                                <table class="min-w-full text-xs">
                                    <thead class="text-left text-gray-500">
                                        <tr>
                                            <th class="pb-1.5">SKU</th>
                                            <th class="pb-1.5">Item</th>
                                            <th class="pb-1.5 text-right">Received</th>
                                            <th class="pb-1.5 text-right">Damaged</th>
                                            <th v-if="canViewCost" class="pb-1.5 text-right">Unit cost</th>
                                            <th class="pb-1.5 pl-4">Serials</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200/60">
                                        <tr v-for="l in grn.lines" :key="l.id">
                                            <td class="py-1.5 font-mono font-medium">{{ l.sku }}</td>
                                            <td class="py-1.5">
                                                {{ l.product_name }}
                                                <span v-if="l.variant_name" class="text-gray-500">— {{ l.variant_name }}</span>
                                            </td>
                                            <td class="py-1.5 text-right font-mono">{{ formatQty(l.received_qty) }}</td>
                                            <td class="py-1.5 text-right font-mono text-red-600">
                                                {{ formatQty(l.damaged_qty) }}
                                            </td>
                                            <td v-if="canViewCost" class="py-1.5 text-right font-mono">
                                                {{ formatMoney(l.unit_cost) }}
                                            </td>
                                            <td class="py-1.5 pl-4 font-mono text-gray-600">
                                                {{ l.serials?.length ? l.serials.join(', ') : '—' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!receipts.data.length">
                        <td :colspan="canViewCost ? 8 : 7" class="px-4 py-10 text-center text-gray-500">
                            No goods receipts recorded yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
