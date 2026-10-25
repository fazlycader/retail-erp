<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney, decimalToCents } from '@/lib/money';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ agreement: Object, paymentMethods: Array, canManage: Boolean });
const collection = useForm({
    payment_method_id: props.paymentMethods.find((method) => method.code === 'cash')?.id ?? props.paymentMethods[0]?.id ?? '',
    amount: (props.agreement.remaining / 100).toFixed(2),
    tendered: '',
    reference: '',
    cheque_bank: '',
    cheque_branch: '',
    cheque_number: '',
    cheque_date: '',
});
const contact = useForm({ schedule_id: '', notes: '', promise_to_pay_on: '' });
const statusForm = useForm({ status: 'defaulted', reason: '' });
const method = () => props.paymentMethods.find((item) => Number(item.id) === Number(collection.payment_method_id));
const outstanding = () => decimalToCents(collection.amount) ?? 0;

function submitCollection(routeName, amount = null) {
    collection.transform((data) => ({
        ...data,
        amount_cents: amount ?? outstanding(),
        tendered_cents: decimalToCents(data.tendered),
    })).post(route(routeName, props.agreement.id), { preserveScroll: true });
}

function submitContact() {
    contact.post(route('installments.contacts.store', props.agreement.id), { preserveScroll: true, onSuccess: () => contact.reset('notes', 'promise_to_pay_on') });
}

