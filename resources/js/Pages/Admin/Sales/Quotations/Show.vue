<script setup>
import CustomSpecModal from '@/Components/Sales/CustomSpecModal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    quotation: { type: Object, required: true },
    conversionPreview: { type: Object, required: true },
    approvers: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
});

const sendForm = useForm({ email_customer: true });
const acceptForm = useForm({});
const showLostModal = ref(false);
const lostForm = useForm({ reason: '' });
const viewingSpecLine = ref(null);

const showConvertModal = ref(false);
const convertForm = useForm({
    honour_quoted_prices: true,
    approver_id: '',
    pin: '',
});

function sendQuotation(withEmail = true) {
    sendForm.email_customer = withEmail;
    sendForm.post(route('sales.quotations.send', props.quotation.id), { preserveScroll: true });
}

function markAccepted() {
    acceptForm.post(route('sales.quotations.accept', props.quotation.id), { preserveScroll: true });
}

function submitLost() {
    lostForm.post(route('sales.quotations.lose', props.quotation.id), {
        preserveScroll: true,
        onSuccess: () => {
            showLostModal.value = false;
        },
    });
}

function submitConvert() {
    convertForm.post(route('sales.quotations.convert', props.quotation.id));
}

function formatQty(milli) {
    return (milli / 1000).toString();
}
</script>

