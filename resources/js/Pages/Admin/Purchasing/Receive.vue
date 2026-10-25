<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    selectedOrder: { type: Object, default: null },
    openOrders: { type: Array, default: () => [] },
    suppliers: { type: Array, required: true },
    locations: { type: Array, required: true },
    defaultLocationId: { type: Number, default: null },
    approversByLocation: { type: Object, default: () => ({}) },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: true },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

const form = useForm({
    purchase_order_id: props.selectedOrder?.id || null,
    supplier_id: props.selectedOrder?.supplier_id || (props.suppliers[0]?.id ?? ''),
    location_id: props.selectedOrder?.location_id || props.defaultLocationId || (props.locations[0]?.id ?? ''),
    supplier_delivery_note: '',
    notes: '',
    approver_id: '',
    pin: '',
    lines: props.selectedOrder?.lines?.length
        ? props.selectedOrder.lines.map((l) => ({
              ...l,
              serials: '',
          }))
        : [],
});

const scanInput = ref('');
const scanFeedback = ref('');

function selectPurchaseOrder(poId) {
    router.get(route('purchasing.receive'), { purchase_order_id: poId || undefined });
}

async function handleScan() {
    const q = scanInput.value.trim();
    if (!q) return;

    const match = form.lines.find((l) => l.barcode === q || l.sku?.toLowerCase() === q.toLowerCase());
    if (match) {
        match.received_qty = String((parseFloat(match.received_qty || '0') + 1).toFixed(0));
        scanFeedback.value = `+1 ${match.sku}`;
        scanInput.value = '';
        return;
    }

    const res = await fetch(
        route('purchasing.lookup', { q, supplier_id: form.supplier_id || undefined }),
        { headers: { Accept: 'application/json' } },
    );
    const data = await res.json();
    const v = (data.variants || [])[0];
    if (v) {
        const existing = form.lines.find((l) => l.product_variant_id === v.id);
        if (existing) {
            existing.received_qty = String((parseFloat(existing.received_qty || '0') + 1).toFixed(0));
        } else {
            form.lines.push({
                purchase_order_line_id: null,
                product_variant_id: v.id,
                sku: v.sku,
                barcode: v.barcode,
                product_name: v.product_name,
                variant_name: v.variant_name,
                is_serialized: v.is_serialized,
                ordered_qty: 0,
                already_received_qty: 0,
                remaining_qty: 0,
                received_qty: '1',
                damaged_qty: '0',
                unit_cost: v.unit_cost_decimal || '0.00',
                serials: '',
                note: '',
            });
        }
        scanFeedback.value = `Added ${v.sku}`;
        scanInput.value = '';
    } else {
        scanFeedback.value = `No match for "${q}"`;
    }
}

const isOverReceiving = computed(() => {
    if (!form.purchase_order_id) return false;
    return form.lines.some((l) => {
        const recMilli = Math.round(parseFloat(l.received_qty || '0') * 1000);
        if (recMilli <= 0) return false;
        if (!l.purchase_order_line_id) return true;
        return (l.already_received_qty || 0) + recMilli > (l.ordered_qty || 0);
    });
});

const approvers = computed(() => props.approversByLocation[form.location_id] || []);

function submit() {
    form.post(route('purchasing.receive.store'), { preserveScroll: true });
}

function formatQty(milli) {
    if (!milli) return '0';
    return (milli / 1000).toLocaleString('en-LK', { maximumFractionDigits: 3 });
}
</script>

