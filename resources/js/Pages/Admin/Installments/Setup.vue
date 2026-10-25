<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ plans: Array, settings: Object, canManagePolicy: Boolean });

function planForm(plan = null) {
    return useForm({
        id: plan?.id ?? null,
        name: plan?.name ?? '',
        terms_text: (plan?.terms ?? []).join(', '),
        minimum_down_payment_percent: plan?.minimum_down_payment_percent ?? '',
        charge_method: plan?.charge_method ?? 'flat_bp',
        flat_percent: plan?.flat_percent ?? '10',
        document_fee: plan?.document_fee ?? '',
        late_fee_method: plan?.late_fee_method ?? '',
        late_fee_amount: plan?.late_fee_amount ?? '',
        late_fee_percent: plan?.late_fee_percent ?? '',
        grace_days: plan?.grace_days ?? '',
        is_active: plan?.is_active ?? false,
    });
}

const newPlan = planForm();
const existingPlans = props.plans.map((plan) => ({ plan, form: planForm(plan) }));
const policy = useForm({
    agreement_terms: props.settings.agreement_terms,
    tax_treatment: props.settings.tax_treatment,
    early_settlement_method: props.settings.early_settlement_method,
    charge_rebate_percent: props.settings.charge_rebate_percent,
    ownership_until_paid: props.settings.ownership_until_paid,
    reminder_stages: [...props.settings.reminder_stages],
});

function savePlan(form) {
    form.transform((data) => ({
        ...data,
        terms: data.terms_text.split(',').map((term) => Number(term.trim())).filter((term) => Number.isInteger(term) && term > 0),
    }));
    const options = { preserveScroll: true };
    if (form.id) form.put(route('installments.plans.update', form.id), options);
    else form.post(route('installments.plans.store'), options);
}

function saveExisting(item) {
    const form = item.form;
    form.transform((data) => ({
        ...data,
        terms: data.terms_text.split(',').map((term) => Number(term.trim())).filter((term) => Number.isInteger(term) && term > 0),
    })).put(route('installments.plans.update', item.plan.id), { preserveScroll: true });
}

function addReminderStage() {
    policy.reminder_stages.push('overdue:14');
}

function removeReminderStage(index) {
    policy.reminder_stages.splice(index, 1);
}

