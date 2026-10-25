<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson, postJson, requestJson } from '@/lib/http';
import { decimalToCents, formatMoney } from '@/lib/money';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    approvers: { type: Array, default: () => [] },
    heldCarts: { type: Array, default: () => [] },
    fulfilmentTypes: { type: Array, default: () => [] },
    pricingConfig: { type: Object, required: true },
    canOverride: { type: Boolean, default: false },
    shift: { type: Object, default: null },
    requireShift: { type: Boolean, default: true },
});

const page = usePage();
const location = computed(() => page.props.location);
const centsToDecimal = (cents) => (Math.max(0, cents) / 100).toFixed(2);

// ── Product search / scan / browse ───────────────────────────
const searchInput = ref(null);
const search = ref('');
const categoryId = ref(null);
const results = ref([]);
const searching = ref(false);
const searchMessage = ref('');
let searchTimer = null;
let searchAbort = null;

async function runSearch({ addExact = false } = {}) {
    searchAbort?.abort();
    if (!search.value.trim() && !categoryId.value) {
        results.value = [];
        return;
    }
    searchAbort = new AbortController();
    searching.value = true;
    try {
        const { ok, data } = await getJson(route('pos.products', { q: search.value.trim() || undefined, category_id: categoryId.value || undefined }), { signal: searchAbort.signal });
        if (!ok) return;
        results.value = data.variants;
        searchMessage.value = data.variants.length ? '' : 'Nothing found.';
        if (addExact && data.exact && data.variants.length === 1) {
            addToCart(data.variants[0]);
            search.value = '';
            results.value = [];
        }
    } catch {
        // aborted by a newer search
    } finally {
        searching.value = false;
    }
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(), 250);
});

function onSearchEnter() {
    clearTimeout(searchTimer);
    runSearch({ addExact: true });
}

function pickCategory(id) {
    categoryId.value = categoryId.value === id ? null : id;
    runSearch();
}

// ── Cart ──────────────────────────────────────────────────────
const blankCart = () => ({
    customer: null,
    kind: 'cash_and_carry',
    fulfilment_type: 'collect',
    requested_delivery_date: '',
    order_discount_type: '',
    order_discount_value: '',
    notes: '',
    lines: [],
});
const cart = reactive(blankCart());
const heldCartId = ref(null);
const held = ref(props.heldCarts);

function addToCart(v) {
    const existing = cart.lines.find((l) => l.product_variant_id === v.id);
    if (existing) {
        existing.quantity = String(Number(existing.quantity || 0) + 1);
        return;
    }
    cart.lines.push({
        product_variant_id: v.id,
        name: v.name,
        sku: v.sku,
        options: v.options,
        unit_price: v.price,
        quantity: '1',
        discount_type: '',
        discount_value: '',
        available_here: v.available_here,
        available_here_milli: v.available_here_milli,
        is_service: v.is_service,
        type: v.type,
        source: v.default_source,
    });
    if (v.type === 'made_to_order' && cart.kind === 'cash_and_carry') {
        cart.kind = 'order';
    }
}

function step(line, delta) {
    const next = Math.max(0, Number(line.quantity || 0) + delta);
    if (next === 0) {
        removeLine(line);
        return;
    }
    line.quantity = String(next);
}

function removeLine(line) {
    cart.lines.splice(cart.lines.indexOf(line), 1);
}

function clearCart() {
    Object.assign(cart, blankCart());
    heldCartId.value = null;
    priced.value = null;
    approval.approver_id = '';
    approval.pin = '';
}

const lowStock = (line) => !line.is_service && line.source === 'stock' && Number(line.quantity) * 1000 > line.available_here_milli;

// Server-side totals (PricingService), refreshed as the cart changes.
const priced = ref(null);
const pricing = ref(false);
let priceTimer = null;

const pricePayload = () => ({
    order_discount_type: cart.order_discount_type || null,
    order_discount_value: cart.order_discount_value || null,
    lines: cart.lines.map((l) => ({
        product_variant_id: l.product_variant_id,
        quantity: String(l.quantity || '0'),
        unit_price: String(l.unit_price || '0'),
        discount_type: l.discount_type || null,
        discount_value: l.discount_value || null,
    })),
});

watch(
    () => JSON.stringify(pricePayload()),
    () => {
        clearTimeout(priceTimer);
        if (!cart.lines.length) {
            priced.value = null;
            return;
        }
        priceTimer = setTimeout(async () => {
            pricing.value = true;
            const { ok, data } = await postJson(route('pos.price'), pricePayload());
            pricing.value = false;
            if (ok) priced.value = data;
        }, 200);
    },
);

const total = computed(() => priced.value?.grand_total ?? 0);

