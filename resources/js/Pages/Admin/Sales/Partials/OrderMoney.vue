<script setup>
import { getJson } from '@/lib/http';
import { decimalToCents, formatMoney } from '@/lib/money';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
    payments: { type: Array, default: () => [] },
    returns: { type: Array, default: () => [] },
    creditNotes: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    returnConfig: { type: Object, required: true },
    documents: { type: Object, default: null },
    approvers: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
    canManageCheques: { type: Boolean, default: false },
});

const centsToDecimal = (cents) => (cents / 100).toFixed(2);

// ── Take payment ──────────────────────────────────────────────
const showPay = ref(false);
const payForm = useForm({
    payment_method_id: '',
    amount: '',
    tendered: '',
    reference: '',
    credit_note_id: null,
    cheque_bank: '',
    cheque_branch: '',
    cheque_number: '',
    cheque_date: '',
    notes: '',
});
const creditNoteNumber = ref('');
const creditNoteInfo = ref(null);
const creditNoteError = ref('');

const payMethod = computed(() => props.paymentMethods.find((m) => m.id === Number(payForm.payment_method_id)) ?? null);
const changePreview = computed(() => {
    if (payMethod.value?.code !== 'cash') return 0;
    const tendered = decimalToCents(payForm.tendered) ?? 0;
    return Math.max(0, tendered - props.order.balance_due);
});

function openPay() {
    payForm.reset();
    payForm.clearErrors();
    payForm.payment_method_id = props.paymentMethods[0]?.id ?? '';
    payForm.amount = centsToDecimal(props.order.balance_due);
    creditNoteInfo.value = null;
    creditNoteNumber.value = '';
    showPay.value = true;
}

async function findCreditNote() {
    creditNoteError.value = '';
    creditNoteInfo.value = null;
    const { ok, data } = await getJson(route('pos.credit-note', { number: creditNoteNumber.value }));
    if (!ok) {
        creditNoteError.value = data.message ?? 'Not found.';
        return;
    }
    creditNoteInfo.value = data.credit_note;
    payForm.credit_note_id = data.credit_note.id;
    payForm.amount = centsToDecimal(Math.min(data.credit_note.remaining_cents, props.order.balance_due));
}

function submitPay() {
    payForm.post(route('sales.orders.payments.store', props.order.id), {
        preserveScroll: true,
        onSuccess: () => (showPay.value = false),
    });
}

// ── Void payment ──────────────────────────────────────────────
const voiding = ref(null);
const voidForm = useForm({ reason: '', approver_id: '', pin: '' });

function openVoid(payment) {
    voidForm.reset();
    voidForm.clearErrors();
    voiding.value = payment;
}

function submitVoid() {
    voidForm.post(route('sales.payments.void', voiding.value.id), {
        preserveScroll: true,
        onSuccess: () => (voiding.value = null),
    });
}

// ── Cheque status ─────────────────────────────────────────────
const chequeForm = useForm({ status: '' });
function setCheque(payment, status) {
    if (status === 'bounced' && !confirm(`Mark cheque ${payment.cheque.number} as bounced? The payment is reversed and the balance becomes due again.`)) return;
    chequeForm.status = status;
    chequeForm.post(route('sales.payments.cheque', payment.id), { preserveScroll: true });
}

// ── Return ────────────────────────────────────────────────────
const showReturn = ref(false);
const returnableLines = computed(() => props.order.lines.filter((l) => Number(l.returnable_qty) > 0));
const returnForm = useForm({
    outcome: 'credit_note',
    reason: '',
    refund_method_id: '',
    refund_reference: '',
    approver_id: '',
    pin: '',
    lines: [],
});

function openReturn() {
    returnForm.reset();
    returnForm.clearErrors();
    returnForm.lines = returnableLines.value.map((l) => ({
        sales_order_line_id: l.id,
        name: l.name_snapshot,
        is_service: l.is_service,
        returnable: l.returnable_qty,
        quantity: '',
        condition: 'resaleable',
        restock_location_id: props.order.location?.id ?? '',
    }));
    returnForm.refund_method_id = props.paymentMethods.find((m) => m.code === 'cash')?.id ?? '';
    showReturn.value = true;
}