<template>
    <Head :title="`Quotation ${quotation.number}`" />

    <AdminLayout>
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav>
                <a
                    :href="route('sales.quotations.pdf', quotation.id)"
                    target="_blank"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                >
                    A4 PDF ↗
                </a>
                <a
                    :href="quotation.whatsapp_url"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500"
                >
                    Share on WhatsApp ↗
                </a>
                <button
                    v-if="canManage && quotation.can_edit"
                    type="button"
                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                    @click="sendQuotation(true)"
                >
                    {{ quotation.customer?.email ? 'Mark Sent & Email' : 'Mark Sent' }}
                </button>
                <Link
                    v-if="quotation.can_edit"
                    :href="route('sales.quotations.edit', quotation.id)"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Edit
                </Link>
                <button
                    v-if="canManage && (quotation.status === 'draft' || quotation.status === 'sent')"
                    type="button"
                    class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700"
                    @click="markAccepted"
                >
                    Mark Accepted
                </button>
                <button
                    v-if="canManage && quotation.can_convert"
                    type="button"
                    class="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                    @click="showConvertModal = true"
                >
                    Convert to Sales Order →
                </button>
                <button
                    v-if="canManage && quotation.status !== 'converted' && quotation.status !== 'lost'"
                    type="button"
                    class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100"
                    @click="showLostModal = true"
                >
                    Mark Lost
                </button>
            </SalesNav>

            <!-- Header card -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="font-mono text-xl font-bold text-gray-900">{{ quotation.number }}</h1>
                            <span class="rounded-full bg-indigo-50 px-3 py-0.5 text-xs font-semibold text-indigo-700">
                                {{ quotation.status_label }}
                            </span>
                        </div>
                        <div class="mt-2 text-sm text-gray-600">
                            <span v-if="quotation.customer">
                                Customer:
                                <Link :href="route('customers.show', quotation.customer.id)" class="font-semibold text-indigo-600 hover:underline">
                                    {{ quotation.customer.name }}
                                </Link>
                                ({{ quotation.customer.phone_display || quotation.customer.phone }})
                            </span>
                            <span v-else>Walk-in customer</span>
                            · Location: <strong>{{ quotation.location?.name }}</strong>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-4 text-xs text-gray-500">
                            <span>Valid until: <strong>{{ quotation.valid_until || '—' }}</strong></span>
                            <span v-if="quotation.follow_up_date">Follow-up: <strong>{{ quotation.follow_up_date }}</strong></span>
                            <span v-if="quotation.salesperson_name">Prepared by: {{ quotation.salesperson_name }}</span>
                        </div>
                        <div v-if="quotation.lost_reason" class="mt-2 rounded bg-red-50 px-3 py-1.5 text-xs text-red-700">
                            Lost reason: {{ quotation.lost_reason }}
                        </div>
                        <div v-if="quotation.converted_sales_order" class="mt-2 text-xs font-semibold text-emerald-700">
                            Converted to Sales Order:
                            <Link :href="route('sales.orders.show', quotation.converted_sales_order.id)" class="underline">
                                {{ quotation.converted_sales_order.number }}
                            </Link>
                        </div>
                    </div>

                    <div class="text-right">
                        <div class="text-xs text-gray-500">Grand Total</div>
                        <div class="font-mono text-2xl font-bold text-gray-900">{{ formatMoney(quotation.grand_total) }}</div>
                    </div>
                </div>
            </div>

            <!-- Lines Table -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Item / Spec</th>
                            <th class="px-4 py-3 text-right">Qty</th>
                            <th class="px-4 py-3 text-right">Unit price</th>
                            <th class="px-4 py-3 text-right">Discount</th>
                            <th class="px-4 py-3 text-right">Line total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="l in quotation.lines" :key="l.id">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900">
                                    {{ l.name_snapshot }}
                                    <span v-if="l.is_custom" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                                        Custom
                                    </span>
                                </div>
                                <div v-if="l.sku_snapshot" class="font-mono text-xs text-gray-500">{{ l.sku_snapshot }}</div>
                                <div v-if="l.custom_spec" class="mt-0.5 text-xs text-gray-600">{{ l.custom_spec }}</div>
                                <button
                                    v-if="l.custom_spec_detail"
                                    type="button"
                                    class="mt-1 text-xs font-semibold text-indigo-600 hover:underline"
                                    @click="viewingSpecLine = l"
                                >
                                    View spec &amp; sketches ({{ l.custom_spec_detail.attachments?.length || 0 }})
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatQty(l.quantity) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(l.unit_price) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-gray-600">
                                {{ l.discount_cents > 0 ? formatMoney(l.discount_cents) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900">{{ formatMoney(l.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <div class="ml-auto max-w-xs space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Subtotal</span>
                            <span class="font-mono font-medium">{{ formatMoney(quotation.subtotal) }}</span>
                        </div>
                        <div v-if="quotation.order_discount_cents > 0" class="flex justify-between text-amber-700">
                            <span>Order discount</span>
                            <span class="font-mono">-{{ formatMoney(quotation.order_discount_cents) }}</span>
                        </div>
                        <div v-if="quotation.tax_total > 0" class="flex justify-between text-gray-600">
                            <span>{{ quotation.prices_include_tax ? 'Included VAT' : 'VAT' }}</span>
                            <span class="font-mono">{{ formatMoney(quotation.tax_total) }}</span>
                        </div>
                        <div v-if="quotation.delivery_charge > 0" class="flex justify-between text-gray-600">
                            <span>Delivery charge</span>
                            <span class="font-mono">{{ formatMoney(quotation.delivery_charge) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-2 text-sm font-bold text-gray-900">
                            <span>Grand Total</span>
                            <span class="font-mono">{{ formatMoney(quotation.grand_total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Convert to Sales Order Modal (with price change warning) -->
            <div v-if="showConvertModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-gray-900">Convert Quotation to Sales Order</h2>
                    <p class="mt-1 text-xs text-gray-600">
                        This will create a draft Sales Order from {{ quotation.number }}.
                    </p>

                    <div
                        v-if="conversionPreview.any_price_changed"
                        class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs"
                    >
                        <div class="font-semibold text-amber-900">
                            Warning: Catalog prices have changed since this quotation was prepared
                        </div>
                        <ul class="mt-2 space-y-1 text-amber-800">
                            <li
                                v-for="item in conversionPreview.lines.filter((x) => x.has_price_change)"
                                :key="item.quotation_line_id"
                                class="flex justify-between"
                            >
                                <span>{{ item.name_snapshot }}</span>
                                <span class="font-mono">
                                    Quoted: {{ formatMoney(item.quoted_unit_price) }} → Current: {{ formatMoney(item.current_unit_price) }}
                                </span>
                            </li>
                        </ul>

                        <div class="mt-3 space-y-1.5">
                            <label class="flex items-center gap-2 font-medium text-amber-950">
                                <input v-model="convertForm.honour_quoted_prices" :value="true" type="radio" />
                                Honour quoted prices (subject to role discount / floor limits)
                            </label>
                            <label class="flex items-center gap-2 font-medium text-amber-950">
                                <input v-model="convertForm.honour_quoted_prices" :value="false" type="radio" />
                                Re-price using current catalog prices
                            </label>
                        </div>
                    </div>

                    <div v-if="!canOverride" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs">
                        <div class="font-semibold text-gray-700">Manager PIN (if honouring a price below your limit)</div>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <select v-model="convertForm.approver_id" class="rounded border-gray-300 text-xs">
                                <option value="">Select manager…</option>
                                <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <input v-model="convertForm.pin" type="password" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                        </div>
                        <p v-if="convertForm.errors.pin" class="mt-1 text-xs text-red-600">{{ convertForm.errors.pin }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-xs font-medium text-gray-700"
                            @click="showConvertModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="convertForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                            @click="submitConvert"
                        >
                            Convert to Sales Order
                        </button>
                    </div>
                </div>
            </div>

            <!-- Lost Modal -->
            <div v-if="showLostModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <h2 class="text-base font-bold text-gray-900">Mark Quotation Lost</h2>
                    <form class="mt-3 space-y-3" @submit.prevent="submitLost">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Reason *</label>
                            <input
                                v-model="lostForm.reason"
                                type="text"
                                required
                                placeholder="Price too high, bought elsewhere, lead time…"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <p v-if="lostForm.errors.reason" class="mt-1 text-xs text-red-600">{{ lostForm.errors.reason }}</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="showLostModal = false">
                                Cancel
                            </button>
                            <button type="submit" class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-semibold text-white">
                                Mark Lost
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
            @saved="router.reload({ only: ['quotation'] })"
        />
    </AdminLayout>
</template>

