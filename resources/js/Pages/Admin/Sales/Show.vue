<script setup>
import CustomSpecModal from '@/Components/Sales/CustomSpecModal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import OrderMoney from '@/Pages/Admin/Sales/Partials/OrderMoney.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
    approvers: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
    canManageCheques: { type: Boolean, default: false },
    payments: { type: Array, default: () => [] },
    returns: { type: Array, default: () => [] },
    creditNotes: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    returnConfig: { type: Object, required: true },
    documents: { type: Object, default: null },
});

const removeLineForm = useForm({});
function removeLine(line) {
    if (!confirm(`Remove ${line.name_snapshot} from this order? Its reserved stock is released and the order is repriced.`)) return;
    removeLineForm.delete(route('sales.orders.lines.destroy', [props.order.id, line.id]), { preserveScroll: true });
}

const confirmForm = useForm({});
const showFulfilModal = ref(false);
const showCancelModal = ref(false);
const viewingSpecLine = ref(null);

const fulfilForm = useForm({
    note: '',
    lines: props.order.lines
        .filter((l) => !l.is_service && l.remaining_qty > 0)
        .map((l) => ({
            line_id: l.id,
            name_snapshot: l.name_snapshot,
            remaining_qty_decimal: l.remaining_qty_decimal,
            quantity: l.remaining_qty_decimal,
        })),
});

const cancelForm = useForm({
    reason: '',
    approver_id: '',
    pin: '',
});

function confirmOrder() {
    confirmForm.post(route('sales.orders.confirm', props.order.id), { preserveScroll: true });
}

function openFulfil() {
    fulfilForm.lines = props.order.lines
        .filter((l) => !l.is_service && l.remaining_qty > 0)
        .map((l) => ({
            line_id: l.id,
            name_snapshot: l.name_snapshot,
            remaining_qty_decimal: l.remaining_qty_decimal,
            quantity: l.remaining_qty_decimal,
        }));
    showFulfilModal.value = true;
}

function submitFulfil() {
    fulfilForm.post(route('sales.orders.fulfil', props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            showFulfilModal.value = false;
        },
    });
}

function submitCancel() {
    cancelForm.post(route('sales.orders.cancel', props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
        },
    });
}

function formatQty(milli) {
    return (milli / 1000).toString();
}
</script>