function savePolicy() {
    policy.put(route('installments.policy.update'), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="Installment setup">
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <div><h1 class="text-xl font-bold text-gray-900">Installment setup</h1><p class="mt-1 text-sm text-gray-500">Plan rates, fees and compliance wording are editable—not embedded in code.</p></div>
                <Link :href="route('installments.index')" class="text-sm font-semibold text-indigo-700">Collections</Link>
            </div>

            <section v-if="canManagePolicy" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Agreement wording, tax treatment and follow-up</h2>
                <p class="mt-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">The accountant's exact approved wording and tax treatment were not provided. Plans remain blocked until these and the ownership policy are explicitly configured.</p>
                <form class="mt-4 space-y-4" @submit.prevent="savePolicy">
                    <label class="block text-sm font-medium text-gray-700">Agreement terms
                        <textarea v-model="policy.agreement_terms" rows="7" class="mt-1 block w-full rounded-lg border-gray-300" placeholder="Enter the approved agreement terms, responsibilities, default and signature language." />
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-gray-700">Charge tax treatment
                            <select v-model="policy.tax_treatment" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option value="">Unset — accountant confirmation required</option>
                                <option value="non_taxable">Non-taxable</option>
                                <option v-if="settings.vat_registered" value="vat_inclusive">VAT-inclusive ({{ settings.vat_rate_percent }}%)</option>
                                <option v-if="settings.vat_registered" value="vat_exclusive">VAT-exclusive ({{ settings.vat_rate_percent }}%)</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">Early settlement
                            <select v-model="policy.early_settlement_method" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option value="">Unset — owner decision required</option>
                                <option value="none">No charge rebate</option>
                                <option value="charge_rebate">Rebate on remaining charges</option>
                            </select>
                        </label>
                        <label v-if="policy.early_settlement_method === 'charge_rebate'" class="text-sm font-medium text-gray-700">Rebate of remaining charges (%)
                            <input v-model="policy.charge_rebate_percent" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                        </label>
                        <label class="text-sm font-medium text-gray-700">Ownership until fully paid
                            <select v-model="policy.ownership_until_paid" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option value="">Unset — owner decision required</option>
                                <option value="yes">Ownership remains with the business</option>
                                <option value="no">Ownership transfers at sale</option>
                            </select>
                        </label>
                    </div>
                    <div>
                        <div class="flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-800">Reminder stages</h3><button type="button" class="text-sm font-semibold text-indigo-700" @click="addReminderStage">Add stage</button></div>
                        <p class="mt-1 text-xs text-gray-500">Format: before:1 sends one day before due; overdue:7 sends seven days after due. Each stage is sent once per installment.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <div v-for="(stage, index) in policy.reminder_stages" :key="index" class="flex items-center gap-2">
                                <input v-model="policy.reminder_stages[index]" class="w-36 rounded-lg border-gray-300 text-sm" />
                                <button type="button" class="text-sm text-red-600" @click="removeReminderStage(index)">Remove</button>
                            </div>
                        </div>
                    </div>
                    <p v-if="Object.keys(policy.errors).length" class="text-sm text-red-700">{{ Object.values(policy.errors)[0] }}</p>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="policy.processing">Save policy</button>
                </form>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Add a plan</h2>
                <p class="mt-1 text-sm text-gray-500">The agreed initial flat charge is 10% of the financed amount. Down payment, available terms and late-fee policy are owner-configured.</p>
                <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="savePlan(newPlan)">
                    <input v-model="newPlan.name" required maxlength="100" class="rounded-lg border-gray-300 text-sm" placeholder="Plan name" />
                    <input v-model="newPlan.terms_text" class="rounded-lg border-gray-300 text-sm" placeholder="Terms in months: 3, 6, 12" />
                    <input v-model="newPlan.minimum_down_payment_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Minimum down payment %" />
                    <select v-model="newPlan.charge_method" class="rounded-lg border-gray-300 text-sm"><option value="flat_bp">Flat charge on financed amount</option><option value="none">No charge</option></select>
                    <input v-if="newPlan.charge_method === 'flat_bp'" v-model="newPlan.flat_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Flat charge %" />
                    <input v-model="newPlan.document_fee" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Document fee (LKR)" />
                    <select v-model="newPlan.late_fee_method" class="rounded-lg border-gray-300 text-sm"><option value="">Late fee policy (unset)</option><option value="fixed">Fixed per overdue installment</option><option value="monthly_bp">Percentage per month</option></select>
                    <input v-if="newPlan.late_fee_method === 'fixed'" v-model="newPlan.late_fee_amount" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Fixed fee (LKR)" />
                    <input v-if="newPlan.late_fee_method === 'monthly_bp'" v-model="newPlan.late_fee_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Late fee per month %" />
                    <input v-model="newPlan.grace_days" type="number" min="0" class="rounded-lg border-gray-300 text-sm" placeholder="Grace days" />
                    <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="newPlan.is_active" type="checkbox" class="rounded border-gray-300" /> Active</label>
                    <p v-if="Object.keys(newPlan.errors).length" class="text-sm text-red-700 sm:col-span-2 lg:col-span-4">{{ Object.values(newPlan.errors)[0] }}</p>
                    <div class="sm:col-span-2 lg:col-span-4"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="newPlan.processing">Save plan</button></div>
                </form>
            </section>

            <section v-for="item in existingPlans" :key="item.plan.id" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center justify-between"><h2 class="font-semibold text-gray-900">{{ item.plan.name }}</h2><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="item.plan.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'">{{ item.plan.is_active ? 'Active' : 'Inactive' }}</span></div>
                <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="saveExisting(item)">
                    <input v-model="item.form.name" required maxlength="100" class="rounded-lg border-gray-300 text-sm" placeholder="Plan name" />
                    <input v-model="item.form.terms_text" class="rounded-lg border-gray-300 text-sm" placeholder="Terms in months: 3, 6, 12" />
                    <input v-model="item.form.minimum_down_payment_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Minimum down payment %" />
                    <select v-model="item.form.charge_method" class="rounded-lg border-gray-300 text-sm"><option value="flat_bp">Flat charge</option><option value="none">No charge</option></select>
                    <input v-if="item.form.charge_method === 'flat_bp'" v-model="item.form.flat_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Flat charge %" />
                    <input v-model="item.form.document_fee" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Document fee (LKR)" />
                    <select v-model="item.form.late_fee_method" class="rounded-lg border-gray-300 text-sm"><option value="">Late fee policy (unset)</option><option value="fixed">Fixed per overdue installment</option><option value="monthly_bp">Percentage per month</option></select>
                    <input v-if="item.form.late_fee_method === 'fixed'" v-model="item.form.late_fee_amount" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Fixed fee (LKR)" />
                    <input v-if="item.form.late_fee_method === 'monthly_bp'" v-model="item.form.late_fee_percent" inputmode="decimal" class="rounded-lg border-gray-300 text-sm" placeholder="Late fee per month %" />
                    <input v-model="item.form.grace_days" type="number" min="0" class="rounded-lg border-gray-300 text-sm" placeholder="Grace days" />
                    <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="item.form.is_active" type="checkbox" class="rounded border-gray-300" /> Active</label>
                    <p v-if="Object.keys(item.form.errors).length" class="text-sm text-red-700 sm:col-span-2 lg:col-span-4">{{ Object.values(item.form.errors)[0] }}</p>
                    <div class="sm:col-span-2 lg:col-span-4"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="item.form.processing">Save changes</button></div>
                </form>
            </section>
        </div>
    </AdminLayout>
</template>
