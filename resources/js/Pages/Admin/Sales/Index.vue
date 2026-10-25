<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const channel = ref(props.filters.channel || 'all');
const locationId = ref(props.filters.location_id || '');

function applyFilters() {
    router.get(
        route('sales.index'),
        {
            search: search.value || undefined,
            status: status.value !== 'all' ? status.value : undefined,
            channel: channel.value !== 'all' ? channel.value : undefined,
            location_id: locationId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Sales Orders" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <Link
                    v-if="canManage"
                    :href="route('sales.orders.create')"
                    class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    + New sales order
                </Link>
            </SalesNav>

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Sales Orders</h1>
                    <p class="text-sm text-gray-500">Showroom, phone, WhatsApp & web orders with stock reservation, back-to-back POs, and fulfilment.</p>
                </div>

                <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Order #, Invoice #, customer, phone…"
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
                    <select
                        v-model="channel"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        @change="applyFilters"
                    >
                        <option value="all">All channels</option>
                        <option v-for="ch in channels" :key="ch.value" :value="ch.value">{{ ch.label }}</option>
                    </select>
                    <select
                        v-if="locations.length > 1"
                        v-model="locationId"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        @change="applyFilters"
                    >
                        <option value="">All accessible locations</option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
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
                            <th class="px-4 py-3">Order / Invoice</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Location / Channel</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Payment</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs">
                                <Link :href="route('sales.orders.show', o.id)" class="font-semibold text-indigo-600 hover:underline">
                                    {{ o.number }}
                                </Link>
                                <div v-if="o.invoice_number" class="text-[11px] text-gray-500">{{ o.invoice_number }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ o.customer_name || '—' }}</div>
                                <div v-if="o.customer_phone" class="font-mono text-xs text-gray-500">{{ o.customer_phone }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <div>{{ o.location_name }}</div>
                                <div class="text-gray-400">{{ o.channel_label }} · {{ o.fulfilment_type_label }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                    {{ o.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span>{{ o.payment_status_label }}</span>
                                <span
                                    v-if="o.advance_pending"
                                    class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
                                >
                                    Advance pending
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                                {{ formatMoney(o.grand_total) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-amber-700">
                                {{ formatMoney(o.balance_due) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('sales.orders.show', o.id)"
                                    class="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    View →
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!orders.data.length">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No sales orders found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

