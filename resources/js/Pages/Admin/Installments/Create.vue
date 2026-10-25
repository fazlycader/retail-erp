<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney, decimalToCents } from '@/lib/money';
import { postJson } from '@/lib/http';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    order: Object,
    plans: Array,
    paymentMethods: Array,
    approvers: Array,
    settingsReady: Boolean,
    today: String,
});

const form = useForm({
    sales_order_id: props.order.id,
    plan_id: props.plans[0]?.id ?? '',
    term_months: props.plans[0]?.terms?.[0] ?? '',
    down_payment: '',
    start_date: props.today,
    payment_method_id: props.paymentMethods.find((method) => method.code === 'cash')?.id ?? props.paymentMethods[0]?.id ?? '',
    tendered: '',
    reference: '',
    cheque_bank: '',
    cheque_branch: '',
    cheque_number: '',
    cheque_date: '',
    approver_id: '',
    pin: '',
    signed_agreement: null,
    guarantors: [],
});

const preview = ref(null);
const previewError = ref('');
const loadingPreview = ref(false);
const selectedPlan = computed(() => props.plans.find((plan) => Number(plan.id) === Number(form.plan_id)));
const selectedMethod = computed(() => props.paymentMethods.find((method) => Number(method.id) === Number(form.payment_method_id)));
const downPaymentCents = computed(() => decimalToCents(form.down_payment) ?? 0);
const previewKey = computed(() => [form.plan_id, form.term_months, downPaymentCents.value, form.start_date].join(':'));

watch(
    () => form.plan_id,
    () => {
        form.term_months = selectedPlan.value?.terms?.[0] ?? '';
        const plan = selectedPlan.value;
        if (plan && !form.down_payment && plan.minimum_down_payment_bp !== null) {
            const paid = Math.max(0, props.order.grand_total - props.order.balance_due);
            const minimumCents = Math.max(0, Math.round((props.order.grand_total * plan.minimum_down_payment_bp) / 10000) - paid);
            form.down_payment = (minimumCents / 100).toFixed(2);
        }
    },
);

watch(previewKey, async () => {
    preview.value = null;
    previewError.value = '';
    if (!form.plan_id || !form.term_months || !form.start_date) return;

    loadingPreview.value = true;
    const result = await postJson(route('installments.preview'), {
        order_id: props.order.id,
        plan_id: Number(form.plan_id),
        term_months: Number(form.term_months),
        down_payment_cents: downPaymentCents.value,
        start_date: form.start_date,
    });
    loadingPreview.value = false;
    if (result.ok) {
        preview.value = result.data;
    } else {
        previewError.value = result.data?.errors?.preview?.[0] || result.data?.message || 'Could not calculate this schedule.';
    }
});

function addGuarantor() {
    form.guarantors.push({ name: '', nic: '', phone: '', address: '' });
}

function downloadDraft() {
    if (!preview.value) return;
    const url = new URL(route('installments.draft-pdf'), window.location.origin);
    url.searchParams.set('order_id', props.order.id);
    url.searchParams.set('plan_id', form.plan_id);
    url.searchParams.set('term_months', form.term_months);
    url.searchParams.set('down_payment_cents', downPaymentCents.value);
    url.searchParams.set('start_date', form.start_date);
    window.open(url, '_blank', 'noopener');
}

function submit() {
    form.transform((data) => ({
        ...data,
        down_payment_cents: downPaymentCents.value,
        tendered_cents: decimalToCents(data.tendered),
    })).post(route('installments.store'), { forceFormData: true });
}
</script>

