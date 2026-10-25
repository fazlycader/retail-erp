<script setup>
import CustomSpecModal from '@/Components/Sales/CustomSpecModal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { getJson, postJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    order: { type: Object, default: null },
    locations: { type: Array, default: () => [] },
    fulfilLocations: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    channels: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    fulfilmentTypes: { type: Array, default: () => [] },
    lineSources: { type: Array, default: () => [] },
    pricingConfig: { type: Object, required: true },
    approversByLocation: { type: Object, default: () => ({}) },
    canOverride: { type: Boolean, default: false },
});

const selectedCustomer = ref(props.order?.customer || null);
const customerSearch = ref('');
const customerResults = ref([]);
const showNewCustomer = ref(false);
const newCustomerForm = ref({ name: '', phone: '', email: '' });
const newCustomerError = ref('');

const catalogQuery = ref('');
const catalogResults = ref([]);

const form = useForm({
    customer_id: props.order?.customer_id || null,
    location_id: props.order?.location_id || props.defaultLocationId || props.locations[0]?.id || '',
    channel: props.order?.channel || 'showroom',
    kind: props.order?.kind || 'order',
    fulfilment_type: props.order?.fulfilment_type || 'collect',
    delivery_address: props.order?.delivery_address || {
        line1: '',
        city: '',
        district: 'Colombo',
    },
    requested_delivery_date: props.order?.requested_delivery_date || '',
    order_discount_type: props.order?.order_discount_type || '',
    order_discount_value: props.order?.order_discount_value || '',
    delivery_charge: props.order?.delivery_charge || '0.00',
    notes: props.order?.notes || '',
    approver_id: '',
    pin: '',
    confirm_now: false,
    lines: props.order?.lines?.length ? JSON.parse(JSON.stringify(props.order.lines)) : [],
});

async function searchCustomers() {
    if (!customerSearch.value.trim()) {
        customerResults.value = [];
        return;
    }
    const { ok, data } = await getJson(route('customers.lookup', { q: customerSearch.value.trim() }));
    if (ok) {
        customerResults.value = data.customers || [];
    }
}

function selectCustomer(c) {
    selectedCustomer.value = c;
    form.customer_id = c.id;
    if (c.default_address && (!form.delivery_address?.line1 || form.delivery_address.line1 === '')) {
        form.delivery_address = { ...c.default_address };
    }
    customerResults.value = [];
    customerSearch.value = '';
}

async function createInlineCustomer() {
    newCustomerError.value = '';
    const { ok, data } = await postJson(route('customers.store'), newCustomerForm.value);
    if (!ok) {
        newCustomerError.value = data.message || 'Check customer name and phone.';
        return;
    }
    selectCustomer(data.customer);
    showNewCustomer.value = false;
    newCustomerForm.value = { name: '', phone: '', email: '' };
}

async function searchCatalog() {
    if (!catalogQuery.value.trim()) {
        catalogResults.value = [];
        return;
    }
    const { ok, data } = await getJson(
        route('sales.catalog-lookup', {
            q: catalogQuery.value.trim(),
            location_id: form.location_id,
        }),
    );
    if (ok) {
        catalogResults.value = data.variants || [];
    }
}

function addVariantLine(v) {
    form.lines.push({
        product_variant_id: v.id,
        is_custom: false,
        is_service: !!v.is_service,
        sku_snapshot: v.sku,
        name_snapshot: v.display_name,
        custom_spec: '',
        custom_spec_id: null,
        custom_spec_detail: null,
        source: v.default_source || 'stock',
        fulfil_location_id: form.location_id,
        quantity: '1',
        unit_price: v.price_decimal,
        min_price_cents: v.min_price_cents || 0,
        discount_type: '',
        discount_value: '',
        note: '',
    });
    catalogResults.value = [];
    catalogQuery.value = '';
}

function addCustomLine() {
    form.lines.push({
        product_variant_id: null,
        is_custom: true,
        is_service: false,
        sku_snapshot: 'CUSTOM',
        name_snapshot: '',
        custom_spec: '',
        custom_spec_id: null,
        custom_spec_detail: null,
        source: 'make',
        fulfil_location_id: form.location_id,
        quantity: '1',
        unit_price: '0.00',
        min_price_cents: 0,
        discount_type: '',
        discount_value: '',
        note: '',
    });
}

