<script setup>
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({ agreements: Object, filter: String, dueToday: Array, overdue: Array, arrears: Object, canManage: Boolean });
const filters = ['active', 'completed', 'defaulted', 'written_off', 'cancelled', 'all'];

function setFilter(status) {
    router.get(route('installments.index'), { status }, { preserveState: true, replace: true });
}
</script>

<template>
    <AdminLayout title="Installment collections">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-xl font-bold text-gray-900">Installment collections</h1><p class="mt-1 text-sm text-gray-500">Due amounts, arrears follow-up and active agreements.</p></div>
                <Link v-if="canManage" :href="route('installments.setup')" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Plans &amp; policy</Link>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">1–7 days overdue</p><p class="mt-1 text-2xl font-bold text-amber-700">{{ arrears.days_1_7 }}</p></div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">8–30 days overdue</p><p class="mt-1 text-2xl font-bold text-orange-700">{{ arrears.days_8_30 }}</p></div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">31+ days overdue</p><p class="mt-1 text-2xl font-bold text-red-700">{{ arrears.days_31_plus }}</p></div>
            </div>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                <div class="border-b border-gray-200 p-4"><h2 class="font-semibold text-gray-900">Due today</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase"><tr><th class="px-4 py-3">Customer / agreement</th><th class="px-4 py-3">Due date</th><th class="px-4 py-3 text-right">Outstanding</th><th class="px-4 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!dueToday.length"><td colspan="4" class="px-4 py-7 text-center text-gray-500">Nothing due today.</td></tr>
                            <tr v-for="line in dueToday" :key="line.id">
                                <td class="px-4 py-3"><Link :href="route('installments.show', line.agreement_id)" class="font-semibold text-indigo-700">{{ line.customer }} · {{ line.agreement_number }}</Link></td>
                                <td class="px-4 py-3">{{ line.due_date }}</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.remaining) }}</td>
                                <td class="px-4 py-3 text-right"><Link :href="route('installments.show', line.agreement_id)" class="text-xs font-semibold text-indigo-700">Collect</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                <div class="border-b border-gray-200 p-4"><h2 class="font-semibold text-gray-900">Arrears report</h2><p class="mt-1 text-xs text-gray-500">Outstanding schedule lines grouped by days overdue.</p></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase"><tr><th class="px-4 py-3">Customer / agreement</th><th class="px-4 py-3">Due date</th><th class="px-4 py-3">Age</th><th class="px-4 py-3 text-right">Outstanding</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!overdue.data.length"><td colspan="4" class="px-4 py-7 text-center text-gray-500">No overdue installments.</td></tr>
                            <tr v-for="line in overdue.data" :key="line.id">
                                <td class="px-4 py-3"><Link :href="route('installments.show', line.agreement_id)" class="font-semibold text-indigo-700">{{ line.customer }} · {{ line.agreement_number }}</Link></td>
                                <td class="px-4 py-3">{{ line.due_date }}</td><td class="px-4 py-3">{{ line.days_overdue }} days</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.remaining) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="overdue.links" />
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 p-4">
                    <h2 class="font-semibold text-gray-900">Agreements</h2>
                    <div class="flex flex-wrap gap-1">
                        <button v-for="status in filters" :key="status" type="button" class="rounded-full px-3 py-1 text-xs font-semibold capitalize" :class="filter === status ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700'" @click="setFilter(status)">{{ status.replace('_', ' ') }}</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase"><tr><th class="px-4 py-3">Agreement</th><th class="px-4 py-3">Customer / NIC</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Term</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Remaining</th><th class="px-4 py-3">Status</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!agreements.data.length"><td colspan="7" class="px-4 py-8 text-center text-gray-500">No agreements for this filter.</td></tr>
                            <tr v-for="agreement in agreements.data" :key="agreement.id">
                                <td class="px-4 py-3"><Link :href="route('installments.show', agreement.id)" class="font-semibold text-indigo-700">{{ agreement.number }}</Link><div class="text-xs text-gray-500">{{ agreement.sales_order }} · {{ agreement.plan }}</div></td>
                                <td class="px-4 py-3">{{ agreement.customer }}<div class="text-xs text-gray-500">{{ agreement.nic }}</div></td><td class="px-4 py-3">{{ agreement.location }}</td><td class="px-4 py-3">{{ agreement.term_months }} months</td>
                                <td class="px-4 py-3 text-right font-mono">{{ formatMoney(agreement.total_payable) }}</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(agreement.remaining) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs capitalize text-gray-700">{{ agreement.status.replace('_', ' ') }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="agreements.links" />
            </section>
        </div>
    </AdminLayout>
</template>