// ── Customer ──────────────────────────────────────────────────
const customerQuery = ref('');
const customerResults = ref([]);
const creatingCustomer = ref(false);
const newCustomer = reactive({ name: '', phone: '', errors: {} });
let customerTimer = null;

watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    if (q.trim().length < 3) {
        customerResults.value = [];
        return;
    }
    customerTimer = setTimeout(async () => {
        const { ok, data } = await getJson(route('customers.lookup', { q }));
        if (ok) customerResults.value = data.customers;
    }, 250);
});

function chooseCustomer(c) {
    cart.customer = { id: c.id, name: c.name, phone_display: c.phone_display, is_blacklisted: c.is_blacklisted, default_address: c.default_address };
    customerQuery.value = '';
    customerResults.value = [];
    creatingCustomer.value = false;
}

function startNewCustomer() {
    creatingCustomer.value = true;
    newCustomer.name = '';
    newCustomer.phone = /\d/.test(customerQuery.value) ? customerQuery.value : '';
    newCustomer.errors = {};
}

async function saveCustomer() {
    const { ok, data } = await postJson(route('customers.store'), { name: newCustomer.name, phone: newCustomer.phone, type: 'individual' });
    if (!ok) {
        newCustomer.errors = data.errors ?? { name: [data.message] };
        return;
    }
    chooseCustomer(data.customer);
}

// ── Hold / resume ─────────────────────────────────────────────
async function holdCart() {
    if (!cart.lines.length) return;
    const label = prompt('Name this cart (e.g. customer name):', cart.customer?.name ?? `Cart ${new Date().toLocaleTimeString()}`);
    if (!label) return;
    const { ok, data } = await postJson(route('pos.held.store'), { label, cart: JSON.parse(JSON.stringify(cart)) });
    if (ok) {
        held.value = data.held;
        clearCart();
    }
}

const showHeld = ref(false);
async function resume(h) {
    if (cart.lines.length && !confirm('Replace the current cart?')) return;
    const { ok, data } = await getJson(route('pos.held.show', h.id));
    if (!ok) return;
    Object.assign(cart, blankCart(), data.cart);
    heldCartId.value = data.id;
    showHeld.value = false;
}

async function discard(h) {
    if (!confirm(`Discard held cart "${h.label}"?`)) return;
    const { ok, data } = await requestJson(route('pos.held.destroy', h.id), { method: 'DELETE' });
    if (ok) held.value = data.held;
}

// ── Pay pad ───────────────────────────────────────────────────
const showPay = ref(false);
const payments = ref([]);
const payMethodId = ref(null);
const entry = ref('');
const payExtra = reactive({ reference: '', cheque_bank: '', cheque_branch: '', cheque_number: '', cheque_date: '', credit_note: null, credit_note_number: '', credit_note_error: '' });
const payError = ref('');
const submitting = ref(false);

const payMethod = computed(() => props.paymentMethods.find((m) => m.id === payMethodId.value) ?? null);
const appliedTotal = computed(() => payments.value.reduce((sum, p) => sum + p.applied, 0));
const remaining = computed(() => Math.max(0, total.value - appliedTotal.value));
const changeDue = computed(() => payments.value.reduce((sum, p) => sum + (p.tendered ? p.tendered - p.applied : 0), 0));
const canComplete = computed(() => cart.lines.length > 0 && !pricing.value && (cart.kind === 'order' ? true : remaining.value === 0 && total.value > 0));

const quickCash = computed(() => {
    const r = remaining.value;
    if (!r) return [];
    const options = new Set([r]);
    for (const unit of [10000, 50000, 100000, 500000]) {
        options.add(Math.ceil(r / unit) * unit);
    }
    return [...options].sort((a, b) => a - b).slice(0, 4);
});

function openPay() {
    if (!cart.lines.length) return;
    if (cart.kind === 'order' && !cart.customer) {
        alert('Orders to collect later or deliver need a customer.');
        return;
    }
    payError.value = '';
    payMethodId.value = props.paymentMethods[0]?.id ?? null;
    entry.value = remaining.value ? centsToDecimal(remaining.value) : '';
    showPay.value = true;
}

function selectMethod(m) {
    payMethodId.value = m.id;
    payError.value = '';
    entry.value = remaining.value ? centsToDecimal(m.code === 'credit_note' && payExtra.credit_note ? Math.min(payExtra.credit_note.remaining_cents, remaining.value) : remaining.value) : '';
}

function key(k) {
    if (k === 'C') entry.value = '';
    else if (k === '⌫') entry.value = entry.value.slice(0, -1);
    else if (k === '.' && entry.value.includes('.')) return;
    else entry.value += k;
}

