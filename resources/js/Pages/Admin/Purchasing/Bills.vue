<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    bills: { type: Object, required: true },
    payments: { type: Object, required: true },
    unpaidBills: { type: Array, default: () => [] },
    recentReceipts: { type: Array, default: () => [] },
    suppliers: { type: Array, required: true },
    statuses: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    filters: { type: Object, required: true },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: true },
    canViewCost: { type: Boolean, default: true },
});

const showBillModal = ref(false);
const showPaymentModal = ref(false);

const billForm = useForm({
    supplier_id: props.suppliers[0]?.id ?? '',
    supplier_invoice_number: '',
    bill_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    subtotal: '0.00',
    tax_total: '0.00',
    goods_receipt_ids: [],
    notes: '',
});

const supplierGrns = computed(() =>
    props.recentReceipts.filter((g) => Number(g.supplier_id) === Number(billForm.supplier_id)),
);

const paymentForm = useForm({
    supplier_id: props.suppliers[0]?.id ?? '',
    paid_at: new Date().toISOString().slice(0, 10),
    method: 'bank_transfer',
    reference: '',
    cheque_number: '',
    cheque_date: '',
    cheque_bank: '',
    notes: '',
    allocations: [],
});

const supplierUnpaidBills = computed(() =>
    props.unpaidBills.filter((b) => Number(b.supplier_id) === Number(paymentForm.supplier_id)),
);

watch(
    () => paymentForm.supplier_id,
    () => {
        paymentForm.allocations = supplierUnpaidBills.value.map((b) => ({
            supplier_bill_id: b.id,
            bill_number: b.number,
            supplier_invoice_number: b.supplier_invoice_number,
            balance_due_decimal: b.balance_due_decimal,
            amount: '0.00',
        }));
    },
    { immediate: true },
);

function submitBill() {
    billForm.post(route('purchasing.bills.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showBillModal.value = false;
            billForm.reset('supplier_invoice_number', 'subtotal', 'tax_total', 'goods_receipt_ids', 'notes');
        },
    });
}

function submitPayment() {
    paymentForm.post(route('purchasing.payments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showPaymentModal.value = false;
        },
    });
}

