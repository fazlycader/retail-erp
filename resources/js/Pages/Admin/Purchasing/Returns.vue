<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    returns: { type: Object, required: true },
    suppliers: { type: Array, required: true },
    recentReceipts: { type: Array, default: () => [] },
    locations: { type: Array, required: true },
    defaultLocationId: { type: Number, default: null },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

const showCreate = ref(false);
const lookupQuery = ref('');
const lookupResults = ref([]);

const form = useForm({
    supplier_id: props.suppliers[0]?.id ?? '',
    location_id: props.defaultLocationId || (props.locations[0]?.id ?? ''),
    goods_receipt_id: '',
    returned_at: new Date().toISOString().slice(0, 10),
    reason: '',
    notes: '',
    lines: [],
});

async function searchVariants() {
    const q = lookupQuery.value.trim();
    if (!q) {
        lookupResults.value = [];
        return;
    }
    const res = await fetch(
        route('purchasing.lookup', { q, supplier_id: form.supplier_id || undefined }),
        { headers: { Accept: 'application/json' } },
    );
    const data = await res.json();
    lookupResults.value = data.variants || [];
}

function addVariant(v) {
    form.lines.push({
        product_variant_id: v.id,
        sku: v.sku,
        product_name: v.product_name,
        variant_name: v.variant_name,
        is_serialized: v.is_serialized,
        quantity: '1',
        unit_cost: v.unit_cost_decimal || '',
        serial_number: '',
        reason: '',
    });
    lookupQuery.value = '';
    lookupResults.value = [];
}

function submit() {
    form.post(route('purchasing.returns.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            form.reset('reason', 'notes', 'lines', 'goods_receipt_id');
        },
    });
}

function formatMoney(cents) {
    if (cents === undefined || cents === null) return '—';
    return 'Rs. ' + (cents / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatQty(milli) {
    if (!milli) return '0';
    return (milli / 1000).toLocaleString('en-LK', { maximumFractionDigits: 3 });
}
</script>

<template>
    <Head title="Supplier Returns" />

    <AdminLayout title="Supplier Returns">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <button
                v-if="canReceive"
                type="button"
                class="rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-slate-800"
                @click="showCreate = !showCreate"
            >
                {{ showCreate ? 'Close form' : '+ New supplier return' }}
            </button>
        </PurchasingNav>

        <form
            v-if="showCreate"
            class="mb-6 space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            @submit.prevent="submit"
        >
            <h3 class="text-sm font-semibold text-gray-900">Record supplier return (stock out)</h3>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700">Supplier *</label>
                    <select v-model="form.supplier_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">From location *</label>
                    <select v-model="form.location_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            {{ loc.name }} ({{ loc.code }})
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">Linked GRN (optional)</label>
                    <select v-model="form.goods_receipt_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="">None</option>
                        <option
                            v-for="grn in recentReceipts.filter((g) => !form.supplier_id || g.supplier_id === Number(form.supplier_id))"
                            :key="grn.id"
                            :value="grn.id"
                        >
                            {{ grn.number }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">Return reason *</label>
                    <input
                        v-model="form.reason"
                        type="text"
                        required
                        placeholder="e.g. Defective finish, wrong colour"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                    />
                </div>
            </div>

            <div class="relative w-96">
                <input
                    v-model="lookupQuery"
                    type="search"
                    placeholder="Search SKU or barcode to return…"
                    class="w-full rounded-lg border-gray-300 text-sm"
                    @input="searchVariants"
                />
                <div
                    v-if="lookupResults.length"
                    class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-lg"
                >
                    <button
                        v-for="v in lookupResults"
                        :key="v.id"
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
                        @click="addVariant(v)"
                    >
                        <span>
                            <strong class="font-mono">{{ v.sku }}</strong> — {{ v.product_name }}
                        </span>
                    </button>
                </div>
            </div>

            <p v-if="form.errors.lines" class="text-xs text-red-600">{{ form.errors.lines }}</p>

            <table v-if="form.lines.length" class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Item</th>
                        <th class="px-3 py-2 text-right">Qty</th>
                        <th v-if="canViewCost" class="px-3 py-2 text-right">Unit cost (Rs.)</th>
                        <th class="px-3 py-2">Serial # (if serialized)</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="(l, idx) in form.lines" :key="idx">
                        <td class="px-3 py-2">
                            <span class="font-mono font-semibold">{{ l.sku }}</span> — {{ l.product_name }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input v-model="l.quantity" type="text" class="w-20 rounded-lg border-gray-300 text-right font-mono text-sm" />
                        </td>
                        <td v-if="canViewCost" class="px-3 py-2 text-right">
                            <input v-model="l.unit_cost" type="text" class="w-28 rounded-lg border-gray-300 text-right font-mono text-sm" />
                        </td>
                        <td class="px-3 py-2">
                            <input
                                v-if="l.is_serialized"
                                v-model="l.serial_number"
                                type="text"
                                placeholder="Serial number"
                                class="w-44 rounded-lg border-gray-300 font-mono text-xs"
                            />
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" class="text-xs text-red-600" @click="form.lines.splice(idx, 1)">Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing || !form.lines.length"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    Post supplier return
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Return #</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Location</th>
                        <th class="px-4 py-3">Reason</th>
                        <th class="px-4 py-3">Date</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Credit expected</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="ret in returns.data" :key="ret.id">
                        <td class="px-4 py-3 font-mono font-medium text-slate-900">{{ ret.number }}</td>
                        <td class="px-4 py-3 text-gray-900">{{ ret.supplier_name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ ret.location_name }} ({{ ret.location_code }})</td>
                        <td class="px-4 py-3 text-gray-700">
                            <div>{{ ret.reason }}</div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                <span v-for="l in ret.lines" :key="l.id" class="mr-3 font-mono">
                                    {{ l.sku }} × {{ formatQty(l.quantity) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ ret.returned_at }}</td>
                        <td v-if="canViewCost" class="px-4 py-3 text-right font-mono font-medium text-gray-900">
                            {{ formatMoney(ret.credit_expected) }}
                        </td>
                    </tr>
                    <tr v-if="!returns.data.length">
                        <td :colspan="canViewCost ? 6 : 5" class="px-4 py-10 text-center text-gray-500">
                            No supplier returns recorded yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
