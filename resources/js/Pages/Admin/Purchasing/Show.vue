<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PurchasingNav from '@/Pages/Admin/Purchasing/Partials/PurchasingNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    order: { type: Object, required: true },
    canCreatePo: { type: Boolean, default: false },
    canReceive: { type: Boolean, default: false },
    canManageBills: { type: Boolean, default: false },
    canViewCost: { type: Boolean, default: false },
});

function sendOrder(emailSupplier = true) {
    router.post(
        route('purchasing.orders.send', props.order.id),
        { email_supplier: emailSupplier },
        { preserveScroll: true },
    );
}

function closeOrder() {
    router.post(route('purchasing.orders.close', props.order.id), {}, { preserveScroll: true });
}

function cancelOrder() {
    router.post(route('purchasing.orders.cancel', props.order.id), {}, { preserveScroll: true });
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
    <Head :title="`PO ${order.number}`" />

    <AdminLayout :title="`Purchase Order ${order.number}`">
        <PurchasingNav :can-receive="canReceive" :can-manage-bills="canManageBills">
            <a
                v-if="canViewCost"
                :href="route('purchasing.orders.pdf', order.id)"
                target="_blank"
                class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Download PDF
            </a>
            <Link
                v-if="order.can_edit"
                :href="route('purchasing.orders.edit', order.id)"
                class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Edit draft
            </Link>
            <button
                v-if="order.can_send"
                type="button"
                class="rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-blue-700"
                @click="sendOrder(true)"
            >
                {{ order.status === 'draft' ? 'Send & email supplier' : 'Resend email' }}
            </button>
            <Link
                v-if="order.can_receive"
                :href="route('purchasing.receive', { purchase_order_id: order.id })"
                class="rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-emerald-700"
            >
                Receive goods (GRN)
            </Link>
            <button
                v-if="order.can_close"
                type="button"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
                @click="closeOrder"
            >
                Close PO
            </button>
            <button
                v-if="order.can_cancel"
                type="button"
                class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-100"
                @click="cancelOrder"
            >
                Cancel PO
            </button>
        </PurchasingNav>

        <div class="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-400">Supplier</div>
                <div class="mt-1 font-medium text-gray-900">{{ order.supplier?.name }}</div>
                <div v-if="order.supplier?.contact_person" class="text-xs text-gray-500">
                    {{ order.supplier.contact_person }}
                </div>
                <div v-if="order.supplier?.email" class="text-xs text-gray-500">{{ order.supplier.email }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-400">Deliver to</div>
                <div class="mt-1 font-medium text-gray-900">
                    {{ order.location?.name }} ({{ order.location?.code }})
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-400">Dates</div>
                <div class="mt-1 text-sm text-gray-800">Ordered: {{ order.order_date }}</div>
                <div class="text-xs text-gray-500">Expected: {{ order.expected_date || '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-400">Status</div>
                <div class="mt-1 font-semibold text-slate-900">{{ order.status_label }}</div>
                <div v-if="order.created_by_name" class="text-xs text-gray-500">Created by {{ order.created_by_name }}</div>
            </div>
            <div v-if="order.notes" class="sm:col-span-4 border-t border-gray-100 pt-3 text-sm text-gray-600">
                <span class="font-medium text-gray-800">Notes:</span> {{ order.notes }}
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">SKU</th>
                        <th class="px-4 py-3">Item</th>
                        <th class="px-4 py-3">Supplier SKU</th>
                        <th class="px-4 py-3 text-right">Ordered</th>
                        <th class="px-4 py-3 text-right">Received</th>
                        <th class="px-4 py-3 text-right">Remaining</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Unit cost</th>
                        <th v-if="canViewCost" class="px-4 py-3 text-right">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="l in order.lines" :key="l.id">
                        <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">{{ l.sku }}</td>
                        <td class="px-4 py-3 text-gray-900">
                            {{ l.product_name }}
                            <span v-if="l.variant_name" class="text-gray-500">— {{ l.variant_name }}</span>
                            <span
                                v-if="l.sales_order_line_id"
                                class="ml-2 rounded bg-purple-50 px-1.5 py-0.5 text-xs font-medium text-purple-700"
                            >
                                Back-to-back SO line #{{ l.sales_order_line_id }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ l.supplier_sku || '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ formatQty(l.ordered_qty) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-emerald-700">{{ formatQty(l.received_qty) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-amber-700">{{ formatQty(l.remaining_qty) }}</td>
                        <td v-if="canViewCost" class="px-4 py-3 text-right font-mono">{{ formatMoney(l.unit_cost) }}</td>
                        <td v-if="canViewCost" class="px-4 py-3 text-right font-mono font-medium">
                            {{ formatMoney(l.line_total) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="canViewCost" class="flex flex-col items-end gap-1 border-t border-gray-200 bg-gray-50 px-6 py-4 text-sm">
                <div class="flex w-64 justify-between text-gray-600">
                    <span>Subtotal:</span>
                    <span class="font-mono">{{ formatMoney(order.subtotal) }}</span>
                </div>
                <div v-if="order.discount_total > 0" class="flex w-64 justify-between text-gray-600">
                    <span>Discount:</span>
                    <span class="font-mono">-{{ formatMoney(order.discount_total) }}</span>
                </div>
                <div v-if="order.tax_total > 0" class="flex w-64 justify-between text-gray-600">
                    <span>Tax:</span>
                    <span class="font-mono">{{ formatMoney(order.tax_total) }}</span>
                </div>
                <div class="flex w-64 justify-between border-t border-gray-200 pt-1 text-base font-semibold text-gray-900">
                    <span>Total:</span>
                    <span class="font-mono">{{ formatMoney(order.total_amount) }}</span>
                </div>
            </div>
        </div>

        <div v-if="order.goods_receipts?.length" class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Goods receipts (GRNs) against this PO</h3>
            <div class="divide-y divide-gray-100 text-sm">
                <div
                    v-for="grn in order.goods_receipts"
                    :key="grn.id"
                    class="flex items-center justify-between py-2.5"
                >
                    <div>
                        <span class="font-mono font-semibold text-slate-900">{{ grn.number }}</span>
                        <span v-if="grn.supplier_delivery_note" class="ml-2 text-xs text-gray-500">
                            Delivery Note: {{ grn.supplier_delivery_note }}
                        </span>
                        <span class="ml-2 text-xs text-gray-400">by {{ grn.received_by_name }}</span>
                    </div>
                    <div v-if="canViewCost" class="font-mono font-medium text-gray-900">
                        {{ formatMoney(grn.total_amount) }}
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