async function findCreditNote() {
    payExtra.credit_note_error = '';
    payExtra.credit_note = null;
    const { ok, data } = await getJson(route('pos.credit-note', { number: payExtra.credit_note_number }));
    if (!ok) {
        payExtra.credit_note_error = data.message ?? 'Not found.';
        return;
    }
    if (!data.credit_note.usable) {
        payExtra.credit_note_error = `${data.credit_note.number} is used up or expired.`;
        return;
    }
    payExtra.credit_note = data.credit_note;
    entry.value = centsToDecimal(Math.min(data.credit_note.remaining_cents, remaining.value));
}

function addPayment(amountCents = null) {
    payError.value = '';
    const m = payMethod.value;
    const cents = amountCents ?? decimalToCents(entry.value);
    if (!m || !cents) {
        payError.value = 'Enter an amount.';
        return;
    }
    if (!remaining.value) {
        payError.value = 'Nothing left to pay.';
        return;
    }
    const isCash = m.code === 'cash';
    if (!isCash && cents > remaining.value) {
        payError.value = `Only ${formatMoney(remaining.value)} is due. Only cash can be more (change).`;
        return;
    }
    if (m.requires_reference && !payExtra.reference.trim()) {
        payError.value = `${m.name} needs a reference.`;
        return;
    }
    if (m.code === 'cheque' && (!payExtra.cheque_bank || !payExtra.cheque_number || !payExtra.cheque_date)) {
        payError.value = 'Enter the bank, cheque number and date.';
        return;
    }
    if (m.code === 'credit_note' && !payExtra.credit_note) {
        payError.value = 'Find the credit note first.';
        return;
    }
    const applied = Math.min(cents, remaining.value);
    payments.value.push({
        method: m,
        applied,
        tendered: isCash ? cents : null,
        reference: m.requires_reference ? payExtra.reference.trim() : null,
        cheque: m.code === 'cheque' ? { bank: payExtra.cheque_bank, branch: payExtra.cheque_branch, number: payExtra.cheque_number, date: payExtra.cheque_date } : null,
        credit_note: m.code === 'credit_note' ? payExtra.credit_note : null,
    });
    Object.assign(payExtra, { reference: '', cheque_bank: '', cheque_branch: '', cheque_number: '', cheque_date: '', credit_note: null, credit_note_number: '', credit_note_error: '' });
    entry.value = remaining.value ? centsToDecimal(remaining.value) : '';
}

function removePayment(i) {
    payments.value.splice(i, 1);
    entry.value = centsToDecimal(remaining.value);
}

// ── Approval + checkout ───────────────────────────────────────
const approval = reactive({ open: false, approver_id: '', pin: '', message: '' });
const result = ref(null);

function checkoutPayload() {
    // Cash goes last so it only covers what the other methods left (the rest is change).
    const ordered = [...payments.value.filter((p) => p.method.code !== 'cash'), ...payments.value.filter((p) => p.method.code === 'cash')];
    return {
        customer_id: cart.customer?.id ?? null,
        kind: cart.kind,
        fulfilment_type: cart.kind === 'order' ? cart.fulfilment_type : 'collect',
        delivery_address: cart.kind === 'order' && cart.fulfilment_type === 'deliver' ? cart.customer?.default_address ?? null : null,
        requested_delivery_date: cart.requested_delivery_date || null,
        order_discount_type: cart.order_discount_type || null,
        order_discount_value: cart.order_discount_value || null,
        notes: cart.notes || null,
        approver_id: approval.approver_id || null,
        pin: approval.pin || null,
        held_cart_id: heldCartId.value,
        lines: cart.lines.map((l) => ({
            product_variant_id: l.product_variant_id,
            quantity: String(l.quantity),
            unit_price: String(l.unit_price),
            discount_type: l.discount_type || null,
            discount_value: l.discount_value || null,
            source: l.source,
        })),
        payments: ordered.map((p) => ({
            payment_method_id: p.method.id,
            amount: centsToDecimal(p.applied),
            tendered: p.tendered ? centsToDecimal(p.tendered) : null,
            reference: p.reference,
            credit_note_id: p.credit_note?.id ?? null,
            cheque_bank: p.cheque?.bank ?? null,
            cheque_branch: p.cheque?.branch ?? null,
            cheque_number: p.cheque?.number ?? null,
            cheque_date: p.cheque?.date ?? null,
        })),
    };
}

