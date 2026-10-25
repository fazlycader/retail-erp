<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    transfers: Object,
    inTransitCount: Number,
    discrepancyCount: Number,
    statuses: Array,
    filters: Object,
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

const applyFilters = () => {
    router.get(
        route('transfers.index'),
        {
            search: filterForm.search || undefined,
            status: filterForm.status || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const statusTone = (status) => {
    if (status === 'received' || status === 'closed') return 'green';
    if (status === 'dispatched') return 'indigo';
    if (status === 'partially_received') return 'rose';
    if (status === 'requested') return 'amber';
    return 'slate';
};
</script>

<template>
    <AdminLayout title="Stock transfers">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="!filterForm.status ? 'bg-slate-900 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'"
                    @click="filterForm.status = ''; applyFilters()"
                >
                    All transfers
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="filterForm.status === 'in_transit' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'"
                    @click="filterForm.status = 'in_transit'; applyFilters()"
                >
                    In transit
                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-bold text-indigo-800">{{ inTransitCount }}</span>
                </button>
                <button
                    v-if="discrepancyCount > 0"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="filterForm.status === 'partially_received' ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-800 hover:bg-rose-100'"
                    @click="filterForm.status = 'partially_received'; applyFilters()"
                >
                    Discrepancies
                    <span class="rounded-full bg-rose-200 px-2 py-0.5 text-xs font-bold text-rose-900">{{ discrepancyCount }}</span>
                </button>
            </div>
            <Link
                :href="route('transfers.create')"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-slate-800"
            >
                New transfer
            </Link>
        </div>

        <Card class="mb-6">
            <form class="grid grid-cols-1 gap-4 sm:grid-cols-3" @submit.prevent="applyFilters">
                <div class="sm:col-span-2">
                    <TextInput
                        v-model="filterForm.search"
                        placeholder="Search transfer #, SKU or vehicle note…"
                        class="w-full"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <SelectInput v-model="filterForm.status" class="w-full" @change="applyFilters">
                        <option value="">All statuses</option>
                        <option value="in_transit">In transit (dispatched)</option>
                        <option v-for="st in statuses" :key="st.value" :value="st.value">{{ st.label }}</option>
                    </SelectInput>
                </div>
            </form>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Transfer #</th>
                            <th class="px-4 py-3">From → To</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Lines</th>
                            <th class="px-4 py-3">Vehicle / Driver</th>
                            <th class="px-4 py-3">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="t in transfers.data" :key="t.id" class="hover:bg-gray-50/70">
                            <td class="px-4 py-3">
                                <Link :href="route('transfers.show', t.id)" class="font-semibold text-indigo-600 hover:underline">
                                    {{ t.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-gray-900">{{ t.from_location?.code }}</span>
                                <span class="mx-1.5 text-gray-400">→</span>
                                <span class="font-medium text-gray-900">{{ t.to_location?.code }}</span>
                                <p class="text-xs text-gray-500">{{ t.from_location?.name }} to {{ t.to_location?.name }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="statusTone(t.status)">{{ t.status_label }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ t.lines_count }}</td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ t.vehicle_note || '—' }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                <div v-if="t.requested_by_name">Req: {{ t.requested_by_name }}</div>
                                <div v-if="t.dispatched_by_name">Sent: {{ t.dispatched_by_name }}</div>
                                <div v-if="t.received_by_name">Rcvd: {{ t.received_by_name }}</div>
                            </td>
                        </tr>
                        <tr v-if="transfers.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                No transfers match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="transfers.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

