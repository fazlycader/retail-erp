<script setup>
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    returns: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search || '');

function applyFilters() {
    router.get(route('sales.returns.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Sales returns" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav />

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Sales returns</h1>
                    <p class="text-sm text-gray-500">Start a return from the sales order (Return items). Exchanges link to the new sale once the credit note is used.</p>
                </div>
                <form @submit.prevent="applyFilters">
                    <input v-model="search" type="search" placeholder="Return, invoice or customer…" class="w-64 rounded-lg border-gray-300 text-sm" />
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Return</th>
                            <th class="px-4 py-3">Order</th>
                            <th class="px-4 py-3">Outcome</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3 text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!returns.data.length"><td colspan="5" class="px-4 py-8 text-center text-gray-500">No returns yet.</td></tr>
                        <tr v-for="r in returns.data" :key="r.id">
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs font-semibold">{{ r.number }}</div>
                                <div class="text-xs text-gray-500">{{ r.date }} · {{ r.location_code }} · {{ r.created_by }}<span v-if="r.approved_by"> · approved {{ r.approved_by }}</span></div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <Link :href="route('sales.orders.show', r.order_id)" class="font-mono text-indigo-600 hover:underline">{{ r.order_number }}</Link>
                                <div>{{ r.customer || 'Walk-in' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-medium">{{ r.outcome_label }}</div>
                                <div v-if="r.credit_note" class="font-mono text-gray-500">{{ r.credit_note.number }}</div>
                                <div v-if="r.refund_number" class="font-mono text-gray-500">{{ r.refund_number }}</div>
                                <Link v-if="r.exchange_order" :href="route('sales.orders.show', r.exchange_order.id)" class="text-indigo-600 hover:underline">→ {{ r.exchange_order.number }}</Link>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ r.reason }} ({{ r.lines_count }} line{{ r.lines_count === 1 ? '' : 's' }})</td>
                            <td class="px-4 py-3 text-right font-mono">
                                <div class="font-semibold">{{ formatMoney(r.total) }}</div>
                                <div v-if="r.settled_amount" class="text-xs text-gray-500">settled {{ formatMoney(r.settled_amount) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4"><Pagination :paginator="returns" /></div>
        </div>
    </AdminLayout>
</template>