async function complete() {
    if (priced.value?.needs_approval && !props.canOverride && !approval.pin) {
        approval.message = priced.value.approval_reasons.join('; ');
        approval.open = true;
        return;
    }
    submitting.value = true;
    payError.value = '';
    const { ok, status, data } = await postJson(route('pos.checkout'), checkoutPayload());
    submitting.value = false;

    if (ok) {
        result.value = data.order;
        showPay.value = false;
        approval.open = false;
        payments.value = [];
        held.value = held.value.filter((h) => h.id !== heldCartId.value);
        clearCart();
        return;
    }

    if (data.reason === 'approval_required' || data.reason === 'pin_locked') {
        approval.message = data.message;
        approval.pin = '';
        approval.open = true;
        return;
    }

    if (data.reason === 'shift_required') {
        payError.value = `${data.message}`;
        approval.open = false;
        return;
    }

    payError.value = status === 422 && data.errors ? Object.values(data.errors).flat()[0] : data.message ?? 'The sale could not be completed.';
    approval.open = false;
}

function submitApproval() {
    approval.open = false;
    complete();
}

function printReceipt(order) {
    window.open(`${order.receipt_url}?print=1`, '_blank', 'width=420,height=700');
}

function newSale() {
    result.value = null;
    nextTick(() => searchInput.value?.focus());
}

onMounted(() => searchInput.value?.focus());
</script>

