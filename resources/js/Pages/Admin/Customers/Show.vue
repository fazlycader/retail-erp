<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    customer: { type: Object, required: true },
    salesOrders: { type: Array, default: () => [] },
    openOrdersCount: { type: Number, default: 0 },
    quotations: { type: Array, default: () => [] },
    customerTypes: { type: Array, default: () => [] },
    districts: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
});

const showEdit = ref(false);

const form = useForm({
    name: props.customer.name || '',
    phone: props.customer.phone || '',
    secondary_phone: props.customer.secondary_phone || '',
    email: props.customer.email || '',
    nic: props.customer.nic || '',
    type: props.customer.type || 'individual',
    vat_number: props.customer.vat_number || '',
    credit_limit: props.customer.credit_limit_decimal || '0.00',
    sms_opt_out: !!props.customer.sms_opt_out,
    is_blacklisted: !!props.customer.is_blacklisted,
    blacklist_reason: props.customer.blacklist_reason || '',
    notes: props.customer.notes || '',
    addresses: props.customer.addresses?.length
        ? JSON.parse(JSON.stringify(props.customer.addresses))
        : [],
});

function addAddress() {
    if (form.addresses.length >= 5) return;
    form.addresses.push({
        label: `Address ${form.addresses.length + 1}`,
        line1: '',
        line2: '',
        city: '',
        district: 'Colombo',
        maps_url: '',
        is_default: form.addresses.length === 0,
    });
}

function removeAddress(idx) {
    form.addresses.splice(idx, 1);
}