function removeLine(idx) {
    form.lines.splice(idx, 1);
}

const editingSpecIndex = ref(null);
function openSpecModal(idx) {
    editingSpecIndex.value = idx;
}
function onSpecSaved(spec) {
    if (editingSpecIndex.value === null) return;
    const line = form.lines[editingSpecIndex.value];
    if (line) {
        line.custom_spec_id = spec.id;
        line.custom_spec_detail = spec;
        if (spec.summary) {
            line.custom_spec = spec.summary;
        }
    }
}

const currentApprovers = computed(() => props.approversByLocation[form.location_id] || []);

const estimatedSubtotalCents = computed(() => {
    return form.lines.reduce((sum, l) => {
        const qty = parseFloat(l.quantity || '0') || 0;
        const unitCents = Math.round((parseFloat(l.unit_price || '0') || 0) * 100);
        const gross = Math.round(qty * unitCents);
        let disc = 0;
        if (l.discount_type === 'fixed') {
            disc = Math.round((parseFloat(l.discount_value || '0') || 0) * 100);
        } else if (l.discount_type === 'percent') {
            disc = Math.round((gross * (parseFloat(l.discount_value || '0') || 0)) / 100);
        }
        return sum + Math.max(0, gross - disc);
    }, 0);
});

function submit(confirmNow = false) {
    form.confirm_now = confirmNow;
    if (props.order) {
        form.put(route('sales.orders.update', props.order.id));
    } else {
        form.post(route('sales.orders.store'));
    }
}
</script>