<template>
    <Head title="POS" />

    <AdminLayout title="POS">
        <div v-if="requireShift && !shift" class="mx-auto mt-3 max-w-[1600px] px-3 sm:px-4 lg:px-6">
            <p class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-950 ring-1 ring-amber-200">
                No open shift on this till: payments can't be taken yet.
                <Link :href="route('cash.index')" class="rounded-lg bg-amber-600 px-3 py-1.5 font-semibold text-white">Open a shift</Link>
            </p>
        </div>
        <div class="mx-auto grid max-w-[1600px] gap-4 px-3 py-4 sm:px-4 lg:grid-cols-[1fr_26rem] lg:px-6">
            <!-- Left: search & products -->
            <section class="min-w-0">
                <div class="flex gap-2">
                    <input
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        autocomplete="off"
                        placeholder="Scan a barcode or search name / SKU…"
                        class="h-12 w-full rounded-xl border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        @keydown.enter.prevent="onSearchEnter"
                    />
                    <button type="button" class="relative rounded-xl border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="showHeld = true">
                        Held
                        <span v-if="held.length" class="absolute -top-1.5 -right-1.5 rounded-full bg-amber-500 px-1.5 text-[11px] font-bold text-white">{{ held.length }}</span>
                    </button>
                </div>

                <div v-if="categories.length" class="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                    <button
                        v-for="c in categories"
                        :key="c.id"
                        type="button"
                        class="shrink-0 rounded-full border px-3 py-1 text-sm"
                        :class="categoryId === c.id ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="pickCategory(c.id)"
                    >
                        {{ c.name }}
                    </button>
                </div>

                <p v-if="searching" class="mt-4 text-sm text-gray-500">Searching…</p>
                <p v-else-if="searchMessage && (search || categoryId)" class="mt-4 text-sm text-gray-500">{{ searchMessage }}</p>
                <p v-else-if="!results.length" class="mt-10 text-center text-sm text-gray-400">Scan or search to add items. Pick a category to browse.</p>

                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    <button
                        v-for="v in results"
                        :key="v.id"
                        type="button"
                        class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white text-left shadow-sm transition hover:border-indigo-400 hover:shadow"
                        @click="addToCart(v)"
                    >
                        <div class="aspect-[4/3] w-full bg-gray-100">
                            <img v-if="v.thumbnail" :src="v.thumbnail" alt="" class="h-full w-full object-cover" loading="lazy" />
                        </div>
                        <div class="flex flex-1 flex-col p-2.5">
                            <div class="line-clamp-2 text-sm font-semibold text-gray-900">{{ v.name }}</div>
                            <div v-if="v.options" class="truncate text-xs text-gray-500">{{ v.options }}</div>
                            <div class="font-mono text-[11px] text-gray-400">{{ v.sku }}</div>
                            <div class="mt-auto pt-1.5 font-mono text-sm font-bold text-gray-900">{{ formatMoney(v.price_cents) }}</div>
                            <div v-if="!v.is_service" class="text-xs" :class="v.available_here_milli > 0 ? 'text-emerald-700' : 'text-red-600'">
                                {{ v.available_here_milli > 0 ? `${v.available_here} here` : 'None here' }}
                            </div>
                            <div v-for="e in v.elsewhere.slice(0, 2)" :key="e.code" class="truncate text-[11px] text-gray-500">{{ e.available }} at {{ e.location }}</div>
                        </div>
                    </button>
                </div>
            </section>

            <!-- Right: cart -->
            <aside class="flex min-h-[70vh] flex-col rounded-xl border border-gray-200 bg-white shadow-sm lg:sticky lg:top-4 lg:max-h-[calc(100vh-6rem)]">
                <!-- Customer -->
                <div class="border-b border-gray-100 p-3">
                    <div v-if="cart.customer" class="flex items-center justify-between gap-2 rounded-lg bg-indigo-50 px-3 py-2">
                        <div>
                            <div class="text-sm font-semibold text-indigo-900">{{ cart.customer.name }}</div>
                            <div class="text-xs text-indigo-700">{{ cart.customer.phone_display }}</div>
                            <div v-if="cart.customer.is_blacklisted" class="text-xs font-semibold text-red-600">Blacklisted</div>
                        </div>
                        <button type="button" class="text-xs text-indigo-700 hover:underline" @click="cart.customer = null">Change</button>
                    </div>
                    <div v-else-if="creatingCustomer" class="space-y-2">
                        <input v-model="newCustomer.name" placeholder="Customer name" class="w-full rounded-lg border-gray-300 text-sm" />
                        <input v-model="newCustomer.phone" placeholder="Phone (077 123 4567)" inputmode="tel" class="w-full rounded-lg border-gray-300 text-sm" />
                        <p v-for="(msg, field) in newCustomer.errors" :key="field" class="text-xs text-red-600">{{ Array.isArray(msg) ? msg[0] : msg }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="flex-1 rounded-lg bg-indigo-600 py-1.5 text-sm font-semibold text-white" @click="saveCustomer">Save customer</button>
                            <button type="button" class="rounded-lg border px-3 text-sm" @click="creatingCustomer = false">Cancel</button>
                        </div>
                    </div>
                    <div v-else class="relative">
                        <input v-model="customerQuery" placeholder="Customer phone or name (optional for cash & carry)" inputmode="search" class="w-full rounded-lg border-gray-300 text-sm" />
                        <div v-if="customerQuery.trim().length >= 3" class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                            <button
                                v-for="c in customerResults"
                                :key="c.id"
                                type="button"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50"
                                @click="chooseCustomer(c)"
                            >
                                <span class="font-medium">{{ c.name }}</span> <span class="text-gray-500">{{ c.phone_display }}</span>
                            </button>
                            <button type="button" class="block w-full border-t border-gray-100 px-3 py-2 text-left text-sm font-medium text-indigo-600 hover:bg-indigo-50" @click="startNewCustomer">
                                + New customer
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Lines -->
                <div class="flex-1 overflow-y-auto">
                    <p v-if="!cart.lines.length" class="p-6 text-center text-sm text-gray-400">Cart is empty.</p>
                    <div v-for="(l, i) in cart.lines" :key="l.product_variant_id" class="border-b border-gray-100 px-3 py-2.5">
                        <div class="flex justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-gray-900">{{ l.name }}</div>
                                <div class="text-[11px] text-gray-500">{{ l.sku }}<span v-if="l.options"> · {{ l.options }}</span></div>
                                <div v-if="lowStock(l)" class="text-[11px] font-semibold text-red-600">Only {{ l.available_here }} here</div>
                                <div v-if="l.source !== 'stock' && !l.is_service" class="text-[11px] text-amber-700">Made to order</div>
                            </div>
                            <div class="shrink-0 text-right font-mono text-sm font-semibold">
                                {{ priced?.lines[i] ? formatMoney(priced.lines[i].line_total) : '…' }}
                            </div>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <div class="flex items-center rounded-lg border border-gray-300">
                                <button type="button" class="px-2.5 py-1 text-base leading-none" aria-label="Less" @click="step(l, -1)">−</button>
                                <input v-model="l.quantity" inputmode="decimal" class="w-12 border-0 p-1 text-center text-sm focus:ring-0" />
                                <button type="button" class="px-2.5 py-1 text-base leading-none" aria-label="More" @click="step(l, 1)">+</button>
                            </div>
                            <span class="text-xs text-gray-400">×</span>
                            <input v-model="l.unit_price" inputmode="decimal" class="w-24 rounded-lg border-gray-300 py-1 text-right font-mono text-xs" title="Unit price" />
                            <select v-model="l.discount_type" class="rounded-lg border-gray-300 py-1 pr-7 text-xs" title="Line discount">
                                <option value="">No disc.</option>
                                <option value="percent">%</option>
                                <option value="fixed">Rs</option>
                            </select>
                            <input v-if="l.discount_type" v-model="l.discount_value" inputmode="decimal" class="w-16 rounded-lg border-gray-300 py-1 text-right text-xs" />
                            <button type="button" class="ml-auto text-xs text-red-600 hover:underline" @click="removeLine(l)">Remove</button>
                        </div>
                    </div>
                </div>

                <!-- Sale type, discount & totals -->
                <div class="space-y-2 border-t border-gray-200 p-3 text-sm">
                    <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 text-xs font-semibold">
                        <button type="button" class="rounded-md py-1.5" :class="cart.kind === 'cash_and_carry' ? 'bg-white shadow' : 'text-gray-600'" @click="cart.kind = 'cash_and_carry'">Cash & carry</button>
                        <button type="button" class="rounded-md py-1.5" :class="cart.kind === 'order' ? 'bg-white shadow' : 'text-gray-600'" @click="cart.kind = 'order'">Order</button>
                    </div>
                    <div v-if="cart.kind === 'order'" class="grid grid-cols-2 gap-2">
                        <select v-model="cart.fulfilment_type" class="rounded-lg border-gray-300 text-xs">
                            <option v-for="f in fulfilmentTypes" :key="f.value" :value="f.value">{{ f.value === 'collect' ? 'Collect later' : 'Deliver' }}</option>
                        </select>
                        <input v-model="cart.requested_delivery_date" type="date" class="rounded-lg border-gray-300 text-xs" title="Requested date" />
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500">Order discount</span>
                        <select v-model="cart.order_discount_type" class="rounded-lg border-gray-300 py-1 pr-7 text-xs">
                            <option value="">None</option>
                            <option value="percent">%</option>
                            <option value="fixed">Rs</option>
                        </select>
                        <input v-if="cart.order_discount_type" v-model="cart.order_discount_value" inputmode="decimal" class="w-20 rounded-lg border-gray-300 py-1 text-right text-xs" />
                    </div>

                    <div v-if="priced" class="space-y-0.5 text-xs text-gray-600">
                        <div v-if="priced.line_discount_total || priced.order_discount_cents" class="flex justify-between"><span>Discounts</span><span class="font-mono">−{{ formatMoney(priced.line_discount_total + priced.order_discount_cents) }}</span></div>
                        <div v-if="priced.tax_total" class="flex justify-between"><span>{{ priced.prices_include_tax ? 'VAT included' : 'VAT' }}</span><span class="font-mono">{{ formatMoney(priced.tax_total) }}</span></div>
                        <div v-if="priced.rounding_cents" class="flex justify-between"><span>Rounding</span><span class="font-mono">{{ formatMoney(priced.rounding_cents) }}</span></div>
                    </div>
                    <p v-if="priced?.needs_approval && !canOverride" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">Needs a manager: {{ priced.approval_reasons.join('; ') }}</p>

                    <div class="flex items-end justify-between pt-1">
                        <span class="text-sm font-semibold text-gray-700">Total</span>
                        <span class="font-mono text-2xl font-bold text-gray-900">{{ formatMoney(total) }}</span>
                    </div>

                    <div class="grid grid-cols-[auto_auto_1fr] gap-2 pt-1">
                        <button type="button" class="rounded-lg border border-gray-300 px-3 py-3 text-xs font-semibold text-gray-700 disabled:opacity-40" :disabled="!cart.lines.length" @click="holdCart">Hold</button>
                        <button type="button" class="rounded-lg border border-gray-300 px-3 py-3 text-xs font-semibold text-gray-700 disabled:opacity-40" :disabled="!cart.lines.length" @click="clearCart">Clear</button>
                        <button type="button" class="rounded-lg bg-indigo-600 py-3 text-base font-bold text-white shadow hover:bg-indigo-500 disabled:opacity-40" :disabled="!cart.lines.length || pricing" @click="openPay">
                            Pay {{ total ? formatMoney(total) : '' }}
                        </button>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Pay pad (full screen) -->
        <div v-if="showPay" class="fixed inset-0 z-50 flex flex-col bg-gray-900/95 text-white">
            <div class="flex items-center justify-between px-4 py-3 sm:px-6">
                <div>
                    <div class="text-xs tracking-wide text-gray-400 uppercase">{{ cart.kind === 'order' ? 'Order: payment / advance' : 'Cash & carry' }} · {{ location?.name }}</div>
                    <div class="font-mono text-3xl font-bold">{{ formatMoney(total) }}</div>
                </div>
                <button type="button" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/20" @click="showPay = false">Back to cart</button>
            </div>

            <div class="grid flex-1 gap-4 overflow-y-auto px-4 pb-4 sm:px-6 lg:grid-cols-[1fr_22rem]">
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="m in paymentMethods"
                            :key="m.id"
                            type="button"
                            class="rounded-xl px-3 py-4 text-base font-semibold"
                            :class="payMethodId === m.id ? 'bg-indigo-500 text-white ring-2 ring-white' : 'bg-white/10 hover:bg-white/20'"
                            @click="selectMethod(m)"
                        >
                            {{ m.name }}
                        </button>
                    </div>

                    <div class="rounded-xl bg-white/5 p-4">
                        <div class="text-xs text-gray-400 uppercase">{{ payMethod?.code === 'cash' ? 'Cash received' : 'Amount' }}</div>
                        <div class="font-mono text-4xl font-bold">{{ entry || '0' }}</div>
                        <div v-if="payMethod?.code === 'cash' && quickCash.length" class="mt-3 flex flex-wrap gap-2">
                            <button v-for="q in quickCash" :key="q" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 font-mono text-sm font-semibold hover:bg-emerald-500" @click="addPayment(q)">
                                {{ formatMoney(q) }}
                            </button>
                        </div>

                        <div v-if="payMethod?.requires_reference" class="mt-3">
                            <input v-model="payExtra.reference" placeholder="Reference / approval code" class="w-full rounded-lg border-0 bg-white/10 text-white placeholder:text-gray-400" />
                        </div>
                        <div v-if="payMethod?.code === 'cheque'" class="mt-3 grid grid-cols-2 gap-2">
                            <input v-model="payExtra.cheque_bank" placeholder="Bank" class="rounded-lg border-0 bg-white/10 text-white placeholder:text-gray-400" />
                            <input v-model="payExtra.cheque_branch" placeholder="Branch" class="rounded-lg border-0 bg-white/10 text-white placeholder:text-gray-400" />
                            <input v-model="payExtra.cheque_number" placeholder="Cheque no." class="rounded-lg border-0 bg-white/10 font-mono text-white placeholder:text-gray-400" />
                            <input v-model="payExtra.cheque_date" type="date" class="rounded-lg border-0 bg-white/10 text-white" />
                        </div>
                        <div v-if="payMethod?.code === 'credit_note'" class="mt-3">
                            <div class="flex gap-2">
                                <input v-model="payExtra.credit_note_number" placeholder="CN-…" class="flex-1 rounded-lg border-0 bg-white/10 font-mono text-white uppercase placeholder:text-gray-400" @keydown.enter.prevent="findCreditNote" />
                                <button type="button" class="rounded-lg bg-white/20 px-3 text-sm" @click="findCreditNote">Find</button>
                            </div>
                            <p v-if="payExtra.credit_note_error" class="mt-1 text-sm text-red-300">{{ payExtra.credit_note_error }}</p>
                            <p v-if="payExtra.credit_note" class="mt-1 text-sm text-emerald-300">{{ payExtra.credit_note.number }}: {{ formatMoney(payExtra.credit_note.remaining_cents) }} available<span v-if="payExtra.credit_note.customer_name"> · {{ payExtra.credit_note.customer_name }}</span></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-4 gap-2">
                        <button v-for="k in ['7', '8', '9', '⌫', '4', '5', '6', 'C', '1', '2', '3', '00', '0', '.']" :key="k" type="button" class="rounded-xl bg-white/10 py-4 text-2xl font-semibold hover:bg-white/20" @click="key(k)">{{ k }}</button>
                        <button type="button" class="col-span-2 rounded-xl bg-indigo-500 py-4 text-lg font-bold hover:bg-indigo-400" @click="addPayment()">Add payment</button>
                    </div>
                </div>

                <div class="flex flex-col rounded-xl bg-white p-4 text-gray-900">
                    <h3 class="text-sm font-bold">Payments</h3>
                    <p v-if="!payments.length" class="mt-2 text-sm text-gray-400">None yet. Split across as many methods as needed.</p>
                    <div v-for="(p, i) in payments" :key="i" class="mt-2 flex items-start justify-between border-b border-gray-100 pb-2 text-sm">
                        <div>
                            <div class="font-semibold">{{ p.method.name }}</div>
                            <div class="text-xs text-gray-500">
                                <span v-if="p.tendered">Given {{ formatMoney(p.tendered) }}</span>
                                <span v-if="p.reference">Ref {{ p.reference }}</span>
                                <span v-if="p.cheque">#{{ p.cheque.number }} {{ p.cheque.bank }}</span>
                                <span v-if="p.credit_note">{{ p.credit_note.number }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono font-semibold">{{ formatMoney(p.applied) }}</div>
                            <button type="button" class="text-xs text-red-600 hover:underline" @click="removePayment(i)">Remove</button>
                        </div>
                    </div>

                    <div class="mt-auto space-y-1 pt-4 text-sm">
                        <div class="flex justify-between"><span>Total</span><span class="font-mono">{{ formatMoney(total) }}</span></div>
                        <div class="flex justify-between"><span>Paid</span><span class="font-mono">{{ formatMoney(appliedTotal) }}</span></div>
                        <div class="flex justify-between text-lg font-bold" :class="remaining ? 'text-amber-700' : 'text-emerald-700'"><span>{{ cart.kind === 'order' && remaining ? 'Balance (later)' : 'Remaining' }}</span><span class="font-mono">{{ formatMoney(remaining) }}</span></div>
                        <div v-if="changeDue" class="flex justify-between rounded-lg bg-emerald-50 px-2 py-1 text-lg font-bold text-emerald-800"><span>Change</span><span class="font-mono">{{ formatMoney(changeDue) }}</span></div>
                    </div>

                    <p v-if="payError" class="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ payError }}</p>

                    <button
                        type="button"
                        class="mt-3 rounded-xl bg-emerald-600 py-4 text-lg font-bold text-white hover:bg-emerald-500 disabled:opacity-40"
                        :disabled="!canComplete || submitting"
                        @click="complete"
                    >
                        {{ submitting ? 'Saving…' : cart.kind === 'order' ? (appliedTotal ? 'Confirm order & take payment' : 'Confirm order (no payment)') : 'Complete sale' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Manager approval -->
        <div v-if="approval.open" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
            <form class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitApproval">
                <h2 class="text-base font-bold text-gray-900">Manager approval</h2>
                <p class="mt-1 text-sm text-gray-600">{{ approval.message }}</p>
                <select v-model="approval.approver_id" class="mt-4 w-full rounded-lg border-gray-300 text-sm" required>
                    <option value="">Select manager…</option>
                    <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <input v-model="approval.pin" type="password" inputmode="numeric" autocomplete="off" placeholder="Manager PIN" class="mt-2 w-full rounded-lg border-gray-300 text-center text-lg tracking-widest" required />
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" @click="approval.open = false">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white">Approve & continue</button>
                </div>
            </form>
        </div>

        <!-- Held carts -->
        <div v-if="showHeld" class="fixed inset-0 z-50 flex justify-end bg-black/40" @click.self="showHeld = false">
            <div class="h-full w-full max-w-sm overflow-y-auto bg-white p-4 shadow-xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold">Held carts</h2>
                    <button type="button" class="text-sm text-gray-500" @click="showHeld = false">Close</button>
                </div>
                <p v-if="!held.length" class="text-sm text-gray-500">No held carts at this location.</p>
                <div v-for="h in held" :key="h.id" class="mb-2 rounded-lg border border-gray-200 p-3">
                    <div class="font-medium">{{ h.label }}</div>
                    <div class="text-xs text-gray-500">{{ h.items }} item{{ h.items === 1 ? '' : 's' }} · {{ h.user }} · {{ new Date(h.created_at).toLocaleTimeString() }}</div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white" @click="resume(h)">Resume</button>
                        <button type="button" class="rounded-lg border px-3 py-1 text-xs text-red-600" @click="discard(h)">Discard</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sale done -->
        <div v-if="result" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-xl">
                <div class="text-sm font-semibold text-emerald-700">{{ result.status_label }}</div>
                <div class="mt-1 font-mono text-lg font-bold text-gray-900">{{ result.invoice_number }}</div>
                <div v-if="result.change_given" class="mt-4 rounded-xl bg-emerald-50 py-4">
                    <div class="text-sm text-emerald-800">Change due</div>
                    <div class="font-mono text-4xl font-bold text-emerald-800">{{ formatMoney(result.change_given) }}</div>
                </div>
                <div v-if="result.balance_due" class="mt-3 text-sm text-amber-700">Balance due later: {{ formatMoney(result.balance_due) }}</div>

                <div class="mt-5 grid grid-cols-2 gap-2 text-sm">
                    <button type="button" class="rounded-lg bg-slate-900 py-2.5 font-semibold text-white" @click="printReceipt(result)">Print receipt</button>
                    <a :href="result.invoice_url" target="_blank" class="rounded-lg border border-gray-300 py-2.5 font-semibold text-gray-700">A4 invoice</a>
                    <a :href="result.share.whatsapp" target="_blank" rel="noopener" class="rounded-lg bg-emerald-600 py-2.5 font-semibold text-white">WhatsApp</a>
                    <a v-if="result.share.sms" :href="result.share.sms" class="rounded-lg border border-gray-300 py-2.5 font-semibold text-gray-700">SMS</a>
                    <a v-else :href="result.show_url" class="rounded-lg border border-gray-300 py-2.5 font-semibold text-gray-700">Open order</a>
                </div>
                <button type="button" class="mt-4 w-full rounded-xl bg-indigo-600 py-3 text-base font-bold text-white hover:bg-indigo-500" @click="newSale">New sale</button>
            </div>
        </div>
    </AdminLayout>
</template>
