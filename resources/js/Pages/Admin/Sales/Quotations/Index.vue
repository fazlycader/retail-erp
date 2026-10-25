<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    quotations: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    followUpsDueCount: { type: Number, default: 0 },
    canManage: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const followUpDue = ref(!!props.filters.follow_up_due);

function applyFilters() {
    router.get(
        route('sales.quotations.index'),
        {
            search: search.value || undefined,
            status: status.value !== 'all' ? status.value : undefined,
            follow_up_due: followUpDue.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
}

function toggleFollowUpDue() {
    followUpDue.value = !followUpDue.value;
    applyFilters();
}
</script>

<template>
    <Head title="Quotations" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <Link
                    v-if="canManage"
                    :href="route('sales.quotations.create')"
                    class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    + New quotation
                </Link>
            </SalesNav>

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Quotations</h1>
                    <p class="text-sm text-gray-500">Prepare furniture & custom-order quotes, share on WhatsApp or email, and convert to orders.</p>
                </div>

                <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-xs font-semibold transition-colors"
                        :class="
                            followUpDue
                                ? 'border-amber-600 bg-amber-600 text-white'
                                : 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'
                        "
                        @click="toggleFollowUpDue"
                    >
                        Follow-ups due ({{ followUpsDueCount }})
                    </button>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Quote #, customer name, phone…"
                        class="w-60 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <select
                        v-model="status"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        @change="applyFilters"
                    >
                        <option value="all">All statuses</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Filter
                    </button>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Quotation #</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Valid until</th>
                            <th class="px-4 py-3">Follow-up</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="q in quotations.data" :key="q.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs font-semibold">
                                <Link :href="route('sales.quotations.show', q.id)" class="text-indigo-600 hover:underline">
                                    {{ q.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ q.customer_name || 'Walk-in customer' }}</div>
                                <div v-if="q.customer_phone" class="font-mono text-xs text-gray-500">{{ q.customer_phone }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ q.location_name }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                    {{ q.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ q.valid_until || '—' }}</td>
                            <td class="px-4 py-3 text-xs">
                                <span
                                    v-if="q.follow_up_date"
                                    :class="q.is_follow_up_due ? 'rounded bg-amber-100 px-2 py-0.5 font-semibold text-amber-800' : 'text-gray-600'"
                                >
                                    {{ q.follow_up_date }}
                                </span>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                                {{ formatMoney(q.grand_total) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('sales.quotations.show', q.id)"
                                    class="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    View →
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!quotations.data.length">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No quotations found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