function formatMoney(cents) {
    if (cents === undefined || cents === null) return '—';
    return 'Rs. ' + (cents / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Supplier Bills & Payments" />

    <AdminLayout title="Supplier Bills & Payments">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <button
                type="button"
                class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                @click="showPaymentModal = true"
            >
                + Record payment
            </button>
            <button
                type="button"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-slate-800"
                @click="showBillModal = true"
            >
                + New supplier bill
            </button>
        </PurchasingNav>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Supplier Bills -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold text-gray-900">Supplier bills (invoices)</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Bill # / Inv #</th>
                            <th class="px-4 py-2.5">Supplier</th>
                            <th class="px-4 py-2.5">Due</th>
                            <th class="px-4 py-2.5 text-right">Total</th>
                            <th class="px-4 py-2.5 text-right">Balance</th>
                            <th class="px-4 py-2.5 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="b in bills.data" :key="b.id">
                            <td class="px-4 py-2.5">
                                <div class="font-mono font-semibold text-slate-900">{{ b.number }}</div>
                                <div class="text-xs text-gray-500">Inv: {{ b.supplier_invoice_number }}</div>
                                <div v-if="b.goods_receipts?.length" class="text-[11px] font-mono text-blue-700">
                                    GRNs: {{ b.goods_receipts.map((g) => g.number).join(', ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-gray-900">{{ b.supplier_name }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-600">{{ b.due_date }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(b.total_amount) }}</td>
                            <td
                                class="px-4 py-2.5 text-right font-mono"
                                :class="b.balance_due > 0 ? 'font-semibold text-amber-700' : 'text-gray-400'"
                            >
                                {{ formatMoney(b.balance_due) }}
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        b.status === 'paid'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : b.status === 'partially_paid'
                                              ? 'bg-amber-50 text-amber-800'
                                              : 'bg-red-50 text-red-700'
                                    "
                                >
                                    {{ b.status_label }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!bills.data.length">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">No supplier bills yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Supplier Payments -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold text-gray-900">Supplier payments</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Payment #</th>
                            <th class="px-4 py-2.5">Supplier</th>
                            <th class="px-4 py-2.5">Method</th>
                            <th class="px-4 py-2.5 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in payments.data" :key="p.id">
                            <td class="px-4 py-2.5">
                                <div class="font-mono font-semibold text-slate-900">{{ p.number }}</div>
                                <div class="text-xs text-gray-500">{{ p.paid_at }}</div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="text-gray-900">{{ p.supplier_name }}</div>
                                <div class="text-xs text-gray-500">
                                    <span v-for="a in p.allocations" :key="a.id" class="mr-2 font-mono">
                                        {{ a.bill_number }}: {{ formatMoney(a.amount) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-700">
                                <div>{{ p.method_label }}</div>
                                <div v-if="p.cheque_number" class="font-mono text-gray-500">
                                    Chq #{{ p.cheque_number }} ({{ p.cheque_bank || 'Bank' }})
                                </div>
                                <div v-else-if="p.reference" class="font-mono text-gray-500">Ref: {{ p.reference }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-emerald-700">
                                {{ formatMoney(p.amount) }}
                            </td>
                        </tr>
                        <tr v-if="!payments.data.length">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No supplier payments yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- New Bill Modal -->
        <div
            v-if="showBillModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="showBillModal = false"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">New supplier bill</h2>
                <form class="space-y-4" @submit.prevent="submitBill">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Supplier *</label>
                        <select v-model="billForm.supplier_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Supplier invoice # *</label>
                            <input
                                v-model="billForm.supplier_invoice_number"
                                type="text"
                                required
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <p v-if="billForm.errors.supplier_invoice_number" class="mt-1 text-xs text-red-600">
                                {{ billForm.errors.supplier_invoice_number }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Bill date</label>
                            <input v-model="billForm.bill_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Subtotal (Rs.) *</label>
                            <input v-model="billForm.subtotal" type="text" required class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Tax (Rs.)</label>
                            <input v-model="billForm.tax_total" type="text" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm" />
                        </div>
                    </div>
                    <div v-if="supplierGrns.length">
                        <label class="block text-xs font-medium text-gray-700">Link Goods Receipts (GRNs)</label>
                        <div class="mt-1 max-h-32 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 text-xs">
                            <label v-for="g in supplierGrns" :key="g.id" class="flex items-center gap-2">
                                <input v-model="billForm.goods_receipt_ids" type="checkbox" :value="g.id" class="rounded border-gray-300" />
                                <span class="font-mono">{{ g.number }}</span>
                                <span class="text-gray-500">(Rs. {{ g.total_decimal }})</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showBillModal = false">Cancel</button>
                        <button type="submit" :disabled="billForm.processing" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
                            Save bill
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Record Payment Modal -->
        <div
            v-if="showPaymentModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="showPaymentModal = false"
        >
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">Record supplier payment</h2>
                <form class="space-y-4" @submit.prevent="submitPayment">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Supplier *</label>
                            <select v-model="paymentForm.supplier_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Method *</label>
                            <select v-model="paymentForm.method" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="paymentForm.method === 'cheque'" class="grid grid-cols-3 gap-3 rounded-lg bg-gray-50 p-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Cheque # *</label>
                            <input v-model="paymentForm.cheque_number" type="text" required class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Cheque date</label>
                            <input v-model="paymentForm.cheque_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Bank</label>
                            <input v-model="paymentForm.cheque_bank" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Reference</label>
                        <input v-model="paymentForm.reference" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Allocate to unpaid bills</label>
                        <p v-if="paymentForm.errors.allocations" class="mt-1 text-xs text-red-600">{{ paymentForm.errors.allocations }}</p>
                        <div class="mt-2 space-y-2">
                            <div
                                v-for="alloc in paymentForm.allocations"
                                :key="alloc.supplier_bill_id"
                                class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm"
                            >
                                <div>
                                    <span class="font-mono font-semibold">{{ alloc.bill_number }}</span>
                                    <span class="ml-2 text-xs text-gray-500">
                                        (Inv {{ alloc.supplier_invoice_number }} — Due Rs. {{ alloc.balance_due_decimal }})
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="text-xs text-blue-600 hover:underline"
                                        @click="alloc.amount = alloc.balance_due_decimal"
                                    >
                                        Full
                                    </button>
                                    <input
                                        v-model="alloc.amount"
                                        type="text"
                                        class="w-28 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </div>
                            </div>
                            <p v-if="!paymentForm.allocations.length" class="text-xs text-gray-500">
                                No unpaid bills for this supplier.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showPaymentModal = false">Cancel</button>
                        <button
                            type="submit"
                            :disabled="paymentForm.processing || !paymentForm.allocations.length"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Save payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
