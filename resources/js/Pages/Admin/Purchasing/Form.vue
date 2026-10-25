<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    order: { type: Object, default: null },
    suppliers: { type: Array, required: true },
    locations: { type: Array, required: true },
    defaultLocationId: { type: Number, default: null },
    defaultTaxRateBp: { type: Number, default: 0 },
    canCreatePo: { type: Boolean, default: true },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: true },
});

const defaultTaxPercent = (props.defaultTaxRateBp / 100).toFixed(2);

const form = useForm({
    supplier_id: props.order?.supplier_id || (props.suppliers[0]?.id ?? ''),
    location_id: props.order?.location_id || props.defaultLocationId || (props.locations[0]?.id ?? ''),
    order_date: props.order?.order_date || new Date().toISOString().slice(0, 10),
    expected_date: props.order?.expected_date || '',
    notes: props.order?.notes || '',
    lines: props.order?.lines?.length
        ? props.order.lines.map((l) => ({ ...l }))
        : [],
});

const lookupQuery = ref('');
const lookupResults = ref([]);
const searching = ref(false);

async function searchVariants() {
    const q = lookupQuery.value.trim();
    if (!q) {
        lookupResults.value = [];
        return;
    }
    searching.value = true;
    try {
        const url = route('purchasing.lookup', {
            q,
            supplier_id: form.supplier_id || undefined,
        });
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        lookupResults.value = data.variants || [];
    } finally {
        searching.value = false;
    }
}

function addVariant(v) {
    const existing = form.lines.find((l) => l.product_variant_id === v.id);
    if (existing) {
        existing.ordered_qty = String((parseFloat(existing.ordered_qty || '0') + 1).toFixed(0));
    } else {
        form.lines.push({
            product_variant_id: v.id,
            sku: v.sku,
            product_name: v.product_name,
            variant_name: v.variant_name,
            is_serialized: v.is_serialized,
            supplier_sku: v.supplier_sku || '',
            ordered_qty: '1',
            unit_cost: v.unit_cost_decimal || '0.00',
            discount: '0.00',
            tax_rate: defaultTaxPercent,
            sales_order_line_id: null,
            note: '',
        });
    }
    lookupQuery.value = '';
    lookupResults.value = [];
}

function removeLine(idx) {
    form.lines.splice(idx, 1);
}

const totals = computed(() => {
    let subtotal = 0;
    let discount = 0;
    let tax = 0;
    let total = 0;

    for (const l of form.lines) {
        const qty = Math.max(0, parseFloat(l.ordered_qty || '0'));
        const cost = Math.max(0, parseFloat(l.unit_cost || '0'));
        const disc = Math.max(0, parseFloat(l.discount || '0'));
        const taxRate = Math.max(0, parseFloat(l.tax_rate || '0'));

        const gross = qty * cost;
        const net = Math.max(0, gross - disc);
        const lineTax = (net * taxRate) / 100;

        subtotal += gross;
        discount += disc;
        tax += lineTax;
        total += net + lineTax;
    }

    return { subtotal, discount, tax, total };
});

function submit() {
    if (props.order?.id) {
        form.put(route('purchasing.orders.update', props.order.id));
    } else {
        form.post(route('purchasing.orders.store'));
    }
}