<template>
    <Head :title="order ? `Edit ${order.number}` : 'New sales order'" />

    <AdminLayout>
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <Link :href="route('sales.index')" class="text-xs font-semibold text-gray-600 hover:text-gray-900">
                    ← Back to sales orders
                </Link>
            </SalesNav>

            <form class="space-y-6" @submit.prevent="submit(false)">
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h1 class="mb-4 text-lg font-bold text-gray-900">
                        {{ order ? `Edit Sales Order ${order.number}` : 'New Sales Order' }}
                    </h1>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <!-- Customer lookup -->
                        <div class="sm:col-span-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium text-gray-700">Customer (required to confirm)</label>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-indigo-600 hover:underline"
                                    @click="showNewCustomer = !showNewCustomer"
                                >
                                    + New customer
                                </button>
                            </div>
                            <div
                                v-if="selectedCustomer"
                                class="mt-1 flex items-center justify-between rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs"
                            >
                                <div>
                                    <div class="font-semibold text-indigo-950">{{ selectedCustomer.name }}</div>
                                    <div class="font-mono text-indigo-700">{{ selectedCustomer.phone_display || selectedCustomer.phone }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="text-indigo-600 hover:text-indigo-900"
                                    @click="
                                        selectedCustomer = null;
                                        form.customer_id = null;
                                    "
                                >
                                    Change
                                </button>
                            </div>
                            <div v-else class="relative mt-1">
                                <input
                                    v-model="customerSearch"
                                    type="text"
                                    placeholder="Type phone 077… or name"
                                    class="w-full rounded-lg border-gray-300 text-sm"
                                    @input="searchCustomers"
                                />
                                <div
                                    v-if="customerResults.length"
                                    class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                                >
                                    <button
                                        v-for="c in customerResults"
                                        :key="c.id"
                                        type="button"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-indigo-50"
                                        @click="selectCustomer(c)"
                                    >
                                        <span class="font-semibold text-gray-900">{{ c.name }}</span>
                                        <span class="font-mono text-gray-500">{{ c.phone_display || c.phone }}</span>
                                    </button>
                                </div>
                            </div>

                            <div v-if="showNewCustomer" class="mt-2 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <input
                                    v-model="newCustomerForm.name"
                                    type="text"
                                    placeholder="Customer name *"
                                    class="w-full rounded border-gray-300 text-xs"
                                />
                                <input
                                    v-model="newCustomerForm.phone"
                                    type="text"
                                    placeholder="Phone (077 123 4567) *"
                                    class="w-full rounded border-gray-300 text-xs"
                                />
                                <p v-if="newCustomerError" class="text-xs text-red-600">{{ newCustomerError }}</p>
                                <button
                                    type="button"
                                    class="w-full rounded bg-indigo-600 py-1.5 text-xs font-semibold text-white"
                                    @click="createInlineCustomer"
                                >
                                    Create & select
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Selling location *</label>
                            <select v-model="form.location_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name }} ({{ loc.code }})</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Channel</label>
                            <select v-model="form.channel" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="ch in channels" :key="ch.value" :value="ch.value">{{ ch.label }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Order kind</label>
                            <select v-model="form.kind" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Fulfilment</label>
                            <select v-model="form.fulfilment_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option v-for="f in fulfilmentTypes" :key="f.value" :value="f.value">{{ f.label }}</option>
                            </select>
                        </div>

                        <div v-if="form.fulfilment_type === 'deliver'">
                            <label class="block text-xs font-medium text-gray-700">Requested delivery date</label>
                            <input v-model="form.requested_delivery_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>

                        <div v-if="form.fulfilment_type === 'deliver'" class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Delivery address</label>
                            <div class="mt-1 grid grid-cols-3 gap-2">
                                <input
                                    v-model="form.delivery_address.line1"
                                    type="text"
                                    placeholder="Street address"
                                    class="col-span-2 rounded-lg border-gray-300 text-xs"
                                />
                                <input
                                    v-model="form.delivery_address.city"
                                    type="text"
                                    placeholder="City / District"
                                    class="rounded-lg border-gray-300 text-xs"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lines -->
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-bold text-gray-900">Order Lines</h2>
                        <div class="flex items-center gap-2">
                            <div class="relative w-72">
                                <input
                                    v-model="catalogQuery"
                                    type="text"
                                    placeholder="Search catalog by SKU, barcode, name…"
                                    class="w-full rounded-lg border-gray-300 text-xs"
                                    @input="searchCatalog"
                                />
                                <div
                                    v-if="catalogResults.length"
                                    class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                                >
                                    <button
                                        v-for="v in catalogResults"
                                        :key="v.id"
                                        type="button"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-indigo-50"
                                        @click="addVariantLine(v)"
                                    >
                                        <div>
                                            <div class="font-semibold text-gray-900">{{ v.display_name }}</div>
                                            <div class="font-mono text-[11px] text-gray-500">
                                                {{ v.sku }} · Avail: {{ v.available_display }}
                                            </div>
                                        </div>
                                        <span class="font-mono font-semibold text-indigo-700">LKR {{ v.price_decimal }}</span>
                                    </button>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                @click="addCustomLine"
                            >
                                + Custom made-to-order line
                            </button>
                        </div>
                    </div>

                    <p v-if="form.errors.lines" class="mb-3 text-xs font-medium text-red-600">{{ form.errors.lines }}</p>

                    <div class="space-y-3">
                        <div
                            v-for="(line, idx) in form.lines"
                            :key="idx"
                            class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs"
                        >
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-3">
                                    <label class="block text-[11px] font-medium text-gray-500">Item</label>
                                    <div v-if="!line.is_custom" class="mt-1 font-semibold text-gray-900">
                                        {{ line.name_snapshot }}
                                        <span class="block font-mono text-[11px] text-gray-500">{{ line.sku_snapshot }}</span>
                                    </div>
                                    <input
                                        v-else
                                        v-model="line.name_snapshot"
                                        type="text"
                                        required
                                        placeholder="Custom furniture description *"
                                        class="mt-1 w-full rounded border-gray-300 text-xs"
                                    />
                                    <input
                                        v-model="line.custom_spec"
                                        type="text"
                                        placeholder="Custom spec / fabric"
                                        class="mt-1 w-full rounded border-gray-300 text-xs"
                                    />
                                    <button
                                        type="button"
                                        class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:underline"
                                        @click="openSpecModal(idx)"
                                    >
                                        {{ line.custom_spec_id ? 'Edit full spec & sketches' : '+ Full spec & sketches' }}
                                        <span v-if="line.custom_spec_detail?.attachments?.length" class="rounded bg-indigo-100 px-1 text-[10px]">
                                            {{ line.custom_spec_detail.attachments.length }} file(s)
                                        </span>
                                    </button>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-medium text-gray-500">Source</label>
                                    <select v-model="line.source" class="mt-1 w-full rounded border-gray-300 text-xs">
                                        <option v-for="s in lineSources" :key="s.value" :value="s.value">{{ s.label }}</option>
                                    </select>
                                    <select v-model="line.fulfil_location_id" class="mt-1 w-full rounded border-gray-300 text-xs">
                                        <option v-for="loc in fulfilLocations" :key="loc.id" :value="loc.id">
                                            Fulfil: {{ loc.code }}
                                        </option>
                                    </select>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-medium text-gray-500">Qty</label>
                                    <input v-model="line.quantity" type="text" required class="mt-1 w-full rounded border-gray-300 text-xs" />
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-medium text-gray-500">Unit price (LKR)</label>
                                    <input v-model="line.unit_price" type="text" required class="mt-1 w-full rounded border-gray-300 text-xs" />
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-medium text-gray-500">Discount</label>
                                    <div class="mt-1 flex gap-1">
                                        <select v-model="line.discount_type" class="rounded border-gray-300 text-xs">
                                            <option value="">None</option>
                                            <option value="percent">%</option>
                                            <option value="fixed">LKR</option>
                                        </select>
                                        <input
                                            v-if="line.discount_type"
                                            v-model="line.discount_value"
                                            type="text"
                                            class="w-full rounded border-gray-300 text-xs"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-end sm:col-span-1">
                                    <button type="button" class="text-red-600 hover:underline" @click="removeLine(idx)">Remove</button>
                                </div>
                            </div>
                        </div>

                        <div v-if="!form.lines.length" class="py-6 text-center text-xs text-gray-500">
                            Search the catalog above or add a custom made-to-order line to start.
                        </div>
                    </div>
                </div>

                <!-- Totals & Manager Approval -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="mb-3 text-sm font-bold text-gray-900">Notes & Manager Approval</h3>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            placeholder="Special instructions, delivery notes…"
                            class="w-full rounded-lg border-gray-300 text-sm"
                        ></textarea>

                        <div v-if="!canOverride" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="text-xs font-semibold text-amber-900">
                                Manager approval (if discount exceeds role limit or price is below floor)
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <select v-model="form.approver_id" class="rounded border-gray-300 text-xs">
                                    <option value="">Select manager…</option>
                                    <option v-for="m in currentApprovers" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                                <input v-model="form.pin" type="password" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                            </div>
                            <p v-if="form.errors.pin" class="mt-1 text-xs text-red-600">{{ form.errors.pin }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="mb-3 text-sm font-bold text-gray-900">Totals & Actions</h3>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-600">Estimated items subtotal</span>
                                <span class="font-mono font-semibold">{{ formatMoney(estimatedSubtotalCents) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs text-gray-600">Order discount</span>
                                <div class="flex gap-1">
                                    <select v-model="form.order_discount_type" class="rounded border-gray-300 text-xs">
                                        <option value="">None</option>
                                        <option value="percent">%</option>
                                        <option value="fixed">LKR</option>
                                    </select>
                                    <input
                                        v-if="form.order_discount_type"
                                        v-model="form.order_discount_value"
                                        type="text"
                                        class="w-28 rounded border-gray-300 text-xs"
                                    />
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs text-gray-600">Delivery charge (LKR)</span>
                                <input v-model="form.delivery_charge" type="text" class="w-32 rounded border-gray-300 text-right text-xs" />
                            </div>
                        </div>

                        <div class="mt-6 flex flex-wrap justify-end gap-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            >
                                {{ order ? 'Update Draft' : 'Save Draft' }}
                            </button>
                            <button
                                v-if="!order"
                                type="button"
                                :disabled="form.processing"
                                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="submit(true)"
                            >
                                Save & Confirm Order
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <CustomSpecModal
            :show="editingSpecIndex !== null"
            :spec="editingSpecIndex !== null ? form.lines[editingSpecIndex]?.custom_spec_detail : null"
            :line-name="editingSpecIndex !== null ? form.lines[editingSpecIndex]?.name_snapshot || 'Custom piece' : ''"
            @close="editingSpecIndex = null"
            @saved="onSpecSaved"
        />
    </AdminLayout>
</template>

