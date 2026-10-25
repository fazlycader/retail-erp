<script setup>
import ManagerPin from '@/Components/Cash/ManagerPin.vue';
import DenominationCounter from '@/Components/Cash/DenominationCounter.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { postJson } from '@/lib/http';
import { decimalToCents, formatMoney } from '@/lib/money';
import { clock } from '@/lib/time';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The cashier's shift: open with a counted float, pay-ins / pay-outs / cash drops, X-report,
 * and a blind close (count first, then see the difference).
 */
const props = defineProps({
    shift: Object,
    report: Object,
    lastClosed: Object,
    registers: Array,
    deviceRegisterId: Number,
    denominations: Array,
    categories: Array,
    movementTypes: Array,
    methods: Array,
    approvers: Array,
    settings: Object,
    manager: Boolean,
});

const currency = 'LKR';
const money = (c) => formatMoney(c ?? 0, currency);
const message = ref(null);
const busy = ref(false);

const call = async (url, body) => {
    busy.value = true;
    const { ok, data } = await postJson(url, body);
    busy.value = false;
    message.value = { ok, text: data.message ?? (data.errors ? Object.values(data.errors).flat()[0] : 'Something went wrong.'), reason: data.reason };

    return { ok, data };
};

// ---- Open ------------------------------------------------------------------------------------
const registerId = ref(props.deviceRegisterId ?? props.registers.find((r) => !r.busy)?.id ?? null);
const openCounts = ref({});
const openTotal = ref('');
const byDenomination = ref(true);
const openFloat = computed(() => (byDenomination.value ? props.denominations.reduce((s, d) => s + d.value * (Number(openCounts.value[d.value]) || 0), 0) : decimalToCents(openTotal.value) ?? 0));

const openShift = async () => {
    const { ok } = await call(route('cash.open'), { register_id: registerId.value, counts: byDenomination.value ? openCounts.value : null, total: byDenomination.value ? null : openFloat.value });
    if (ok) router.reload();
};

// ---- Cash movements ----------------------------------------------------------------------------
const movement = ref(null); // {type, amount, category_id, reason}
const approverId = ref('');
const pin = ref('');
const startMovement = (type) => {
    movement.value = { type, amount: '', category_id: props.categories.find((c) => c.applies_to === type)?.id ?? '', reason: '' };
    approverId.value = props.approvers[0]?.id ?? '';
    pin.value = '';
    message.value = null;
};
const movementCents = computed(() => decimalToCents(movement.value?.amount) ?? 0);
const needsPayoutPin = computed(() => !props.manager && movement.value?.type === 'pay_out' && movementCents.value > props.settings.payout_threshold);
const categoriesFor = (type) => props.categories.filter((c) => c.applies_to === 'any' || c.applies_to === type);

const saveMovement = async () => {
    const { ok, data } = await call(route('cash.movement'), {
        type: movement.value.type,
        amount: movementCents.value,
        category_id: movement.value.category_id || null,
        reason: movement.value.reason,
        approver_id: needsPayoutPin.value ? approverId.value : null,
        approver_pin: needsPayoutPin.value ? pin.value : null,
    });

    if (ok) {
        movement.value = null;
        lastSlip.value = data.slip_url;
        router.reload({ only: ['report'] });
    } else {
        pin.value = '';
    }
};

const lastSlip = ref(null);
const printX = () => window.open(route('cash.shifts.print', { shift: props.shift.id, print: 1 }), '_blank', 'width=420,height=700');
const printSlip = () => window.open(`${lastSlip.value}?print=1`, '_blank', 'width=420,height=600');

// ---- Close (blind count) ----------------------------------------------------------------------
const closing = ref(false);
const closeCounts = ref({});
const terminal = ref({});
const note = ref('');
const result = ref(null);
const closeApprover = ref('');
const closePin = ref('');

const counted = computed(() => props.denominations.reduce((s, d) => s + d.value * (Number(closeCounts.value[d.value]) || 0), 0));

const closeShift = async () => {
    const { ok, data } = await call(route('cash.close'), {
        counts: closeCounts.value,
        terminal_totals: Object.fromEntries(Object.entries(terminal.value).map(([id, v]) => [id, decimalToCents(v)]).filter(([, v]) => v !== null)),
        note: note.value || null,
        approver_id: closePin.value ? closeApprover.value : null,
        approver_pin: closePin.value || null,
    });

    if (ok) {
        closing.value = false;
        result.value = data;
    } else {
        closePin.value = '';
    }
};

