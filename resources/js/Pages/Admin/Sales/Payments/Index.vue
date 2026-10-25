<script setup>
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    payments: { type: Object, required: true },
    filters: { type: Object, required: true },
    methods: { type: Array, default: () => [] },
    chequeStatuses: { type: Array, default: () => [] },
    canManageCheques: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const methodId = ref(props.filters.method_id || '');
const cheque = ref(props.filters.cheque || '');

function applyFilters() {
    router.get(
        route('sales.payments.index'),
        { search: search.value || undefined, method_id: methodId.value || undefined, cheque: cheque.value || undefined },
        { preserveState: true, replace: true },
    );
}

const chequeForm = useForm({ status: '' });
function setCheque(payment, status) {
    if (status === 'bounced' && !confirm(`Mark cheque ${payment.cheque.number} as bounced? The payment is reversed and managers are notified.`)) return;
    chequeForm.status = status;
    chequeForm.post(route('sales.payments.cheque', payment.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Payments" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav />

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Payment receipts</h1>
                    <p class="text-sm text-gray-500">Every payment taken, with cheque tracking (pending → deposited → cleared or bounced).</p>
                </div>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                    <input v-model="search" type="search" placeholder="Receipt, invoice, customer, ref…" class="w-56 rounded-lg border-gray-300 text-sm" />
                    <select v-model="methodId" class="rounded-lg border-gray-300 text-sm" @change="applyFilters">
                        <option value="">All methods</option>
                        <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                    <select v-model="cheque" class="rounded-lg border-gray-300 text-sm" @change="applyFilters">
                        <option value="">Any cheque status</option>
                        <option v-for="s in chequeStatuses" :key="s.value" :value="s.value">Cheques: {{ s.label }}</option>
                    </select>
                </form>
            </div>

            <p v-if="chequeForm.errors.payment" class="mb-3 rounded-lg bg-red-50 p-3 text-xs text-red-700">{{ chequeForm.errors.payment }}</p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Receipt</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Customer / order</th>
                            <th class="px-4 py-3">Method</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!payments.data.length"><td colspan="6" class="px-4 py-8 text-center text-gray-500">No payments found.</td></tr>
                        <tr v-for="p in payments.data" :key="p.id" :class="!p.counts ? 'bg-gray-50 text-gray-400' : ''">
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs font-semibold">{{ p.number }}</div>
                                <div class="text-xs text-gray-500">{{ p.location_code }} · {{ p.received_by }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ p.date }}</td>
                            <td class="px-4 py-3 text-xs">
                                <div>{{ p.customer || 'Walk-in' }}</div>
                                <Link v-if="p.order_id" :href="route('sales.orders.show', p.order_id)" class="font-mono text-indigo-600 hover:underline">{{ p.order_number }}</Link>
                                <span v-else class="text-gray-400">On account</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div>{{ p.method }}<span v-if="p.is_voided" class="ml-1 rounded bg-red-100 px-1.5 text-[11px] font-semibold text-red-700">Voided</span></div>
                                <div v-if="p.reference" class="font-mono text-gray-500">{{ p.reference }}</div>
                                <div v-if="p.cheque" class="text-gray-600">
                                    #{{ p.cheque.number }} {{ p.cheque.bank }} · {{ p.cheque.date }} ·
                                    <strong :class="p.cheque.status === 'bounced' ? 'text-red-600' : p.cheque.status === 'cleared' ? 'text-emerald-700' : 'text-amber-700'">{{ p.cheque.status_label }}</strong>
                                    <div v-if="canManageCheques && !p.is_voided && p.cheque.next.length" class="mt-1 flex gap-1">
                                        <button
                                            v-for="s in p.cheque.next"
                                            :key="s.value"
                                            type="button"
                                            class="rounded border px-1.5 py-0.5 text-[11px]"
                                            :class="s.value === 'bounced' ? 'border-red-200 text-red-700' : 'border-gray-300 text-gray-700'"
                                            @click="setCheque(p, s.value)"
                                        >
                                            {{ s.label }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold" :class="!p.counts ? 'line-through' : ''">{{ formatMoney(p.amount) }}</td>
                            <td class="px-4 py-3 text-right text-xs"><a :href="p.pdf_url" target="_blank" class="text-indigo-600 hover:underline">PDF ↗</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4"><Pagination :paginator="payments" /></div>
        </div>
    </AdminLayout>
</template>
