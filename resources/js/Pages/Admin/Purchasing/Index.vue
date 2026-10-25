<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const supplierId = ref(props.filters.supplier_id || '');

function applyFilters() {
    router.get(
        route('purchasing.index'),
        {
            search: search.value || undefined,
            status: status.value !== 'all' ? status.value : undefined,
            supplier_id: supplierId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function statusBadgeClass(st) {
    return (
        {
            draft: 'bg-gray-100 text-gray-700',
            sent: 'bg-blue-50 text-blue-700',
            partially_received: 'bg-amber-50 text-amber-800',
            received: 'bg-emerald-50 text-emerald-700',
            closed: 'bg-slate-100 text-slate-700',
            cancelled: 'bg-red-50 text-red-700',
        }[st] || 'bg-gray-100 text-gray-700'
    );
}

function formatMoney(cents) {
    if (cents === undefined || cents === null) return '—';
    return 'Rs. ' + (cents / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Purchasing" />

    <AdminLayout title="Purchasing">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <Link
                v-if="canReceive"
                :href="route('purchasing.receive')"
                class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Receive stock (GRN)
            </Link>
            <Link
                v-if="canCreatePo"
                :href="route('purchasing.orders.create')"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                + New purchase order
            </Link>
        </PurchasingNav>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="search"
                placeholder="Search PO number or supplier…"
                class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                @keyup.enter="applyFilters"
            />
            <select
                v-model="status"
                class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                @change="applyFilters"
            >
                <option value="all">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select
                v-model="supplierId"
                class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                @change="applyFilters"
            >
                <option value="">All suppliers</option>
                <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
            </select>
            <button
                type="button"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                @click="applyFilters"
            >
                Filter
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">PO #</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Deliver to</th>
                        <th class="px-4 py-3">Order date</th>
                        <th class="px-4 py-3">Expected</th>
                        <th class="px-4 py-3 text-center">Lines</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="po in orders.data" :key="po.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono font-medium text-slate-900">
                            <Link :href="route('purchasing.orders.show', po.id)" class="hover:underline">
                                {{ po.number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-gray-900">{{ po.supplier_name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ po.location_name }} ({{ po.location_code }})</td>
                        <td class="px-4 py-3 text-gray-600">{{ po.order_date }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ po.expected_date || '—' }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ po.lines_count }}</td>
                        <td v-if="canViewCost" class="px-4 py-3 text-right font-mono font-medium text-gray-900">
                            {{ formatMoney(po.total_amount) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span
                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusBadgeClass(po.status)"
                            >
                                {{ po.status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-2">
                                <Link
                                    v-if="po.can_receive && canReceive"
                                    :href="route('purchasing.receive', { purchase_order_id: po.id })"
                                    class="rounded bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                                >
                                    Receive
                                </Link>
                                <Link
                                    :href="route('purchasing.orders.show', po.id)"
                                    class="text-xs font-medium text-slate-700 hover:text-slate-900"
                                >
                                    View
                                </Link>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!orders.data.length">
                        <td :colspan="canViewCost ? 9 : 8" class="px-4 py-10 text-center text-gray-500">
                            No purchase orders yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