function submitUpdate() {
    const cleanAddresses = form.addresses.filter((a) => a.line1 && a.line1.trim() !== '');
    form.transform((data) => ({
        ...data,
        addresses: cleanAddresses,
    })).put(route('customers.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEdit.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Customer — ${customer.name}`" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <Link
                    v-if="canManage"
                    :href="route('sales.orders.create')"
                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                >
                    + New sales order
                </Link>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                    @click="showEdit = !showEdit"
                >
                    {{ showEdit ? 'Close edit' : 'Edit customer' }}
                </button>
            </SalesNav>

            <!-- Summary Banner -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-gray-900">{{ customer.name }}</h1>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                {{ customer.type_label }}
                            </span>
                            <span
                                v-if="customer.is_blacklisted"
                                class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700"
                            >
                                Blacklisted: {{ customer.blacklist_reason || 'Restricted' }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-600">
                            <span><strong>Phone:</strong> {{ customer.phone_display || customer.phone }}</span>
                            <span v-if="customer.secondary_phone_display">
                                <strong>Alt:</strong> {{ customer.secondary_phone_display }}
                            </span>
                            <span v-if="customer.email"><strong>Email:</strong> {{ customer.email }}</span>
                            <span v-if="customer.nic"><strong>NIC:</strong> {{ customer.nic }}</span>
                            <span v-if="customer.vat_number"><strong>VAT:</strong> {{ customer.vat_number }}</span>
                        </div>
                        <p v-if="customer.notes" class="mt-2 text-xs text-gray-500">{{ customer.notes }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div class="rounded-lg bg-gray-50 p-3 text-right">
                            <div class="text-xs text-gray-500">Total orders</div>
                            <div class="text-lg font-bold text-gray-900">{{ customer.total_orders_count }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 text-right">
                            <div class="text-xs text-gray-500">Open orders</div>
                            <div class="text-lg font-bold text-indigo-700">{{ openOrdersCount }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 text-right">
                            <div class="text-xs text-gray-500">Lifetime spent</div>
                            <div class="font-mono text-sm font-bold text-gray-900">{{ formatMoney(customer.total_spent) }}</div>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-3 text-right">
                            <div class="text-xs text-amber-700">Balance due</div>
                            <div class="font-mono text-sm font-bold text-amber-800">{{ formatMoney(customer.balance_due) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Addresses list -->
                <div v-if="customer.addresses && customer.addresses.length" class="mt-4 border-t border-gray-100 pt-4">
                    <h3 class="mb-2 text-xs font-semibold tracking-wider text-gray-500 uppercase">Saved Addresses</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div
                            v-for="(addr, idx) in customer.addresses"
                            :key="idx"
                            class="rounded-lg border border-gray-200 p-3 text-xs"
                        >
                            <div class="flex items-center justify-between font-semibold text-gray-800">
                                <span>{{ addr.label || 'Address' }}</span>
                                <span v-if="addr.is_default" class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-700">
                                    Default
                                </span>
                            </div>
                            <div class="mt-1 text-gray-600">{{ addr.line1 }}</div>
                            <div v-if="addr.line2" class="text-gray-500">{{ addr.line2 }}</div>
                            <div class="text-gray-500">{{ [addr.city, addr.district].filter(Boolean).join(', ') }}</div>
                            <a
                                v-if="addr.maps_url"
                                :href="addr.maps_url"
                                target="_blank"
                                rel="noopener"
                                class="mt-1 inline-block text-indigo-600 hover:underline"
                            >
                                Google Maps ↗
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inline Edit Form -->
            <div v-if="showEdit" class="mb-6 rounded-xl border border-indigo-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-base font-bold text-gray-900">Edit customer details</h2>
                <form class="space-y-4" @submit.prevent="submitUpdate">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Name *</label>
                            <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Phone *</label>
                            <input v-model="form.phone" type="text" required class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Second phone</label>
                            <input v-model="form.secondary_phone" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Email</label>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">NIC</label>
                            <input v-model="form.nic" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                            <p v-if="form.errors.nic" class="mt-1 text-xs text-red-600">{{ form.errors.nic }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Type</label>
                            <select v-model="form.type" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="t in customerTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">VAT number</label>
                            <input v-model="form.vat_number" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Credit limit (LKR)</label>
                            <input v-model="form.credit_limit" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                    </div>

                    <!-- Addresses editor -->
                    <div class="border-t border-gray-100 pt-3">
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
                            class="mb-2 grid grid-cols-1 gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:grid-cols-5"
                        >
                            <input v-model="addr.label" type="text" placeholder="Label" class="rounded border-gray-300 text-xs" />
                            <input v-model="addr.line1" type="text" placeholder="Address line 1 *" class="rounded border-gray-300 text-xs" />
                            <input v-model="addr.city" type="text" placeholder="City" class="rounded border-gray-300 text-xs" />
                            <select v-model="addr.district" class="rounded border-gray-300 text-xs">
                                <option v-for="d in districts" :key="d" :value="d">{{ d }}</option>
                            </select>
                            <div class="flex items-center gap-2">
                                <input v-model="addr.maps_url" type="url" placeholder="Maps URL" class="w-full rounded border-gray-300 text-xs" />
                                <button type="button" class="text-xs text-red-600" @click="removeAddress(idx)">✕</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-6 border-t border-gray-100 pt-3">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input v-model="form.sms_opt_out" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            SMS opt-out
                        </label>
                        <label class="flex items-center gap-2 text-xs font-medium text-red-700">
                            <input v-model="form.is_blacklisted" type="checkbox" class="rounded border-gray-300 text-red-600" />
                            Blacklist customer
                        </label>
                        <input
                            v-if="form.is_blacklisted"
                            v-model="form.blacklist_reason"
                            type="text"
                            placeholder="Reason for blacklisting…"
                            class="w-72 rounded border-gray-300 text-xs"
                        />
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">
                            Update customer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sales Orders History -->
            <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h2 class="text-sm font-bold text-gray-900">Sales Orders & Purchase History</h2>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2.5">Order #</th>
                            <th class="px-4 py-2.5">Invoice #</th>
                            <th class="px-4 py-2.5">Location</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Payment</th>
                            <th class="px-4 py-2.5 text-right">Total</th>
                            <th class="px-4 py-2.5 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="o in salesOrders" :key="o.id" class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold">
                                <Link :href="route('sales.orders.show', o.id)" class="text-indigo-600 hover:underline">{{ o.number }}</Link>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-600">{{ o.invoice_number || '—' }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-600">{{ o.location_name }}</td>
                            <td class="px-4 py-2.5 text-xs">{{ o.status_label }}</td>
                            <td class="px-4 py-2.5 text-xs">{{ o.payment_status_label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ formatMoney(o.grand_total) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-amber-700">{{ formatMoney(o.balance_due) }}</td>
                        </tr>
                        <tr v-if="!salesOrders.length">
                            <td colspan="7" class="px-4 py-6 text-center text-xs text-gray-500">No sales orders yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Quotations -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h2 class="text-sm font-bold text-gray-900">Quotations</h2>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2.5">Quotation #</th>
                            <th class="px-4 py-2.5">Location</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Valid until</th>
                            <th class="px-4 py-2.5 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="q in quotations" :key="q.id" class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold">
                                <Link :href="route('sales.quotations.show', q.id)" class="text-indigo-600 hover:underline">{{ q.number }}</Link>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-600">{{ q.location_name }}</td>
                            <td class="px-4 py-2.5 text-xs">{{ q.status_label }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-600">{{ q.valid_until || '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ formatMoney(q.grand_total) }}</td>
                        </tr>
                        <tr v-if="!quotations.length">
                            <td colspan="5" class="px-4 py-6 text-center text-xs text-gray-500">No quotations yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

