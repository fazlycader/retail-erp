<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    groups: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    locations: { type: Array, required: true },
    selectedLocationId: { type: Number, default: null },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

const locationFilter = ref(props.selectedLocationId || '');
const fallbackSuppliers = ref({});

function changeLocation() {
    router.get(
        route('purchasing.reorder'),
        { location_id: locationFilter.value || undefined },
        { preserveState: true, replace: true },
    );
}

function createPoForGroup(group) {
    const supplierId = group.supplier_id || fallbackSuppliers.value[group.group_key];
    if (!supplierId) return;

    router.post(route('purchasing.reorder.store'), {
        supplier_id: supplierId,
        location_id: group.location_id,
        lines: group.items.map((item) => ({
            product_variant_id: item.product_variant_id,
            ordered_qty: (item.suggested_qty / 1000).toFixed(3).replace(/\.?0+$/, ''),
            unit_cost: ((item.unit_cost || 0) / 100).toFixed(2),
            supplier_sku: item.supplier_sku || '',
        })),
    });
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
    <Head title="Reorder Suggestions" />

    <AdminLayout title="Reorder Suggestions">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <select
                v-model="locationFilter"
                class="rounded-lg border-gray-300 text-sm shadow-sm"
                @change="changeLocation"
            >
                <option value="">All accessible locations</option>
                <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                    {{ loc.name }} ({{ loc.code }})
                </option>
            </select>
        </PurchasingNav>

        <div v-if="!groups.length" class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-500">
            All stocked variants are currently at or above their reorder levels (including open purchase orders).
        </div>

        <div class="space-y-6">
            <div
                v-for="group in groups"
                :key="group.group_key"
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-5 py-3.5">
                    <div>
                        <span class="font-semibold text-gray-900">{{ group.supplier_name }}</span>
                        <span class="ml-2 rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">
                            Deliver to: {{ group.location_name }} ({{ group.location_code }})
                        </span>
                    </div>

                    <div v-if="canCreatePo" class="flex items-center gap-2">
                        <select
                            v-if="!group.supplier_id"
                            v-model="fallbackSuppliers[group.group_key]"
                            class="rounded-lg border-gray-300 text-xs"
                        >
                            <option value="">Pick supplier to order from…</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <button
                            type="button"
                            :disabled="!group.supplier_id && !fallbackSuppliers[group.group_key]"
                            class="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                            @click="createPoForGroup(group)"
                        >
                            Create Draft PO ({{ group.items.length }} items)
                        </button>
                    </div>
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">SKU</th>
                            <th class="px-4 py-2.5">Item</th>
                            <th class="px-4 py-2.5 text-right">Available</th>
                            <th class="px-4 py-2.5 text-right">On Open PO</th>
                            <th class="px-4 py-2.5 text-right">Reorder level</th>
                            <th class="px-4 py-2.5 text-right">Suggested order qty</th>
                            <th v-if="canViewCost" class="px-4 py-2.5 text-right">Last cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="item in group.items" :key="item.product_variant_id">
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold text-slate-900">{{ item.sku }}</td>
                            <td class="px-4 py-2.5 text-gray-900">
                                {{ item.product_name }}
                                <span v-if="item.variant_name" class="text-gray-500">— {{ item.variant_name }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-red-600">{{ formatQty(item.available) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-blue-700">{{ formatQty(item.open_po_qty) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-gray-600">{{ formatQty(item.reorder_level) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-emerald-700">
                                {{ formatQty(item.suggested_qty) }}
                            </td>
                            <td v-if="canViewCost" class="px-4 py-2.5 text-right font-mono text-gray-700">
                                {{ formatMoney(item.unit_cost) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