<template>
    <AdminLayout title="Create installment agreement">
        <div class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Create installment agreement</h1>
                    <p class="mt-1 text-sm text-gray-500">{{ order.invoice_number || order.number }} · {{ order.customer?.name || 'No customer' }} · Balance {{ formatMoney(order.balance_due) }}</p>
                </div>
                <Link :href="route('sales.orders.show', order.id)" class="text-sm font-semibold text-indigo-700">Back to order</Link>
            </div>

            <div v-if="!settingsReady" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Installment plan activation is blocked until an administrator configures the accountant-approved agreement wording and tax treatment, early-settlement rule, and ownership policy.
            </div>
            <div v-if="!order.customer?.nic" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                This customer needs a NIC on their customer record before financing can be created.
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="font-semibold text-gray-900">Plan and repayment</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="text-sm font-medium text-gray-700">Plan
                            <select v-model="form.plan_id" required class="mt-1 block w-full rounded-lg border-gray-300">
                                <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">Term
                            <select v-model="form.term_months" required class="mt-1 block w-full rounded-lg border-gray-300">
                                <option v-for="term in selectedPlan?.terms || []" :key="term" :value="term">{{ term }} months</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">Down payment (LKR)
                            <input v-model="form.down_payment" inputmode="decimal" required class="mt-1 block w-full rounded-lg border-gray-300" placeholder="0.00" />
                        </label>
                        <label class="text-sm font-medium text-gray-700">Schedule starts
                            <input v-model="form.start_date" type="date" required class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                    </div>
                    <div v-if="downPaymentCents > 0" class="mt-4 rounded-xl bg-gray-50 p-4">
                        <h3 class="text-sm font-semibold text-gray-800">Collect down payment</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <label class="text-sm text-gray-700">Method
                                <select v-model="form.payment_method_id" required class="mt-1 block w-full rounded-lg border-gray-300">
                                    <option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option>
                                </select>
                            </label>
                            <label v-if="selectedMethod?.code === 'cash'" class="text-sm text-gray-700">Cash tendered (LKR)
                                <input v-model="form.tendered" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                            </label>
                            <label v-else-if="selectedMethod?.requires_reference" class="text-sm text-gray-700">Reference
                                <input v-model="form.reference" required maxlength="100" class="mt-1 block w-full rounded-lg border-gray-300" />
                            </label>
                            <template v-if="selectedMethod?.code === 'cheque'">
                                <input v-model="form.cheque_bank" required maxlength="80" class="rounded-lg border-gray-300 text-sm" placeholder="Cheque bank" />
                                <input v-model="form.cheque_branch" maxlength="80" class="rounded-lg border-gray-300 text-sm" placeholder="Branch (optional)" />
                                <input v-model="form.cheque_number" required maxlength="30" class="rounded-lg border-gray-300 text-sm" placeholder="Cheque number" />
                                <input v-model="form.cheque_date" required type="date" class="rounded-lg border-gray-300 text-sm" />
                            </template>
                        </div>
                    </div>
                    <p v-if="previewError" class="mt-3 text-sm text-red-700">{{ previewError }}</p>
                    <div v-if="loadingPreview" class="mt-3 text-sm text-gray-500">Calculating schedule…</div>
                    <div v-if="preview" class="mt-4 overflow-x-auto rounded-xl border border-gray-200">
                        <div class="grid gap-2 bg-indigo-50 p-4 text-sm sm:grid-cols-5">
                            <div>Financed <strong class="block">{{ formatMoney(preview.financed_amount) }}</strong></div>
                            <div>Flat charge <strong class="block">{{ formatMoney(preview.flat_charge) }}</strong></div>
                            <div>Document fee <strong class="block">{{ formatMoney(preview.document_fee) }}</strong></div>
                            <div>Tax <strong class="block">{{ formatMoney(preview.tax_cents) }}</strong><span class="text-xs text-gray-600">{{ preview.tax_treatment?.replace('_', ' ') }}</span></div>
                            <div>Total payable <strong class="block">{{ formatMoney(preview.total_payable) }}</strong></div>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500"><tr><th class="px-4 py-2">Due</th><th class="px-4 py-2 text-right">Principal</th><th class="px-4 py-2 text-right">Charge</th><th class="px-4 py-2 text-right">Due</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(line, index) in preview.schedule" :key="line.due_date">
                                    <td class="px-4 py-2">{{ index + 1 }} · {{ line.due_date }}</td><td class="px-4 py-2 text-right">{{ formatMoney(line.principal) }}</td><td class="px-4 py-2 text-right">{{ formatMoney(line.charge) }}</td><td class="px-4 py-2 text-right font-semibold">{{ formatMoney(line.amount_due) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><h2 class="font-semibold text-gray-900">Guarantors (optional)</h2><p class="text-xs text-gray-500">This plan does not require guarantors; add one only if your policy calls for it.</p></div>
                        <button type="button" class="text-sm font-semibold text-indigo-700" @click="addGuarantor">Add guarantor</button>
                    </div>
                    <div v-for="(guarantor, index) in form.guarantors" :key="index" class="mt-3 grid gap-2 sm:grid-cols-2">
                        <input v-model="guarantor.name" required class="rounded-lg border-gray-300 text-sm" placeholder="Name" />
                        <input v-model="guarantor.nic" required class="rounded-lg border-gray-300 text-sm" placeholder="NIC" />
                        <input v-model="guarantor.phone" required class="rounded-lg border-gray-300 text-sm" placeholder="Phone" />
                        <input v-model="guarantor.address" required class="rounded-lg border-gray-300 text-sm" placeholder="Address" />
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="font-semibold text-gray-900">Signed agreement and approval</h2>
                    <p class="mt-1 text-sm text-gray-500">Download the draft agreement, obtain both signatures, then upload the signed scan to activate this agreement.</p>
                    <button v-if="preview" type="button" class="mt-3 rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700" @click="downloadDraft">Download draft agreement PDF</button>
                    <label class="mt-3 block text-sm font-medium text-gray-700">Signed agreement PDF or image
                        <input type="file" required accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm" @input="form.signed_agreement = $event.target.files[0]" />
                    </label>
                    <div v-if="approvers.length" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm text-gray-700">Manager approval (required for blacklist / credit-limit override)
                            <select v-model="form.approver_id" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option value="">Choose manager</option><option v-for="approver in approvers" :key="approver.id" :value="approver.id">{{ approver.name }}</option>
                            </select>
                        </label>
                        <label class="text-sm text-gray-700">Manager PIN
                            <input v-model="form.pin" type="password" inputmode="numeric" maxlength="6" class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                    </div>
                    <p v-if="Object.keys(form.errors).length" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ Object.values(form.errors)[0] }}</p>
                    <div class="mt-5 flex justify-end">
                        <button :disabled="form.processing || !preview || !settingsReady || !order.customer?.nic" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create signed agreement</button>
                    </div>
                </section>
            </form>
        </div>
    </AdminLayout>
</template>
