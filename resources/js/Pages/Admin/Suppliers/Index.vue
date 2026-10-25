<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');

function applyFilters() {
    router.get(
        route('suppliers.index'),
        {
            search: search.value || undefined,
            status: status.value !== 'all' ? status.value : undefined,
        },
        { preserveState: true, replace: true },
    );
}

const showModal = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    contact_person: '',
    phone: '',
    secondary_phone: '',
    email: '',
    address: '',
    payment_terms_days: 30,
    credit_limit: '0.00',
    bank_details: '',
    vat_number: '',
    notes: '',
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(supplier) {
    editingId.value = supplier.id;
    form.name = supplier.name || '';
    form.contact_person = supplier.contact_person || '';
    form.phone = supplier.phone || '';
    form.secondary_phone = supplier.secondary_phone || '';
    form.email = supplier.email || '';
    form.address = supplier.address || '';
    form.payment_terms_days = supplier.payment_terms_days ?? 30;
    form.credit_limit = ((supplier.credit_limit || 0) / 100).toFixed(2);
    form.bank_details = supplier.bank_details || '';
    form.vat_number = supplier.vat_number || '';
    form.notes = supplier.notes || '';
    form.is_active = !!supplier.is_active;
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    if (editingId.value) {
        form.put(route('suppliers.update', editingId.value), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
            },
        });
    } else {
        form.post(route('suppliers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
            },
        });
    }
}

function toggleActive(supplier) {
    router.patch(route('suppliers.toggle-active', supplier.id), {}, { preserveScroll: true });
}

function formatMoney(cents) {
    if (cents === undefined || cents === null) return '—';
    return 'Rs. ' + (cents / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Suppliers" />

    <AdminLayout title="Suppliers">
        <PurchasingNav :can-manage-bills="canManageBills">
            <button
                v-if="canManage"
                type="button"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-slate-800"
                @click="openCreate"
            >
                + New supplier
            </button>
        </PurchasingNav>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="search"
                placeholder="Search name, contact, phone, email, VAT…"
                class="w-72 rounded-lg border-gray-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                @keyup.enter="applyFilters"
            />
            <select
                v-model="status"
                class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                @change="applyFilters"
            >
                <option value="all">All statuses</option>
                <option value="active">Active only</option>
                <option value="inactive">Inactive</option>
            </select>
            <button
                type="button"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                @click="applyFilters"
            >
                Filter
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Terms</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Credit limit</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Payable balance</th>
                        <th class="px-4 py-3 text-center">Open POs</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th v-if="canManage" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="s in suppliers.data" :key="s.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ s.name }}</div>
                            <div v-if="s.vat_number" class="text-xs text-gray-500">VAT: {{ s.vat_number }}</div>
                            <div v-if="s.address" class="mt-0.5 text-xs text-gray-500">{{ s.address }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            <div v-if="s.contact_person" class="font-medium">{{ s.contact_person }}</div>
                            <div v-if="s.phone" class="text-xs text-gray-500">
                                {{ s.phone }}<span v-if="s.secondary_phone"> / {{ s.secondary_phone }}</span>
                            </div>
                            <div v-if="s.email" class="text-xs text-gray-500">{{ s.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ s.payment_terms_days }} days</td>
                        <td v-if="canViewCost" class="px-4 py-3 text-right font-mono text-gray-700">
                            {{ formatMoney(s.credit_limit) }}
                        </td>
                        <td
                            v-if="canViewCost"
                            class="px-4 py-3 text-right font-mono"
                            :class="s.balance_due > 0 ? 'font-semibold text-amber-700' : 'text-gray-500'"
                        >
                            {{ formatMoney(s.balance_due) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                {{ s.open_pos_count }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="s.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'"
                            >
                                {{ s.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-2">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-slate-700 hover:text-slate-900"
                                    @click="openEdit(s)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-gray-500 hover:text-gray-800"
                                    @click="toggleActive(s)"
                                >
                                    {{ s.is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!suppliers.data.length">
                        <td :colspan="canViewCost ? 8 : 6" class="px-4 py-10 text-center text-gray-500">
                            No suppliers found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Supplier Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="showModal = false"
        >
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">
                        {{ editingId ? 'Edit supplier' : 'New supplier' }}
                    </h2>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="showModal = false">✕</button>
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Supplier name *</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Contact person</label>
                            <input v-model="form.contact_person" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Email</label>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Primary phone</label>
                            <input v-model="form.phone" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Secondary phone</label>
                            <input v-model="form.secondary_phone" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Payment terms (days)</label>
                            <input
                                v-model.number="form.payment_terms_days"
                                type="number"
                                min="0"
                                max="365"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Credit limit (Rs.)</label>
                            <input v-model="form.credit_limit" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">VAT number</label>
                            <input v-model="form.vat_number" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div class="flex items-center pt-5">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-slate-900" />
                                Active supplier
                            </label>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Address</label>
                            <textarea v-model="form.address" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Bank details (encrypted at rest)</label>
                            <textarea
                                v-model="form.bank_details"
                                rows="2"
                                placeholder="Bank name, branch, account number, beneficiary…"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Notes</label>
                            <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            {{ editingId ? 'Save changes' : 'Create supplier' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
