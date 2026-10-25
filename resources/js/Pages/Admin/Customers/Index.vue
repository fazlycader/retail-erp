<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    customers: { type: Object, required: true },
    filters: { type: Object, required: true },
    customerTypes: { type: Array, default: () => [] },
    districts: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
const filter = ref(props.filters.filter || 'all');

function applyFilters() {
    router.get(
        route('customers.index'),
        {
            search: search.value || undefined,
            filter: filter.value !== 'all' ? filter.value : undefined,
        },
        { preserveState: true, replace: true },
    );
}

const showModal = ref(false);

const form = useForm({
    name: '',
    phone: '',
    secondary_phone: '',
    email: '',
    nic: '',
    type: 'individual',
    vat_number: '',
    credit_limit: '0.00',
    sms_opt_out: false,
    notes: '',
    addresses: [
        {
            label: 'Home',
            line1: '',
            line2: '',
            city: '',
            district: 'Colombo',
            maps_url: '',
            is_default: true,
        },
    ],
});

function openCreate() {
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function addAddress() {
    if (form.addresses.length >= 5) return;
    form.addresses.push({
        label: `Address ${form.addresses.length + 1}`,
        line1: '',
        line2: '',
        city: '',
        district: 'Colombo',
        maps_url: '',
        is_default: false,
    });
}

function removeAddress(idx) {
    form.addresses.splice(idx, 1);
}

function submit() {
    const cleanAddresses = form.addresses.filter((a) => a.line1 && a.line1.trim() !== '');
    form.transform((data) => ({
        ...data,
        addresses: cleanAddresses,
    })).post(route('customers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
        },
    });
}
</script>

<template>
    <Head title="Customers" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    @click="openCreate"
                >
                    + New customer
                </button>
            </SalesNav>

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Customers</h1>
                    <p class="text-sm text-gray-500">Lookup by phone, manage delivery addresses, view purchase history & balances.</p>
                </div>

                <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Phone (077…), name, NIC…"
                        class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <select
                        v-model="filter"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        @change="applyFilters"
                    >
                        <option value="all">All customers</option>
                        <option value="individual">Individual</option>
                        <option value="business">Business</option>
                        <option value="blacklisted">Blacklisted</option>
                    </select>
                    <button
                        type="submit"
                        class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                    >
                        Search
                    </button>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">Type / NIC / VAT</th>
                            <th class="px-4 py-3">Default address</th>
                            <th class="px-4 py-3 text-right">Orders</th>
                            <th class="px-4 py-3 text-right">Total spent</th>
                            <th class="px-4 py-3 text-right">Balance due</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="c in customers.data" :key="c.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link :href="route('customers.show', c.id)" class="font-semibold text-indigo-600 hover:underline">
                                        {{ c.name }}
                                    </Link>
                                    <span
                                        v-if="c.is_blacklisted"
                                        class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700"
                                    >
                                        Blacklisted
                                    </span>
                                </div>
                                <div v-if="c.email" class="text-xs text-gray-500">{{ c.email }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-800">
                                <div>{{ c.phone_display || c.phone }}</div>
                                <div v-if="c.secondary_phone_display" class="text-gray-400">{{ c.secondary_phone_display }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <span class="font-medium text-gray-800">{{ c.type_label }}</span>
                                <div v-if="c.nic">NIC: {{ c.nic }}</div>
                                <div v-if="c.vat_number">VAT: {{ c.vat_number }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <template v-if="c.default_address">
                                    <div>{{ c.default_address.line1 }}</div>
                                    <div class="text-gray-400">
                                        {{ [c.default_address.city, c.default_address.district].filter(Boolean).join(', ') }}
                                    </div>
                                </template>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-gray-800">
                                {{ c.total_orders_count }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-gray-800">
                                {{ formatMoney(c.total_spent) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                <span :class="c.balance_due > 0 ? 'font-semibold text-amber-700' : 'text-gray-400'">
                                    {{ formatMoney(c.balance_due) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('customers.show', c.id)"
                                    class="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    Profile →
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!customers.data.length">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No customers found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Create Modal -->
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-bold text-gray-900">New customer</h2>
                        <button type="button" class="text-gray-400 hover:text-gray-600" @click="showModal = false">✕</button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Full name / Company *</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Customer type</label>
                                <select v-model="form.type" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                    <option v-for="t in customerTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Primary phone * (+94 / 07X)</label>
                                <input
                                    v-model="form.phone"
                                    type="text"
                                    required
                                    placeholder="077 123 4567"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                                />
                                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Second phone</label>
                                <input
                                    v-model="form.secondary_phone"
                                    type="text"
                                    placeholder="011 234 5678"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                                />
                                <p v-if="form.errors.secondary_phone" class="mt-1 text-xs text-red-600">{{ form.errors.secondary_phone }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Email</label>
                                <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">NIC (optional)</label>
                                <input
                                    v-model="form.nic"
                                    type="text"
                                    placeholder="901234567V or 199012345678"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                                />
                                <p v-if="form.errors.nic" class="mt-1 text-xs text-red-600">{{ form.errors.nic }}</p>
                            </div>
                            <div v-if="form.type === 'business'">
                                <label class="block text-xs font-medium text-gray-700">Business VAT number</label>
                                <input v-model="form.vat_number" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Credit limit (LKR)</label>
                                <input v-model="form.credit_limit" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                        </div>

                        <!-- Addresses -->
                        <div class="border-t border-gray-200 pt-3">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-700 uppercase">Addresses (max 5)</span>
                                <button
                                    v-if="form.addresses.length < 5"
                                    type="button"
                                    class="text-xs font-semibold text-indigo-600 hover:underline"
                                    @click="addAddress"
                                >
                                    + Add address
                                </button>
                            </div>

                            <div
                                v-for="(addr, idx) in form.addresses"
                                :key="idx"
                                class="mb-3 rounded-lg border border-gray-200 bg-gray-50 p-3"
                            >
                                <div class="mb-2 flex items-center justify-between">
                                    <input
                                        v-model="addr.label"
                                        type="text"
                                        placeholder="Label (Home, Office…)"
                                        class="w-40 rounded border-gray-300 text-xs"
                                    />
                                    <button
                                        v-if="form.addresses.length > 1"
                                        type="button"
                                        class="text-xs text-red-600 hover:underline"
                                        @click="removeAddress(idx)"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <input
                                        v-model="addr.line1"
                                        type="text"
                                        placeholder="Street address *"
                                        class="rounded border-gray-300 text-xs sm:col-span-2"
                                    />
                                    <input v-model="addr.city" type="text" placeholder="City / Town" class="rounded border-gray-300 text-xs" />
                                    <select v-model="addr.district" class="rounded border-gray-300 text-xs">
                                        <option v-for="d in districts" :key="d" :value="d">{{ d }}</option>
                                    </select>
                                    <input
                                        v-model="addr.maps_url"
                                        type="url"
                                        placeholder="Google Maps link (https://maps.google.com/...)"
                                        class="rounded border-gray-300 text-xs sm:col-span-2"
                                    />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Notes</label>
                            <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></textarea>
                        </div>

                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input v-model="form.sms_opt_out" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Opt out of promotional SMS
                        </label>

                        <div class="flex justify-end gap-2 pt-3">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700"
                                @click="showModal = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                            >
                                Save customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