const startClose = () => {
    closeCounts.value = {};
    terminal.value = {};
    note.value = '';
    closeApprover.value = props.approvers[0]?.id ?? '';
    closePin.value = '';
    closing.value = true;
};

const variance = computed(() => result.value?.shift?.variance ?? 0);
const finished = () => router.reload();

const cash = computed(() => props.report?.cash ?? {});
</script>

<template>
    <AdminLayout title="Shift & cash">
        <div class="mx-auto max-w-4xl space-y-5">
            <CashNav />
            <p v-if="lastSlip" class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-2 text-sm">
                Have the slip signed for the record.
                <button type="button" class="font-semibold text-indigo-700" @click="printSlip">Print slip</button>
            </p>
            <p v-if="message" class="rounded-xl px-4 py-2 text-sm font-medium" :class="message.ok ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-800'" role="status">{{ message.text }}</p>

            <!-- Closed just now: show the variance -->
            <section v-if="result" class="space-y-4 rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-200">
                <p class="text-sm font-bold uppercase tracking-wide text-gray-500">Shift closed</p>
                <div class="grid grid-cols-3 gap-3">
                    <div><p class="text-sm text-gray-500">Expected</p><p class="text-xl font-bold tabular-nums">{{ money(result.shift.expected) }}</p></div>
                    <div><p class="text-sm text-gray-500">Counted</p><p class="text-xl font-bold tabular-nums">{{ money(result.shift.counted) }}</p></div>
                    <div>
                        <p class="text-sm text-gray-500">{{ variance < 0 ? 'Short' : variance > 0 ? 'Over' : 'Difference' }}</p>
                        <p class="text-xl font-black tabular-nums" :class="variance < 0 ? 'text-red-700' : variance > 0 ? 'text-amber-700' : 'text-emerald-700'">{{ money(variance) }}</p>
                    </div>
                </div>
                <p v-if="result.shift.status === 'closed'" class="rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-900">The difference is more than {{ money(settings.variance_threshold) }}, so a manager must approve it (Shifts page).</p>
                <p class="text-sm text-gray-500">The Z-report is saved and can be printed any time.</p>
                <div class="flex justify-center gap-2">
                    <Link :href="route('cash.shifts.show', result.shift.id)" class="rounded-xl px-4 py-2.5 font-semibold ring-1 ring-gray-300">View Z-report</Link>
                    <a :href="route('cash.shifts.print', { shift: result.shift.id, print: 1 })" target="_blank" class="rounded-xl px-4 py-2.5 font-semibold ring-1 ring-gray-300">Print Z-report</a>
                    <button type="button" class="rounded-xl bg-gray-900 px-6 py-2.5 font-bold text-white" @click="finished">Done</button>
                </div>
            </section>

            <!-- No shift: open one -->
            <template v-else-if="!shift">
                <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Open your shift</h2>
                        <p class="text-sm text-gray-500">Count the cash in the drawer (the float) before you start.<template v-if="settings.require_shift"> Payments can only be taken during a shift.</template></p>
                    </div>

                    <div>
                        <p class="mb-1 text-sm font-medium text-gray-700">Register</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="r in registers"
                                :key="r.id"
                                type="button"
                                class="min-h-11 rounded-xl px-4 text-sm font-semibold ring-1 ring-inset disabled:opacity-50"
                                :class="registerId === r.id ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-800 ring-gray-300'"
                                :disabled="!!r.busy"
                                @click="registerId = r.id"
                            >
                                {{ r.name }}<span v-if="r.busy" class="block text-xs font-normal">Open: {{ r.busy }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1 sm:w-80">
                        <button type="button" class="min-h-9 rounded-lg text-sm font-semibold" :class="byDenomination ? 'bg-white shadow' : 'text-gray-600'" @click="byDenomination = true">Count notes &amp; coins</button>
                        <button type="button" class="min-h-9 rounded-lg text-sm font-semibold" :class="!byDenomination ? 'bg-white shadow' : 'text-gray-600'" @click="byDenomination = false">Enter a total</button>
                    </div>

                    <DenominationCounter v-if="byDenomination" v-model="openCounts" :denominations="denominations" :currency="currency" />
                    <input v-else v-model="openTotal" type="text" inputmode="decimal" class="block min-h-12 w-full rounded-xl border-gray-300 text-lg sm:w-60" placeholder="Float, e.g. 10000" aria-label="Opening float" />

                    <button type="button" class="min-h-14 w-full rounded-2xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-50" :disabled="busy || !registerId" @click="openShift">
                        Open shift with {{ money(openFloat) }}
                    </button>
                </section>

                <p v-if="lastClosed" class="text-center text-sm text-gray-500">
                    Last shift: {{ lastClosed.business_date }} on {{ lastClosed.register }} ·
                    <Link :href="route('cash.shifts.show', lastClosed.id)" class="font-semibold text-brand-700">Z-report</Link>
                </p>
            </template>

            <!-- Open shift -->
            <template v-else>
                <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-gray-900 p-4 text-white">
                    <Icon name="cash" class="size-8 text-emerald-400" />
                    <div class="flex-1">
                        <p class="font-semibold">Shift open on {{ shift.register }}</p>
                        <p class="text-sm text-gray-300">{{ shift.location }} · since {{ clock(shift.opened_at) }} · business day {{ shift.business_date }} · float {{ money(shift.opening_float) }}</p>
                        <p v-if="shift.elsewhere" class="text-sm font-semibold text-amber-300">This shift is at {{ shift.location }}, not the location you're working at now. Payments here won't go on it.</p>
                    </div>
                    <button type="button" class="min-h-11 rounded-xl bg-white/10 px-4 font-semibold" :disabled="busy" @click="printX">Print X-report</button>
                    <button type="button" class="min-h-11 rounded-xl bg-red-500 px-5 font-bold" @click="startClose">Close shift</button>
                </section>

                <div class="grid grid-cols-3 gap-2">
                    <button v-for="t in movementTypes" :key="t.value" type="button" class="min-h-14 rounded-2xl bg-white font-bold shadow-sm ring-1 ring-gray-200" @click="startMovement(t.value)">{{ t.label }}</button>
                </div>

                <!-- X-report -->
                <section class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <h3 class="mb-2 font-semibold text-gray-900">Payments this shift</h3>
                        <dl class="space-y-1 text-sm">
                            <div v-for="p in report.payments" :key="p.id" class="flex justify-between"><dt>{{ p.method }} ({{ p.count }})</dt><dd class="tabular-nums">{{ money(p.amount) }}</dd></div>
                            <p v-if="!report.payments.length" class="text-gray-500">No payments yet.</p>
                            <div class="flex justify-between border-t border-gray-100 pt-1 font-semibold"><dt>Collected</dt><dd class="tabular-nums">{{ money(report.collected) }}</dd></div>
                            <div class="flex justify-between text-red-700"><dt>Refunds</dt><dd class="tabular-nums">−{{ money(report.refunds) }}</dd></div>
                            <div class="flex justify-between text-gray-600"><dt>Orders paid</dt><dd class="tabular-nums">{{ report.orders_count }}</dd></div>
                            <div v-if="report.cheques.count" class="flex justify-between text-gray-600"><dt>Cheques ({{ report.cheques.count }})</dt><dd class="tabular-nums">{{ money(report.cheques.amount) }}</dd></div>
                        </dl>
                    </div>
                    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <h3 class="mb-2 font-semibold text-gray-900">Cash drawer</h3>
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between"><dt>Opening float</dt><dd class="tabular-nums">{{ money(cash.opening_float) }}</dd></div>
                            <div class="flex justify-between"><dt>+ Cash payments</dt><dd class="tabular-nums">{{ money(cash.cash_payments) }}</dd></div>
                            <div class="flex justify-between"><dt>+ Driver cash hand-overs</dt><dd class="tabular-nums">{{ money(cash.driver_cash || 0) }}</dd></div>
                            <div class="flex justify-between"><dt>+ Pay-ins</dt><dd class="tabular-nums">{{ money(cash.pay_ins) }}</dd></div>
                            <div class="flex justify-between"><dt>− Cash refunds</dt><dd class="tabular-nums">{{ money(cash.cash_refunds) }}</dd></div>
                            <div class="flex justify-between"><dt>− Pay-outs</dt><dd class="tabular-nums">{{ money(cash.pay_outs) }}</dd></div>
                            <div class="flex justify-between"><dt>− Cash drops</dt><dd class="tabular-nums">{{ money(cash.cash_drops) }}</dd></div>
                            <div v-if="cash.expected !== undefined" class="flex justify-between border-t border-gray-100 pt-1 font-bold"><dt>Expected in drawer</dt><dd class="tabular-nums">{{ money(cash.expected) }}</dd></div>
                            <p v-else class="border-t border-gray-100 pt-1 text-xs text-gray-500">Blind close is on: you'll see the expected amount after you count at close.</p>
                            <p class="pt-1 text-xs text-gray-500">Cash received {{ money(cash.cash_received) }}, change given {{ money(cash.change_given) }}.</p>
                        </dl>
                    </div>
                </section>

                <section v-if="report.movements.length" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="mb-2 font-semibold text-gray-900">Cash movements</h3>
                    <ul class="divide-y divide-gray-100 text-sm">
                        <li v-for="(m, i) in report.movements" :key="i" class="flex flex-wrap justify-between gap-2 py-1.5">
                            <span><strong>{{ m.label }}</strong> · {{ m.category ?? 'Other' }} · {{ m.reason }} <span class="text-xs text-gray-500">{{ clock(m.at) }} {{ m.by }}<template v-if="m.approved_by"> · ok {{ m.approved_by }}</template></span></span>
                            <span class="tabular-nums" :class="m.type === 'pay_in' ? 'text-emerald-700' : 'text-red-700'">{{ m.type === 'pay_in' ? '+' : '−' }}{{ money(m.amount) }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>

        <!-- Movement dialog -->
        <Modal :show="movement !== null" max-width="md" @close="movement = null">
            <form v-if="movement" class="space-y-4 p-6" @submit.prevent="saveMovement">
                <h2 class="text-lg font-semibold text-gray-900">{{ movementTypes.find((t) => t.value === movement.type)?.label }}</h2>
                <input v-model="movement.amount" type="text" inputmode="decimal" required class="block min-h-12 w-full rounded-xl border-gray-300 text-2xl font-bold" placeholder="Amount" aria-label="Amount" />
                <select v-model="movement.category_id" class="block w-full rounded-lg border-gray-300" aria-label="Category">
                    <option value="">Other</option>
                    <option v-for="c in categoriesFor(movement.type)" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <input v-model="movement.reason" type="text" required maxlength="255" class="block min-h-11 w-full rounded-xl border-gray-300" placeholder="Reason, e.g. lorry hire for a delivery" aria-label="Reason" />
                <ManagerPin v-if="needsPayoutPin" v-model:approver-id="approverId" v-model:pin="pin" :approvers="approvers" :hint="`Pay-outs over ${money(settings.payout_threshold)} need a manager.`" />
                <p class="text-xs text-gray-500">You can print a slip for signing afterwards.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="min-h-11 rounded-xl px-4 font-semibold text-gray-600" @click="movement = null">Cancel</button>
                    <button class="min-h-11 rounded-xl bg-gray-900 px-5 font-bold text-white disabled:opacity-50" :disabled="busy || !movementCents || !movement.reason.trim() || (needsPayoutPin && pin.length < 4)">Record {{ money(movementCents) }}</button>
                </div>
            </form>
        </Modal>

        <!-- Close dialog -->
        <Modal :show="closing" max-width="2xl" @close="closing = false">
            <form class="space-y-4 p-6" @submit.prevent="closeShift">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Close shift: count the drawer</h2>
                    <p class="text-sm text-gray-500">{{ settings.blind_close ? 'Count every note and coin. The expected amount is shown after you close.' : `Expected in drawer: ${money(cash.expected)}.` }}</p>
                </div>
                <DenominationCounter v-model="closeCounts" :denominations="denominations" :currency="currency" />

                <div v-if="methods.length" class="rounded-xl bg-gray-50 p-3">
                    <p class="text-sm font-semibold text-gray-800">Card / QR terminal totals (optional)</p>
                    <p class="text-xs text-gray-500">From the bank terminal's settlement slip, to compare with the system.</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        <label v-for="m in methods" :key="m.id" class="text-sm">{{ m.name }}<input v-model="terminal[m.id]" type="text" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" placeholder="0.00" /></label>
                    </div>
                </div>

                <textarea v-model="note" rows="2" maxlength="500" class="block w-full rounded-xl border-gray-300 text-sm" placeholder="Note (required if the difference needs approval)" />
                <details v-if="!manager" class="rounded-xl bg-amber-50 p-3 text-sm">
                    <summary class="cursor-pointer font-semibold text-amber-950">Manager here to approve a difference now? (optional)</summary>
                    <ManagerPin v-model:approver-id="closeApprover" v-model:pin="closePin" :approvers="approvers" class="mt-2" />
                </details>
                <div class="flex justify-end gap-2">
                    <button type="button" class="min-h-11 rounded-xl px-4 font-semibold text-gray-600" @click="closing = false">Cancel</button>
                    <button class="min-h-12 rounded-xl bg-red-600 px-6 font-bold text-white disabled:opacity-50" :disabled="busy">Close shift with {{ money(counted) }}</button>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