function changeStatus(status) {
    statusForm.status = status;
    statusForm.post(route('installments.status', props.agreement.id), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="`Agreement ${agreement.number}`">
        <div class="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-xl font-bold text-gray-900">{{ agreement.number }}</h1><p class="mt-1 text-sm text-gray-500">{{ agreement.customer }} · NIC {{ agreement.nic }} · {{ agreement.sales_order }}</p></div>
                <div class="flex flex-wrap gap-2">
                    <a :href="route('installments.pdf', agreement.id)" target="_blank" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Agreement PDF</a>
                    <a v-if="agreement.signed_agreement_available" :href="route('installments.signed', agreement.id)" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Signed scan</a>
                    <a :href="route('installments.customer-statement', agreement.customer_id)" target="_blank" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Customer statement</a>
                    <Link :href="route('installments.index')" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Collections</Link>
                </div>
            </div>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">Financed</p><p class="mt-1 font-mono font-semibold">{{ formatMoney(agreement.financed_amount) }}</p></div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">Charges</p><p class="mt-1 font-mono font-semibold">{{ formatMoney(agreement.charges) }}</p></div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">Total payable</p><p class="mt-1 font-mono font-semibold">{{ formatMoney(agreement.total_payable) }}</p></div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200"><p class="text-xs font-semibold text-gray-500 uppercase">Remaining</p><p class="mt-1 font-mono font-semibold text-indigo-700">{{ formatMoney(agreement.remaining) }}</p></div>
            </section>
            <p class="rounded-xl bg-white px-4 py-3 text-sm text-gray-600 ring-1 ring-gray-200">
                Tax: {{ formatMoney(agreement.tax_cents) }} ({{ agreement.tax_treatment.replace('_', ' ') }}).
                Ownership {{ agreement.ownership_until_paid ? 'remains with the business until fully paid' : 'transfers to the customer at sale' }}.
            </p>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                <div class="border-b border-gray-200 p-4"><h2 class="font-semibold text-gray-900">Repayment schedule</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase"><tr><th class="px-4 py-3">Due</th><th class="px-4 py-3 text-right">Principal</th><th class="px-4 py-3 text-right">Charge</th><th class="px-4 py-3 text-right">Installment</th><th class="px-4 py-3 text-right">Paid</th><th class="px-4 py-3 text-right">Late fee</th><th class="px-4 py-3">Status</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="line in agreement.schedule" :key="line.id">
                                <td class="px-4 py-3">{{ line.due_date }}</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.principal) }}</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.charge) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.amount_due) }}</td><td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.amount_paid) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ formatMoney(line.late_fee_charged - line.late_fee_paid) }}</td>
                                <td class="px-4 py-3 capitalize">{{ line.status.replace('_', ' ') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="agreement.status === 'active'" class="grid gap-5 lg:grid-cols-2">
                <form class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200" @submit.prevent="submitCollection('installments.collect')">
                    <h2 class="font-semibold text-gray-900">Record collection</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm text-gray-700">Method
                            <select v-model="collection.payment_method_id" required class="mt-1 block w-full rounded-lg border-gray-300"><option v-for="option in paymentMethods" :key="option.id" :value="option.id">{{ option.name }}</option></select>
                        </label>
                        <label class="text-sm text-gray-700">Amount (LKR)
                            <input v-model="collection.amount" required inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                        <label v-if="method()?.code === 'cash'" class="text-sm text-gray-700">Tendered (LKR)
                            <input v-model="collection.tendered" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                        <label v-else-if="method()?.requires_reference" class="text-sm text-gray-700">Reference
                            <input v-model="collection.reference" required maxlength="100" class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                        <template v-if="method()?.code === 'cheque'">
                            <input v-model="collection.cheque_bank" required class="rounded-lg border-gray-300 text-sm" placeholder="Cheque bank" />
                            <input v-model="collection.cheque_branch" class="rounded-lg border-gray-300 text-sm" placeholder="Branch (optional)" />
                            <input v-model="collection.cheque_number" required class="rounded-lg border-gray-300 text-sm" placeholder="Cheque number" />
                            <input v-model="collection.cheque_date" required type="date" class="rounded-lg border-gray-300 text-sm" />
                        </template>
                    </div>
                    <p v-if="Object.keys(collection.errors).length" class="text-sm text-red-700">{{ Object.values(collection.errors)[0] }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button :disabled="collection.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Record payment</button>
                        <button type="button" :disabled="collection.processing" class="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700" @click="submitCollection('installments.settle', agreement.settlement.total)">Settle in full · {{ formatMoney(agreement.settlement.total) }}</button>
                    </div>
                </form>

                <form class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200" @submit.prevent="submitContact">
                    <h2 class="font-semibold text-gray-900">Collection call log</h2>
                    <label class="block text-sm text-gray-700">Installment
                        <select v-model="contact.schedule_id" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">General agreement note</option><option v-for="line in agreement.schedule" :key="line.id" :value="line.id">{{ line.due_date }} · {{ formatMoney(line.remaining) }}</option></select>
                    </label>
                    <label class="block text-sm text-gray-700">Call / follow-up notes
                        <textarea v-model="contact.notes" required rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-gray-300" />
                    </label>
                    <label class="block text-sm text-gray-700">Promise to pay date (optional)
                        <input v-model="contact.promise_to_pay_on" type="date" class="mt-1 block w-full rounded-lg border-gray-300" />
                    </label>
                    <p v-if="Object.keys(contact.errors).length" class="text-sm text-red-700">{{ Object.values(contact.errors)[0] }}</p>
                    <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700" :disabled="contact.processing">Save call log</button>
                </form>
            </section>

            <section class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="font-semibold text-gray-900">Payment ledger</h2>
                    <div v-if="!agreement.payments.length" class="mt-3 text-sm text-gray-500">No installment collections recorded.</div>
                    <div v-for="payment in agreement.payments" :key="payment.id" class="mt-3 border-t border-gray-100 pt-3 text-sm">
                        <div class="flex justify-between"><span class="font-semibold">{{ payment.number }} · {{ payment.method }}</span><span class="font-mono">{{ formatMoney(payment.amount) }}</span></div>
                        <div class="text-xs text-gray-500">{{ payment.date }} · {{ payment.reference || 'No reference' }} · {{ payment.status }}</div>
                        <div v-for="(allocation, index) in payment.allocations" :key="index" class="mt-1 text-xs text-gray-600">{{ allocation.kind.replace('_', ' ') }} · {{ allocation.due_date }} · {{ formatMoney(allocation.amount) }}</div>
                    </div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="font-semibold text-gray-900">Call log</h2>
                    <div v-if="!agreement.contactLogs.length" class="mt-3 text-sm text-gray-500">No collection notes.</div>
                    <div v-for="(log, index) in agreement.contactLogs" :key="index" class="mt-3 border-t border-gray-100 pt-3">
                        <p class="whitespace-pre-wrap text-sm text-gray-800">{{ log.notes }}</p><p class="mt-1 text-xs text-gray-500">{{ log.user }} · {{ log.created_at }}<span v-if="log.promise_to_pay_on"> · promised {{ log.promise_to_pay_on }}</span></p>
                    </div>
                </div>
            </section>

            <section v-if="canManage && agreement.status === 'active'" class="flex flex-wrap gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <input v-model="statusForm.reason" required maxlength="255" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm" placeholder="Reason to close / write off agreement" />
                <button class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800" @click="changeStatus('defaulted')">Mark defaulted</button>
                <button class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-800" @click="changeStatus('written_off')">Write off</button>
                <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700" @click="changeStatus('cancelled')">Cancel</button>
                <p v-if="Object.keys(statusForm.errors).length" class="w-full text-sm text-red-700">{{ Object.values(statusForm.errors)[0] }}</p>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Agreement terms</h2><p class="mt-2 whitespace-pre-wrap text-sm text-gray-700">{{ agreement.terms }}</p>
                <p class="mt-2 text-xs text-gray-500">Charge tax treatment recorded: {{ agreement.tax_treatment.replace('_', ' ') }}</p>
            </section>
        </div>
    </AdminLayout>
</template>