<template>
    <Head :title="`Sales Order ${order.number}`" />

    <AdminLayout>
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <Link
                    v-if="order.can_create_installment"
                    :href="route('installments.create', { order_id: order.id })"
                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                >
                    Create Installment Agreement
                </Link>
                <a
                    :href="route('sales.orders.delivery-note', order.id)"
                    target="_blank"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Delivery Note PDF ↗
                </a>
                <Link
                    v-if="order.can_edit"
                    :href="route('sales.orders.edit', order.id)"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Edit Draft
                </Link>
                <button
                    v-if="order.can_confirm"
                    type="button"
                    :disabled="confirmForm.processing"
                    class="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                    @click="confirmOrder"
                >
                    Confirm Order & Reserve Stock
                </button>
                <button
                    v-if="order.can_fulfil"
                    type="button"
                    class="rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500"
                    @click="openFulfil"
                >
                    Fulfil / Issue Stock
                </button>
                <button
                    v-if="order.can_cancel"
                    type="button"
                    class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100"
                    @click="showCancelModal = true"
                >
                    Cancel Order
                </button>
            </SalesNav>

            <div v-if="order.needs_attention" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                <p class="font-semibold">Web order needs attention<template v-if="order.woo_order_number"> (WooCommerce #{{ order.woo_order_number }}, {{ order.woo_status }})</template></p>
                <p class="whitespace-pre-line">{{ order.attention_reason }}</p>
            </div>

            <p v-if="removeLineForm.errors.lines" class="mb-4 rounded-lg bg-red-50 p-3 text-xs font-medium text-red-700">
                {{ removeLineForm.errors.lines }}
            </p>

            <p v-if="confirmForm.errors.status" class="mb-4 rounded-lg bg-red-50 p-3 text-xs font-medium text-red-700">
                {{ confirmForm.errors.status }}
            </p>

            <!-- Summary Card -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-mono text-xl font-bold text-gray-900">{{ order.number }}</h1>
                            <span
                                v-if="order.invoice_number"
                                class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-800"
                            >
                                {{ order.invoice_number }}
                            </span>
                            <span class="rounded-full bg-indigo-50 px-3 py-0.5 text-xs font-semibold text-indigo-700">
                                {{ order.status_label }}
                            </span>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">
                                {{ order.payment_status_label }}
                            </span>
                            <span
                                v-if="order.advance_pending"
                                class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800"
                            >
                                Advance Pending ({{ formatMoney(order.advance_required_cents) }})
                            </span>
                        </div>

                        <div class="mt-2 text-sm text-gray-600">
                            <span v-if="order.customer">
                                Customer:
                                <Link :href="route('customers.show', order.customer.id)" class="font-semibold text-indigo-600 hover:underline">
                                    {{ order.customer.name }}
                                </Link>
                                ({{ order.customer.phone_display || order.customer.phone }})
                            </span>
                            <span v-else class="text-amber-700">No customer assigned (assign a customer before confirming)</span>
                        </div>

                        <div class="mt-1 flex flex-wrap gap-4 text-xs text-gray-500">
                            <span>Location: <strong>{{ order.location?.name }}</strong></span>
                            <span>Channel: <strong>{{ order.channel_label }}</strong></span>
                            <span>Fulfilment: <strong>{{ order.fulfilment_type_label }}</strong></span>
                            <span v-if="order.requested_delivery_date">
                                Requested delivery: <strong>{{ order.requested_delivery_date }}</strong>
                            </span>
                            <span v-if="order.quotation">
                                From quote:
                                <Link :href="route('sales.quotations.show', order.quotation.id)" class="text-indigo-600 underline">
                                    {{ order.quotation.number }}
                                </Link>
                            </span>
                        </div>

                        <div v-if="order.delivery_address?.line1" class="mt-2 text-xs text-gray-600">
                            <strong>Delivery address:</strong>
                            {{ [order.delivery_address.line1, order.delivery_address.city, order.delivery_address.district].filter(Boolean).join(', ') }}
                        </div>
                    </div>

                    <div class="text-right">
                        <div class="text-xs text-gray-500">Grand Total</div>
                        <div class="font-mono text-2xl font-bold text-gray-900">{{ formatMoney(order.grand_total) }}</div>
                        <div class="mt-1 font-mono text-xs text-amber-700">Balance due: {{ formatMoney(order.balance_due) }}</div>
                    </div>
                </div>
            </div>

            <!-- Lines Table -->
            <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Item / Spec</th>
                            <th class="px-4 py-3">Source / Fulfil</th>
                            <th class="px-4 py-3 text-right">Ordered</th>
                            <th class="px-4 py-3 text-right">Reserved</th>
                            <th class="px-4 py-3 text-right">Fulfilled</th>
                            <th class="px-4 py-3 text-right">Unit price</th>
                            <th class="px-4 py-3 text-right">Line total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="l in order.lines" :key="l.id">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900">{{ l.name_snapshot }}</div>
                                <div v-if="l.sku_snapshot" class="font-mono text-xs text-gray-500">{{ l.sku_snapshot }}</div>
                                <div v-if="l.custom_spec" class="text-xs text-gray-600">{{ l.custom_spec }}</div>
                                <button
                                    v-if="l.custom_spec_detail"
                                    type="button"
                                    class="mt-1 text-xs font-semibold text-indigo-600 hover:underline"
                                    @click="viewingSpecLine = l"
                                >
                                    View spec &amp; sketches ({{ l.custom_spec_detail.attachments?.length || 0 }})
                                </button>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <div class="font-medium text-gray-800">{{ l.source_label }}</div>
                                <div v-if="l.fulfil_location_code" class="text-gray-500">Location: {{ l.fulfil_location_code }}</div>
                                <div v-if="l.production_job" class="mt-0.5">
                                    Job:
                                    <Link :href="route('workshop.jobs.show', l.production_job.id)" class="font-mono font-semibold text-indigo-600 underline">
                                        {{ l.production_job.number }}
                                    </Link>
                                    <span class="ml-1 rounded bg-purple-50 px-1.5 py-0.5 text-[10px] font-semibold text-purple-800">
                                        {{ l.production_job.stage || l.production_job.status_label }}
                                    </span>
                                </div>
                                <div v-if="l.linked_po" class="mt-0.5">
                                    Back-to-back PO:
                                    <Link :href="route('purchasing.orders.show', l.linked_po.id)" class="font-mono text-indigo-600 underline">
                                        {{ l.linked_po.number }}
                                    </Link>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatQty(l.quantity) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-indigo-700">{{ formatQty(l.reserved_qty) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-emerald-700">{{ formatQty(l.fulfilled_qty) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(l.unit_price) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold">
                                {{ formatMoney(l.line_total) }}
                                <div v-if="l.returned_qty > 0" class="text-[11px] font-normal text-red-600">Returned {{ formatQty(l.returned_qty) }}</div>
                                <button v-if="l.can_remove && canManage" type="button" class="mt-1 block w-full text-right text-[11px] font-normal text-red-600 hover:underline" @click="removeLine(l)">
                                    Remove line
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <div class="ml-auto max-w-xs space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Subtotal</span>
                            <span class="font-mono">{{ formatMoney(order.subtotal) }}</span>
                        </div>
                        <div v-if="order.order_discount_cents > 0" class="flex justify-between text-amber-700">
                            <span>Order discount</span>
                            <span class="font-mono">-{{ formatMoney(order.order_discount_cents) }}</span>
                        </div>
                        <div v-if="order.tax_total > 0" class="flex justify-between text-gray-600">
                            <span>{{ order.prices_include_tax ? 'Included VAT' : 'VAT' }}</span>
                            <span class="font-mono">{{ formatMoney(order.tax_total) }}</span>
                        </div>
                        <div v-if="order.delivery_charge > 0" class="flex justify-between text-gray-600">
                            <span>Delivery charge</span>
                            <span class="font-mono">{{ formatMoney(order.delivery_charge) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-2 text-sm font-bold text-gray-900">
                            <span>Grand Total</span>
                            <span class="font-mono">{{ formatMoney(order.grand_total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <OrderMoney
                :order="order"
                :payments="payments"
                :returns="returns"
                :credit-notes="creditNotes"
                :payment-methods="paymentMethods"
                :return-config="returnConfig"
                :documents="documents"
                :approvers="approvers"
                :can-manage="canManage"
                :can-override="canOverride"
                :can-manage-cheques="canManageCheques"
            />

            <!-- Status Timeline -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-3 text-sm font-bold text-gray-900">Order Timeline</h2>
                <ul class="space-y-2 text-xs">
                    <li v-for="h in order.status_history" :key="h.id" class="flex items-center justify-between border-b border-gray-100 pb-2">
                        <div>
                            <span class="font-semibold text-gray-900">{{ h.to_status }}</span>
                            <span v-if="h.note" class="ml-2 text-gray-600">— {{ h.note }}</span>
                        </div>
                        <div class="text-gray-400">
                            {{ h.user_name || 'System' }} · {{ h.created_at }}
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Fulfil Modal -->
            <div v-if="showFulfilModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                    <h2 class="text-base font-bold text-gray-900">Fulfil / Issue Stock</h2>
                    <p class="mt-1 text-xs text-gray-500">Enter quantities to collect or deliver now (partial fulfilment supported).</p>

                    <form class="mt-4 space-y-3" @submit.prevent="submitFulfil">
                        <div
                            v-for="(item, idx) in fulfilForm.lines"
                            :key="item.line_id"
                            class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 text-xs"
                        >
                            <div>
                                <div class="font-semibold text-gray-900">{{ item.name_snapshot }}</div>
                                <div class="text-gray-500">Remaining: {{ item.remaining_qty_decimal }}</div>
                            </div>
                            <input
                                v-model="fulfilForm.lines[idx].quantity"
                                type="text"
                                class="w-28 rounded border-gray-300 text-right text-xs"
                            />
                        </div>

                        <p v-if="fulfilForm.errors.lines" class="text-xs text-red-600">{{ fulfilForm.errors.lines }}</p>

                        <div>
                            <label class="block text-xs font-medium text-gray-700">Fulfilment / Delivery note</label>
                            <input v-model="fulfilForm.note" type="text" class="mt-1 w-full rounded border-gray-300 text-xs" />
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="showFulfilModal = false">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="fulfilForm.processing"
                                class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500"
                            >
                                Confirm Fulfilment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Cancel Modal -->
            <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <h2 class="text-base font-bold text-gray-900">Cancel Sales Order</h2>
                    <form class="mt-3 space-y-3" @submit.prevent="submitCancel">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Reason</label>
                            <input v-model="cancelForm.reason" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                        </div>
                        <div v-if="order.paid_amount > 0 && !canOverride" class="rounded border border-amber-200 bg-amber-50 p-3 text-xs">
                            <div class="font-semibold text-amber-900">Manager PIN required to cancel a paid order</div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <select v-model="cancelForm.approver_id" class="rounded border-gray-300 text-xs">
                                    <option value="">Select manager…</option>
                                    <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                                <input v-model="cancelForm.pin" type="password" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="showCancelModal = false">
                                Close
                            </button>
                            <button type="submit" class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-semibold text-white">
                                Cancel Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <CustomSpecModal
            :show="viewingSpecLine !== null"
            :spec="viewingSpecLine?.custom_spec_detail ?? null"
            :line-name="viewingSpecLine?.name_snapshot || 'Custom piece'"
            :read-only="!canManage"
            @close="viewingSpecLine = null"
            @saved="router.reload({ only: ['order'] })"
        />
    </AdminLayout>
</template>