<template>
    <Head title="Receive Stock (GRN)" />

    <AdminLayout title="Receive Stock (GRN)">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills" />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Purchase order (optional)</label>
                        <select
                            :value="form.purchase_order_id || ''"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            @change="selectPurchaseOrder($event.target.value)"
                        >
                            <option value="">Direct receipt (no PO)</option>
                            <option v-if="selectedOrder" :value="selectedOrder.id">
                                {{ selectedOrder.number }} — {{ selectedOrder.supplier_name }}
                            </option>
                            <option
                                v-for="po in openOrders.filter((o) => o.id !== selectedOrder?.id)"
                                :key="po.id"
                                :value="po.id"
                            >
                                {{ po.number }} — {{ po.supplier_name }} ({{ po.location_code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Supplier *</label>
                        <select
                            v-model="form.supplier_id"
                            :disabled="!!form.purchase_order_id"
                            required
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm disabled:bg-gray-100"
                        >
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Receiving location *</label>
                        <select
                            v-model="form.location_id"
                            :disabled="!!form.purchase_order_id"
                            required
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm disabled:bg-gray-100"
                        >
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.name }} ({{ loc.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Supplier delivery note #</label>
                        <input
                            v-model="form.supplier_delivery_note"
                            type="text"
                            placeholder="e.g. DN-2026-884"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                        />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Received items</h3>
                        <p class="text-xs text-gray-500">
                            Scan barcode or enter SKU to add/increment received quantities. Enter serials for serialized furniture.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="scanInput"
                            type="text"
                            placeholder="Scan barcode or SKU + Enter…"
                            class="w-72 rounded-lg border-gray-300 text-sm"
                            @keydown.enter.prevent="handleScan"
                        />
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="handleScan"
                        >
                            Add / +1
                        </button>
                        <span v-if="scanFeedback" class="text-xs font-medium text-emerald-700">{{ scanFeedback }}</span>
                    </div>
                </div>

                <p v-if="form.errors.lines" class="mb-3 text-xs text-red-600">{{ form.errors.lines }}</p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Item</th>
                                <th v-if="form.purchase_order_id" class="px-3 py-2 text-right">Remaining on PO</th>
                                <th class="px-3 py-2 text-right">Received qty</th>
                                <th class="px-3 py-2 text-right">Damaged on arrival</th>
                                <th v-if="canViewCost" class="px-3 py-2 text-right">Unit cost (Rs.)</th>
                                <th class="px-3 py-2">Serial numbers (if serialized)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(line, idx) in form.lines" :key="idx">
                                <td class="px-3 py-2.5">
                                    <div class="font-mono text-xs font-semibold text-slate-900">{{ line.sku }}</div>
                                    <div class="text-gray-800">
                                        {{ line.product_name }}
                                        <span v-if="line.variant_name" class="text-gray-500">— {{ line.variant_name }}</span>
                                    </div>
                                    <span
                                        v-if="line.is_serialized"
                                        class="mt-0.5 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800"
                                    >
                                        Serialized (1 serial per unit)
                                    </span>
                                </td>
                                <td v-if="form.purchase_order_id" class="px-3 py-2.5 text-right font-mono text-gray-600">
                                    {{ formatQty(line.remaining_qty) }}
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <input
                                        v-model="line.received_qty"
                                        type="text"
                                        class="w-24 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <input
                                        v-model="line.damaged_qty"
                                        type="text"
                                        class="w-24 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td v-if="canViewCost" class="px-3 py-2.5 text-right">
                                    <input
                                        v-model="line.unit_cost"
                                        type="text"
                                        class="w-28 rounded-lg border-gray-300 text-right font-mono text-sm"
                                    />
                                </td>
                                <td class="px-3 py-2.5">
                                    <input
                                        v-if="line.is_serialized"
                                        v-model="line.serials"
                                        type="text"
                                        placeholder="Comma-separated serials, e.g. SN-001, SN-002"
                                        class="w-full rounded-lg border-gray-300 font-mono text-xs"
                                    />
                                    <span v-else class="text-xs text-gray-400">Not serialized</span>
                                </td>
                            </tr>
                            <tr v-if="!form.lines.length">
                                <td :colspan="canViewCost ? 6 : 5" class="px-3 py-8 text-center text-gray-500">
                                    Select a purchase order above or scan a barcode/SKU to add items to receive.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Over-receive manager approval box -->
            <div
                v-if="isOverReceiving || form.errors.pin"
                class="rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm"
            >
                <div class="mb-2 text-sm font-semibold text-amber-900">
                    Manager approval required for over-receiving beyond the Purchase Order
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-amber-900">Approving manager *</label>
                        <select v-model="form.approver_id" class="mt-1 w-full rounded-lg border-amber-300 bg-white text-sm">
                            <option value="">Choose manager…</option>
                            <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-amber-900">Manager PIN *</label>
                        <input
                            v-model="form.pin"
                            type="password"
                            inputmode="numeric"
                            maxlength="10"
                            placeholder="Enter 4–6 digit PIN"
                            class="mt-1 w-full rounded-lg border-amber-300 bg-white text-sm"
                        />
                        <p v-if="form.errors.pin" class="mt-1 text-xs text-red-600">{{ form.errors.pin }}</p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing || !form.lines.length"
                    class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                    Post Goods Receipt (GRN)
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
