<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import SelectInput from '@/Components/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import WorkshopNav from '@/Pages/Admin/Workshop/Partials/WorkshopNav.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    jobs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    progressOptions: { type: Array, default: () => [] },
    editableStatuses: { type: Array, default: () => [] },
    canViewCosts: { type: Boolean, default: false },
});

const statusFilter = ref(props.filters.status ?? 'open');
const progressFilter = ref(props.filters.progress ?? 'all');

const applyFilters = () => {
    router.get(
        route('workshop.suppliers.index'),
        {
            status: statusFilter.value,
            progress: progressFilter.value,
        },
        { preserveState: true, replace: true },
    );
};

const updateJobProgress = (jobId, supplierStatus) => {
    router.post(
        route('workshop.suppliers.progress', jobId),
        { supplier_status: supplierStatus },
        { preserveScroll: true },
    );
};
</script>

<template>
    <AdminLayout title="Supplier Custom Jobs">
        <WorkshopNav />

        <Card title="Supplier-Sourced Custom Jobs" description="Back-to-back custom orders made by external suppliers. Receiving the linked PO automatically marks the job Ready and reserves the piece.">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <SelectInput v-model="statusFilter" class="text-sm" @change="applyFilters">
                    <option value="open">Open jobs</option>
                    <option value="ready">Received / Ready</option>
                    <option value="all">All statuses</option>
                </SelectInput>
                <SelectInput v-model="progressFilter" class="text-sm" @change="applyFilters">
                    <option value="all">All supplier stages</option>
                    <option v-for="opt in progressOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </SelectInput>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Job #</th>
                            <th class="px-4 py-3">Item / Customer</th>
                            <th class="px-4 py-3">Supplier &amp; PO</th>
                            <th class="px-4 py-3">Due Date</th>
                            <th class="px-4 py-3">Supplier Progress</th>
                            <th v-if="canViewCosts" class="px-4 py-3 text-right">PO Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="j in jobs.data" :key="j.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <Link :href="route('workshop.jobs.show', j.id)" class="font-mono text-xs font-bold text-brand-700 hover:underline">
                                    {{ j.number }}
                                </Link>
                                <span class="ml-1 text-xs text-gray-400">({{ j.location_code }})</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">
                                    {{ j.title }}
                                    <span v-if="j.pieces > 1" class="text-xs text-gray-500">× {{ j.pieces }}</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ j.customer_name || 'Walk-in' }}
                                    <span v-if="j.order_number" class="font-mono">· {{ j.order_number }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-semibold text-gray-900">{{ j.supplier_name || '—' }}</div>
                                <Link
                                    v-if="j.po_id"
                                    :href="route('purchasing.orders.show', j.po_id)"
                                    class="font-mono text-brand-600 hover:underline"
                                >
                                    {{ j.po_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span :class="j.overdue ? 'font-semibold text-red-700' : 'text-gray-700'">
                                    {{ j.due_date || '—' }}
                                </span>
                                <Badge v-if="j.overdue" tone="red" class="ml-1">Overdue</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <SelectInput
                                    v-if="j.status !== 'ready' && j.status !== 'cancelled'"
                                    :model-value="j.supplier_status || 'ordered'"
                                    class="text-xs"
                                    @change="(e) => updateJobProgress(j.id, e.target.value)"
                                >
                                    <option v-for="st in editableStatuses" :key="st.value" :value="st.value">
                                        {{ st.label }}
                                    </option>
                                </SelectInput>
                                <Badge v-else tone="green">{{ j.supplier_status_label }}</Badge>
                            </td>
                            <td v-if="canViewCosts" class="px-4 py-3 text-right font-mono text-xs font-semibold text-gray-900">
                                {{ formatMoney(j.unit_cost) }}
                            </td>
                        </tr>
                        <tr v-if="!jobs.data?.length">
                            <td :colspan="canViewCosts ? 6 : 5" class="px-4 py-8 text-center text-sm text-gray-500">
                                No supplier-sourced custom jobs match the selected filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>