function fmtCurrency(num) {
    return 'Rs. ' + Number(num || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head :title="order ? `Edit ${order.number}` : 'New Purchase Order'" />

    <AdminLayout :title="order ? `Edit ${order.number}` : 'New Purchase Order'">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <Link
                :href="order ? route('purchasing.orders.show', order.id) : route('purchasing.index')"
                class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Cancel
            </Link>
        </PurchasingNav>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Supplier *</label>
                        <select v-model="form.supplier_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-red-600">{{ form.errors.supplier_id }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Deliver-to location *</label>
                        <select v-model="form.location_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.name }} ({{ loc.code }})
                            </option>
                        </select>
                        <p v-if="form.errors.location_id" class="mt-1 text-xs text-red-600">{{ form.errors.location_id }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Order date</label>
                        <input v-model="form.order_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Expected delivery date</label>
                        <input v-model="form.expected_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-gray-700">Notes / instructions for supplier</label>
                        <input v-model="form.notes" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                </div>
            </div>

            <!-- Line item builder -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900">Order lines</h3>
                    <div class="relative w-96">
                        <input
                            v-model="lookupQuery"
                            type="search"
                            placeholder="Scan barcode or search SKU / product name to add…"
                            class="w-full rounded-lg border-gray-300 text-sm"
                            @input="searchVariants"
                            @keydown.enter.prevent="lookupResults.length === 1 ? addVariant(lookupResults[0]) : searchVariants()"
                        />
                        <div
                            v-if="lookupResults.length"
                            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-lg"
                        >
                            <button
                                v-for="v in lookupResults"
                                :key="v.id"
                                type="button"
                                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
                                @click="addVariant(v)"
                            >
                                <div>
                                    <span class="font-mono font-medium text-slate-900">{{ v.sku }}</span>
                                    <span class="ml-2 text-gray-700">{{ v.product_name }}</span>
                                    <span v-if="v.variant_name" class="ml-1 text-gray-500">— {{ v.variant_name }}</span>
                                </div>
                                <span v-if="v.unit_cost_decimal" class="font-mono text-xs text-gray-500">
                                    Rs. {{ v.unit_cost_decimal }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.lines" class="mb-3 text-xs text-red-600">{{ form.errors.lines }}</p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Item</th>
                                <th class="px-3 py-2">Supplier SKU</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                                <th class="px-3 py-2 text-right">Unit cost (Rs.)</th>
                                <th class="px-3 py-2 text-right">Discount (Rs.)</th>
                                <th class="px-3 py-2 text-right">Tax %</th>
                                <th class="px-3 py-2">Note</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(line, idx) in form.lines" :key="idx">
                                <td class="px-3 py-2">
                                    <div class="font-mono text-xs font-semibold text-slate-900">{{ line.sku }}</div>
                                    <div class="text-gray-800">
                                        {{ line.product_name }}
                                        <span v-if="line.variant_name" class="text-gray-500">— {{ line.variant_name }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <input
                                        v-model="line.supplier_sku"
                                        type="text"
                                        placeholder="Optional"
                                        class="w-28 rounded-lg border-gray-300 text-xs"
                                    />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input
                                        v-model="line.ordered_qty"
                                        type="text"
                                        required
                                        class="w-20 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input
                                        v-model="line.unit_cost"
                                        type="text"
                                        required
                                        class="w-28 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input
                                        v-model="line.discount"
                                        type="text"
                                        class="w-24 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input
                                        v-model="line.tax_rate"
                                        type="text"
                                        class="w-20 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2">
                                    <input
                                        v-model="line.note"
                                        type="text"
                                        placeholder="Line note"
                                        class="w-36 rounded-lg border-gray-300 text-xs"
                                    />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        class="text-xs text-red-600 hover:text-red-800"
                                        @click="removeLine(idx)"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!form.lines.length">
                                <td colspan="8" class="px-3 py-8 text-center text-gray-500">
                                    Search for a SKU or barcode above to add lines to this purchase order.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-col items-end gap-1 border-t border-gray-200 pt-4 text-sm">
                    <div class="flex w-64 justify-between text-gray-600">
                        <span>Subtotal:</span>
                        <span class="font-mono">{{ fmtCurrency(totals.subtotal) }}</span>
                    </div>
                    <div v-if="totals.discount > 0" class="flex w-64 justify-between text-gray-600">
                        <span>Discount:</span>
                        <span class="font-mono">-{{ fmtCurrency(totals.discount) }}</span>
                    </div>
                    <div v-if="totals.tax > 0" class="flex w-64 justify-between text-gray-600">
                        <span>Tax:</span>
                        <span class="font-mono">{{ fmtCurrency(totals.tax) }}</span>
                    </div>
                    <div class="flex w-64 justify-between border-t border-gray-200 pt-1 text-base font-semibold text-gray-900">
                        <span>Total:</span>
                        <span class="font-mono">{{ fmtCurrency(totals.total) }}</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !form.lines.length"
                    class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ order ? 'Update purchase order' : 'Save draft purchase order' }}
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