function submitReturn() {
    returnForm.post(route('sales.orders.returns.store', props.order.id), {
        preserveScroll: true,
        onSuccess: () => (showReturn.value = false),
    });
}

const refundMethods = computed(() => props.paymentMethods.filter((m) => m.code !== 'credit_note'));
const needsApproval = (approvalNeeded) => approvalNeeded && !props.canOverride;

function copy(text) {
    navigator.clipboard?.writeText(text);
}
</script>

<template>
    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <!-- Payments -->
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-gray-900">Payments</h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="order.can_take_payment"
                        type="button"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                        @click="openPay"
                    >
                        Take payment
                    </button>
                    <button
                        v-if="order.can_return && returnableLines.length"
                        type="button"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        @click="openReturn"
                    >
                        Return items
                    </button>
                </div>
            </div>

            <p v-if="chequeForm.errors.payment" class="mb-3 rounded-lg bg-red-50 p-2 text-xs text-red-700">{{ chequeForm.errors.payment }}</p>

            <p v-if="!payments.length" class="text-xs text-gray-500">No payments yet.</p>
            <ul v-else class="divide-y divide-gray-100 text-sm">
                <li v-for="p in payments" :key="p.id" class="flex flex-wrap items-start justify-between gap-3 py-2.5" :class="!p.counts ? 'opacity-60' : ''">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-semibold text-gray-900">{{ p.number }}</span>
                            <span class="text-xs text-gray-700">{{ p.method }}</span>
                            <span v-if="p.is_voided" class="rounded bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700">Voided</span>
                            <span
                                v-if="p.cheque"
                                class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                :class="{
                                    'bg-amber-100 text-amber-800': ['pending', 'deposited'].includes(p.cheque.status),
                                    'bg-emerald-100 text-emerald-800': p.cheque.status === 'cleared',
                                    'bg-red-100 text-red-700': p.cheque.status === 'bounced',
                                }"
                            >
                                Cheque {{ p.cheque.status_label }}
                            </span>
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500">
                            {{ p.date }} · {{ p.received_by }}
                            <span v-if="p.reference"> · Ref {{ p.reference }}</span>
                            <span v-if="p.cheque"> · #{{ p.cheque.number }} {{ p.cheque.bank }} ({{ p.cheque.date }})</span>
                            <span v-if="p.change_given"> · Change {{ formatMoney(p.change_given) }}</span>
                            <span v-if="p.void_reason"> · {{ p.void_reason }}</span>
                        </div>
                        <div v-if="p.cheque?.next.length && canManageCheques && !p.is_voided" class="mt-1.5 flex gap-1.5">
                            <button
                                v-for="s in p.cheque.next"
                                :key="s.value"
                                type="button"
                                class="rounded border px-2 py-0.5 text-[11px] font-medium"
                                :class="s.value === 'bounced' ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                                @click="setCheque(p, s.value)"
                            >
                                Mark {{ s.label.toLowerCase() }}
                            </button>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-mono font-semibold" :class="p.counts ? 'text-gray-900' : 'text-gray-400 line-through'">{{ formatMoney(p.amount) }}</div>
                        <div class="mt-1 flex justify-end gap-2 text-xs">
                            <a :href="p.pdf_url" target="_blank" class="text-indigo-600 hover:underline">Receipt</a>
                            <button v-if="!p.is_voided && canManage" type="button" class="text-red-600 hover:underline" @click="openVoid(p)">Void</button>
                        </div>
                    </div>
                </li>
            </ul>

            <div class="mt-4 space-y-1 border-t border-gray-100 pt-3 text-xs">
                <div class="flex justify-between"><span class="text-gray-500">Grand total</span><span class="font-mono">{{ formatMoney(order.grand_total) }}</span></div>
                <div v-if="order.returned_total" class="flex justify-between"><span class="text-gray-500">Returned goods</span><span class="font-mono">−{{ formatMoney(order.returned_total) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Paid</span><span class="font-mono">{{ formatMoney(order.paid_amount) }}</span></div>
                <div v-if="order.refunded_total" class="flex justify-between"><span class="text-gray-500">Refunded / credited</span><span class="font-mono">−{{ formatMoney(order.refunded_total) }}</span></div>
                <div class="flex justify-between text-sm font-bold"><span>Balance due</span><span class="font-mono" :class="order.balance_due ? 'text-amber-700' : 'text-emerald-700'">{{ formatMoney(order.balance_due) }}</span></div>
            </div>
        </div>

        <!-- Documents -->
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-3 text-sm font-bold text-gray-900">Print & share</h2>
            <p v-if="!documents" class="text-xs text-gray-500">The invoice is available once the order is confirmed.</p>
            <div v-else class="space-y-2 text-sm">
                <a :href="documents.invoice_url" target="_blank" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">A4 {{ documents.title }} (PDF) ↗</a>
                <a :href="`${documents.receipt_url}?print=1`" target="_blank" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">80 mm receipt ↗</a>
                <a :href="route('sales.orders.delivery-note', order.id)" target="_blank" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">Delivery note (PDF) ↗</a>
                <div class="flex gap-2 pt-1">
                    <a :href="documents.share.whatsapp" target="_blank" rel="noopener" class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-center text-xs font-semibold text-white hover:bg-emerald-500">WhatsApp link</a>
                    <a v-if="documents.share.sms" :href="documents.share.sms" class="flex-1 rounded-lg bg-slate-800 px-3 py-2 text-center text-xs font-semibold text-white hover:bg-slate-700">SMS link</a>
                </div>
                <button type="button" class="w-full truncate rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-left font-mono text-[11px] text-gray-500 hover:bg-gray-50" :title="documents.share.url" @click="copy(documents.share.url)">
                    Copy digital copy link
                </button>
            </div>

            <div v-if="creditNotes.length" class="mt-5">
                <h3 class="mb-2 text-xs font-semibold text-gray-500 uppercase">Credit notes</h3>
                <a v-for="n in creditNotes" :key="n.id" :href="n.pdf_url" target="_blank" class="mb-1 flex justify-between rounded border border-gray-100 px-2 py-1 text-xs hover:bg-gray-50">
                    <span class="font-mono">{{ n.number }}</span>
                    <span>{{ formatMoney(n.remaining) }} / {{ formatMoney(n.amount) }} · {{ n.status_label }}</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Returns history -->
    <div v-if="returns.length" class="mb-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-sm font-bold text-gray-900">Returns</h2>
        <div v-for="r in returns" :key="r.id" class="border-b border-gray-100 py-2 text-xs last:border-0">
            <div class="flex flex-wrap justify-between gap-2">
                <div>
                    <span class="font-mono font-semibold">{{ r.number }}</span>
                    · {{ r.date }} · {{ r.outcome_label }} · {{ r.created_by }}
                    <span v-if="r.approved_by"> (approved by {{ r.approved_by }})</span>
                </div>
                <div class="font-mono">{{ formatMoney(r.total) }}<span v-if="r.settled_amount" class="text-gray-500"> · settled {{ formatMoney(r.settled_amount) }}</span></div>
            </div>
            <div class="text-gray-500">{{ r.reason }}<span v-if="r.credit_note_number"> · {{ r.credit_note_number }}</span><span v-if="r.refund_number"> · {{ r.refund_number }}</span></div>
            <ul class="mt-1 text-gray-600">
                <li v-for="(l, i) in r.lines" :key="i">{{ l.quantity }} × {{ l.name }} · {{ l.condition }}<span v-if="l.restock"> → {{ l.restock }}</span></li>
            </ul>
        </div>
    </div>

    <!-- Take payment modal -->
    <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitPay">
            <h2 class="text-base font-bold text-gray-900">Take payment</h2>
            <p class="mt-1 text-xs text-gray-500">Balance due {{ formatMoney(order.balance_due) }}</p>

            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-for="m in paymentMethods"
                    :key="m.id"
                    type="button"
                    class="rounded-lg border px-2 py-2 text-xs font-semibold"
                    :class="Number(payForm.payment_method_id) === m.id ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-700 hover:bg-gray-50'"
                    @click="payForm.payment_method_id = m.id"
                >
                    {{ m.name }}
                </button>
            </div>

            <div class="mt-4 space-y-3 text-sm">
                <template v-if="payMethod?.code === 'cash'">
                    <label class="block text-xs font-medium text-gray-700">Cash received</label>
                    <input v-model="payForm.tendered" inputmode="decimal" class="w-full rounded-lg border-gray-300 text-lg font-mono" placeholder="0.00" autofocus />
                    <p class="text-xs text-gray-500">Applied: up to {{ formatMoney(order.balance_due) }}. Change: <strong>{{ formatMoney(changePreview) }}</strong></p>
                </template>
                <template v-else>
                    <label class="block text-xs font-medium text-gray-700">Amount</label>
                    <input v-model="payForm.amount" inputmode="decimal" class="w-full rounded-lg border-gray-300 font-mono" />
                </template>

                <template v-if="payMethod?.code === 'credit_note'">
                    <div class="flex gap-2">
                        <input v-model="creditNoteNumber" placeholder="Credit note no. (CN-…)" class="flex-1 rounded-lg border-gray-300 font-mono text-sm uppercase" />
                        <button type="button" class="rounded-lg border px-3 text-xs" @click="findCreditNote">Find</button>
                    </div>
                    <p v-if="creditNoteError" class="text-xs text-red-600">{{ creditNoteError }}</p>
                    <p v-if="creditNoteInfo" class="text-xs" :class="creditNoteInfo.usable ? 'text-emerald-700' : 'text-red-600'">
                        {{ creditNoteInfo.number }}: {{ formatMoney(creditNoteInfo.remaining_cents) }} left<span v-if="creditNoteInfo.customer_name"> · {{ creditNoteInfo.customer_name }}</span>
                        <span v-if="!creditNoteInfo.usable"> · not usable</span>
                    </p>
                </template>

                <template v-if="payMethod?.code === 'cheque'">
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="payForm.cheque_bank" placeholder="Bank" class="rounded-lg border-gray-300 text-sm" />
                        <input v-model="payForm.cheque_branch" placeholder="Branch" class="rounded-lg border-gray-300 text-sm" />
                        <input v-model="payForm.cheque_number" placeholder="Cheque no." class="rounded-lg border-gray-300 font-mono text-sm" />
                        <input v-model="payForm.cheque_date" type="date" class="rounded-lg border-gray-300 text-sm" />
                    </div>
                    <p class="text-xs text-amber-700">Counts as paid but stays flagged until the cheque clears.</p>
                </template>

                <div v-if="payMethod?.requires_reference">
                    <label class="block text-xs font-medium text-gray-700">Reference (approval code / transaction ID)</label>
                    <input v-model="payForm.reference" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm" />
                </div>

                <p v-if="payForm.errors.payment" class="rounded bg-red-50 p-2 text-xs text-red-700">{{ payForm.errors.payment }}</p>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="showPay = false">Close</button>
                <button type="submit" :disabled="payForm.processing" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Record payment</button>
            </div>
        </form>
    </div>

    <!-- Void modal -->
    <div v-if="voiding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <form class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitVoid">
            <h2 class="text-base font-bold text-gray-900">Void payment {{ voiding.number }}</h2>
            <p class="mt-1 text-xs text-gray-500">{{ voiding.method }} · {{ formatMoney(voiding.amount) }}. The order's balance goes back up. This is audited.</p>
            <div class="mt-4 space-y-3">
                <input v-model="voidForm.reason" placeholder="Reason" class="w-full rounded-lg border-gray-300 text-sm" required />
                <div v-if="needsApproval(true)" class="rounded border border-amber-200 bg-amber-50 p-3 text-xs">
                    <div class="font-semibold text-amber-900">Only a manager can void a payment</div>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <select v-model="voidForm.approver_id" class="rounded border-gray-300 text-xs">
                            <option value="">Select manager…</option>
                            <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <input v-model="voidForm.pin" type="password" inputmode="numeric" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                    </div>
                </div>
                <p v-if="voidForm.errors.pin || voidForm.errors.payment" class="text-xs text-red-600">{{ voidForm.errors.pin || voidForm.errors.payment }}</p>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="voiding = null">Close</button>
                <button type="submit" :disabled="voidForm.processing" class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-semibold text-white">Void payment</button>
            </div>
        </form>
    </div>

    <!-- Return modal -->
    <div v-if="showReturn" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <form class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitReturn">
            <h2 class="text-base font-bold text-gray-900">Return items</h2>
            <p class="mt-1 text-xs text-gray-500">
                Returns within {{ returnConfig.return_days }} days need no manager.
                <span v-if="returnConfig.is_late" class="font-semibold text-amber-700">This sale is past the return window: a manager must approve.</span>
            </p>

            <div class="mt-4 space-y-2">
                <div v-for="(l, i) in returnForm.lines" :key="l.sales_order_line_id" class="grid grid-cols-2 items-center gap-2 rounded-lg border border-gray-200 p-3 text-xs sm:grid-cols-[1fr_6rem_8rem_8rem]">
                    <div class="col-span-2 sm:col-span-1">
                        <div class="font-semibold text-gray-900">{{ l.name }}</div>
                        <div class="text-gray-500">Returnable: {{ l.returnable }}</div>
                    </div>
                    <input v-model="returnForm.lines[i].quantity" inputmode="decimal" placeholder="Qty" class="rounded border-gray-300 text-right text-xs" />
                    <select v-model="returnForm.lines[i].condition" class="rounded border-gray-300 text-xs">
                        <option v-for="c in returnConfig.conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <select v-if="!l.is_service" v-model="returnForm.lines[i].restock_location_id" class="rounded border-gray-300 text-xs" title="Restock at">
                        <option v-for="loc in returnConfig.locations" :key="loc.id" :value="loc.id">→ {{ loc.code }}</option>
                    </select>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-gray-700">Outcome</label>
                    <select v-model="returnForm.outcome" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option v-for="o in returnConfig.outcomes" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <p class="mt-1 text-[11px] text-gray-500">
                        Only what was paid beyond the new total is settled; the rest just reduces the balance.
                        <span v-if="returnForm.outcome === 'exchange'">Exchange issues a credit note: use it to pay for the new sale.</span>
                    </p>
                </div>
                <div v-if="returnForm.outcome === 'refund'">
                    <label class="block text-xs font-medium text-gray-700">Refund by</label>
                    <select v-model="returnForm.refund_method_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option v-for="m in refundMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                    <input v-model="returnForm.refund_reference" placeholder="Reference (optional)" class="mt-2 w-full rounded-lg border-gray-300 text-sm" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-700">Reason</label>
                    <input v-model="returnForm.reason" class="mt-1 w-full rounded-lg border-gray-300 text-sm" required />
                </div>
                <div v-if="needsApproval(returnConfig.is_late)" class="rounded border border-amber-200 bg-amber-50 p-3 text-xs sm:col-span-2">
                    <div class="font-semibold text-amber-900">Manager approval for a late return</div>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <select v-model="returnForm.approver_id" class="rounded border-gray-300 text-xs">
                            <option value="">Select manager…</option>
                            <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <input v-model="returnForm.pin" type="password" inputmode="numeric" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                    </div>
                </div>
            </div>

            <p v-if="returnForm.errors.return || returnForm.errors.pin || returnForm.errors.lines" class="mt-3 rounded bg-red-50 p-2 text-xs text-red-700">
                {{ returnForm.errors.return || returnForm.errors.pin || returnForm.errors.lines }}
            </p>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="showReturn = false">Close</button>
                <button type="submit" :disabled="returnForm.processing" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Record return</button>
            </div>
        </form>
    </div>
</template>
