<script setup>
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { decimalToCents, formatMoney } from '@/lib/money';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    deposits: Object,
    safeBalance: Number,
    openShifts: Array,
    pendingCheques: Array,
});

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Colombo' });
const form = useForm({ source: 'safe', shift_id: '', cash_amount: '', cheque_ids: [], bank_account: '', slip_reference: '', deposited_on: today, note: '' });

const chequeTotal = computed(() => props.pendingCheques.filter((c) => form.cheque_ids.includes(c.id)).reduce((s, c) => s + c.amount, 0));
const total = computed(() => (decimalToCents(form.cash_amount) ?? 0) + chequeTotal.value);
const allDue = () => (form.cheque_ids = props.pendingCheques.filter((c) => c.due).map((c) => c.id));

const submit = () => form.post(route('cash.deposits.store'), { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <AdminLayout title="Bank deposits">
        <div class="mx-auto max-w-5xl space-y-5">
            <CashNav />

            <form class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <h2 class="font-semibold text-gray-900">Record a deposit</h2>
                    <p class="text-sm text-gray-500">Cash in the safe: <strong>{{ formatMoney(safeBalance) }}</strong> (cash drops less earlier safe deposits).</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm font-medium text-gray-700">Cash from
                        <select v-model="form.source" class="mt-1 block w-full rounded-lg border-gray-300">
                            <option value="safe">The safe</option>
                            <option value="shift" :disabled="!openShifts.length">An open shift's drawer</option>
                        </select>
                    </label>
                    <label v-if="form.source === 'shift'" class="text-sm font-medium text-gray-700">Shift
                        <select v-model="form.shift_id" class="mt-1 block w-full rounded-lg border-gray-300" required>
                            <option value="" disabled>Choose…</option>
                            <option v-for="s in openShifts" :key="s.id" :value="s.id">{{ s.label }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-gray-700">Cash amount (LKR)
                        <input v-model="form.cash_amount" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" placeholder="0.00" />
                    </label>
                </div>

                <div v-if="pendingCheques.length" class="rounded-xl bg-gray-50 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-800">Pending cheques on this slip</p>
                        <button type="button" class="text-xs font-semibold text-indigo-700" @click="allDue">Select all due</button>
                    </div>
                    <label v-for="c in pendingCheques" :key="c.id" class="flex items-center gap-3 border-b border-gray-200 py-1.5 text-sm last:border-0">
                        <input v-model="form.cheque_ids" type="checkbox" :value="c.id" class="rounded border-gray-300" />
                        <span class="flex-1">#{{ c.cheque_number }} {{ c.bank }} · {{ c.customer || 'Walk-in' }} · {{ c.number }}</span>
                        <span :class="c.due ? 'font-semibold text-amber-700' : 'text-gray-500'">{{ c.cheque_date }}{{ c.due ? ' (due)' : '' }}</span>
                        <span class="w-32 text-right font-mono">{{ formatMoney(c.amount) }}</span>
                    </label>
                </div>
                <p v-else class="text-sm text-gray-500">No pending cheques at this location.</p>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm font-medium text-gray-700">Bank account
                        <input v-model="form.bank_account" required maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300" placeholder="e.g. BOC Kandy 0081234567" />
                    </label>
                    <label class="text-sm font-medium text-gray-700">Slip reference
                        <input v-model="form.slip_reference" required maxlength="60" class="mt-1 block w-full rounded-lg border-gray-300 font-mono" />
                    </label>
                    <label class="text-sm font-medium text-gray-700">Deposited on
                        <input v-model="form.deposited_on" type="date" required :max="today" class="mt-1 block w-full rounded-lg border-gray-300" />
                    </label>
                </div>
                <input v-model="form.note" maxlength="255" class="block w-full rounded-lg border-gray-300 text-sm" placeholder="Note (optional)" />

                <p v-if="form.errors.deposit || Object.keys(form.errors).length" class="rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ form.errors.deposit || Object.values(form.errors)[0] }}</p>
                <div class="flex items-center justify-end gap-3">
                    <span class="text-sm text-gray-600">Slip total <strong class="font-mono">{{ formatMoney(total) }}</strong></span>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing || !total">Record deposit</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Slip</th><th class="px-4 py-3">From</th><th class="px-4 py-3 text-right">Cash</th><th class="px-4 py-3 text-right">Cheques</th><th class="px-4 py-3 text-right">Total</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!deposits.data.length"><td colspan="6" class="px-4 py-8 text-center text-gray-500">No deposits yet.</td></tr>
                        <tr v-for="d in deposits.data" :key="d.id">
                            <td class="px-4 py-3">{{ d.deposited_on }}<div class="text-xs text-gray-500">{{ d.by }}</div></td>
                            <td class="px-4 py-3"><span class="font-mono">{{ d.slip_reference }}</span><div class="text-xs text-gray-500">{{ d.bank_account }}</div></td>
                            <td class="px-4 py-3 text-xs">{{ d.source === 'shift' ? `Shift ${d.shift}` : 'Safe' }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(d.cash_amount) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(d.cheque_amount) }} <span class="text-xs text-gray-500">({{ d.cheque_count }})</span></td>
                            <td class="px-4 py-3 text-right font-mono font-semibold">{{ formatMoney(d.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="deposits" />
        </div>
    </AdminLayout>
</template>
