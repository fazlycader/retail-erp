<script setup>
import Badge from '@/Components/Badge.vue';
import DenominationCounter from '@/Components/Cash/DenominationCounter.vue';
import Modal from '@/Components/Modal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { postJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { clock } from '@/lib/time';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** A shift's Z-report (frozen at close) or live X-report, with manager actions. */
const props = defineProps({
    shift: Object,
    report: Object,
    denominations: Array,
    methods: Array,
    manager: Boolean,
});

const money = (c) => (c === null || c === undefined ? '—' : formatMoney(c));
const color = { open: 'blue', closed: 'amber', approved: 'green' };
const message = ref(null);
const busy = ref(false);

const call = async (url, body) => {
    busy.value = true;
    const { ok, data } = await postJson(url, body);
    busy.value = false;
    message.value = { ok, text: data.message ?? Object.values(data.errors ?? {}).flat()[0] };

    if (ok) router.reload();

    return ok;
};

const approveNote = ref('');
const approving = ref(false);
const approve = async () => (await call(route('cash.shifts.approve', props.shift.id), { note: approveNote.value })) && (approving.value = false);

const forcing = ref(false);
const forceCounts = ref({});
const forceReason = ref('');
const forceClose = async () => (await call(route('cash.shifts.force-close', props.shift.id), { counts: forceCounts.value, reason: forceReason.value })) && (forcing.value = false);

const reprint = () => window.open(route('cash.shifts.print', { shift: props.shift.id, print: 1 }), '_blank', 'width=420,height=700');
const cash = computed(() => props.report.cash);
const methodName = (id) => props.methods.find((m) => m.id === id)?.name ?? 'Terminal';
</script>

<template>
    <AdminLayout :title="`Shift #${shift.id}`">
        <div class="mx-auto max-w-4xl space-y-5">
            <CashNav />

            <p v-if="message" class="rounded-xl px-4 py-2 text-sm font-medium" :class="message.ok ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-800'">{{ message.text }}</p>

            <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="flex-1">
                    <p class="text-lg font-bold text-gray-900">{{ report.kind === 'z' ? 'Z-report' : 'X-report (shift still open)' }} <Badge :color="color[shift.status]">{{ shift.status_label }}</Badge></p>
                    <p class="text-sm text-gray-500">{{ shift.cashier }} · {{ shift.register }} · business day {{ shift.business_date }} · {{ clock(shift.opened_at) }}<template v-if="shift.closed_at"> – {{ clock(shift.closed_at) }}</template></p>
                    <p v-if="shift.force_close_reason" class="text-sm text-red-700">Force-closed: {{ shift.force_close_reason }}</p>
                    <p v-if="shift.approved_by" class="text-sm text-emerald-700">Approved by {{ shift.approved_by }}: {{ shift.approval_note }}</p>
                </div>
                <button type="button" class="min-h-10 rounded-lg px-3 text-sm font-semibold ring-1 ring-gray-300" @click="reprint">{{ report.kind === 'z' ? 'Print Z-report' : 'Print X-report' }}</button>
                <button v-if="manager && shift.status === 'closed'" type="button" class="min-h-10 rounded-lg bg-amber-500 px-4 text-sm font-bold text-white" @click="approving = true">Approve difference</button>
                <button v-if="manager && shift.status === 'open'" type="button" class="min-h-10 rounded-lg bg-red-600 px-4 text-sm font-bold text-white" @click="forcing = true">Force-close</button>
            </section>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="mb-2 font-semibold text-gray-900">Payments</h3>
                    <dl class="space-y-1 text-sm">
                        <div v-for="p in report.payments" :key="p.id" class="flex justify-between"><dt>{{ p.method }} ({{ p.count }})<span v-if="p.refunds" class="text-xs text-red-700"> −{{ money(p.refunds) }} refunded</span></dt><dd class="tabular-nums">{{ money(p.amount) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-100 pt-1 font-semibold"><dt>Collected</dt><dd class="tabular-nums">{{ money(report.collected) }}</dd></div>
                        <div class="flex justify-between"><dt>Refunds</dt><dd class="tabular-nums">−{{ money(report.refunds) }}</dd></div>
                        <div class="flex justify-between font-bold"><dt>Net</dt><dd class="tabular-nums">{{ money(report.net_collected) }}</dd></div>
                        <div class="flex justify-between text-gray-600"><dt>Orders paid</dt><dd class="tabular-nums">{{ report.orders_count }}</dd></div>
                        <div v-if="report.cheques.count" class="flex justify-between text-gray-600"><dt>Cheques ({{ report.cheques.count }})<span v-if="report.cheques.bounced" class="text-xs text-red-700"> {{ money(report.cheques.bounced) }} bounced</span></dt><dd class="tabular-nums">{{ money(report.cheques.amount) }}</dd></div>
                    </dl>
                    <div v-if="report.terminal.length" class="mt-3 border-t border-gray-100 pt-2 text-sm">
                        <p class="font-semibold text-gray-800">Terminal settlement</p>
                        <div v-for="t in report.terminal" :key="t.id" class="flex justify-between"><span>{{ methodName(t.id) }}: system {{ money(t.system) }}, terminal {{ money(t.terminal) }}</span><span :class="t.difference ? 'font-bold text-red-700' : 'text-emerald-700'">{{ t.difference ? money(t.difference) : 'matches' }}</span></div>
                    </div>
                </section>
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="mb-2 font-semibold text-gray-900">Cash drawer</h3>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Opening float</dt><dd class="tabular-nums">{{ money(cash.opening_float) }}</dd></div>
                        <div class="flex justify-between"><dt>+ Cash payments</dt><dd class="tabular-nums">{{ money(cash.cash_payments) }}</dd></div>
                        <div class="flex justify-between"><dt>+ Driver cash hand-overs</dt><dd class="tabular-nums">{{ money(cash.driver_cash || 0) }}</dd></div>
                        <div class="flex justify-between"><dt>+ Pay-ins</dt><dd class="tabular-nums">{{ money(cash.pay_ins) }}</dd></div>
                        <div class="flex justify-between"><dt>− Cash refunds</dt><dd class="tabular-nums">{{ money(cash.cash_refunds) }}</dd></div>
                        <div class="flex justify-between"><dt>− Pay-outs</dt><dd class="tabular-nums">{{ money(cash.pay_outs) }}</dd></div>
                        <div class="flex justify-between"><dt>− Cash drops</dt><dd class="tabular-nums">{{ money(cash.cash_drops) }}</dd></div>
                        <div v-if="cash.expected !== undefined" class="flex justify-between border-t border-gray-100 pt-1 font-bold"><dt>Expected</dt><dd class="tabular-nums">{{ money(cash.expected) }}</dd></div>
                        <template v-if="report.counted !== null">
                            <div class="flex justify-between font-bold"><dt>Counted</dt><dd class="tabular-nums">{{ money(report.counted) }}</dd></div>
                            <div class="flex justify-between text-lg font-black" :class="report.variance < 0 ? 'text-red-700' : report.variance > 0 ? 'text-amber-700' : 'text-emerald-700'">
                                <dt>{{ report.variance < 0 ? 'Short' : report.variance > 0 ? 'Over' : 'Difference' }}</dt><dd class="tabular-nums">{{ money(report.variance) }}</dd>
                            </div>
                        </template>
                        <p v-if="report.note" class="pt-1 text-gray-600">Note: {{ report.note }}</p>
                    </dl>
                </section>
            </div>

            <section v-if="report.movements.length" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <h3 class="mb-2 font-semibold text-gray-900">Cash movements</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    <li v-for="(m, i) in report.movements" :key="i" class="flex flex-wrap justify-between gap-2 py-1.5">
                        <span><strong>{{ m.label }}</strong> · {{ m.category ?? 'Other' }} · {{ m.reason }} <span class="text-xs text-gray-500">{{ clock(m.at) }} {{ m.by }}<template v-if="m.approved_by"> · ok {{ m.approved_by }}</template></span></span>
                        <span class="tabular-nums">{{ m.type === 'pay_in' ? '+' : '−' }}{{ money(m.amount) }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="report.closing_counts" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <h3 class="mb-2 font-semibold text-gray-900">Closing count</h3>
                <ul class="grid grid-cols-2 gap-1 text-sm sm:grid-cols-4">
                    <li v-for="(count, value) in report.closing_counts" :key="value">{{ (Number(value) / 100).toLocaleString() }} × {{ count }}</li>
                </ul>
            </section>
        </div>

        <Modal :show="approving" max-width="md" @close="approving = false">
            <form class="space-y-4 p-6" @submit.prevent="approve">
                <h2 class="text-lg font-semibold text-gray-900">Approve a difference of {{ money(shift.variance) }}</h2>
                <textarea v-model="approveNote" rows="3" required maxlength="500" class="block w-full rounded-xl border-gray-300" placeholder="What happened? (required)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="min-h-11 rounded-xl px-4 font-semibold text-gray-600" @click="approving = false">Cancel</button>
                    <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold text-white" :disabled="busy || !approveNote.trim()">Approve</button>
                </div>
            </form>
        </Modal>

        <Modal :show="forcing" max-width="2xl" @close="forcing = false">
            <form class="space-y-4 p-6" @submit.prevent="forceClose">
                <h2 class="text-lg font-semibold text-gray-900">Force-close {{ shift.cashier }}'s shift</h2>
                <p class="text-sm text-gray-500">Count the drawer now. The shift closes with your count and is approved by you.</p>
                <DenominationCounter v-model="forceCounts" :denominations="denominations"  />
                <input v-model="forceReason" type="text" required maxlength="255" class="block min-h-11 w-full rounded-xl border-gray-300" placeholder="Reason, e.g. cashier went home without closing" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="min-h-11 rounded-xl px-4 font-semibold text-gray-600" @click="forcing = false">Cancel</button>
                    <button class="min-h-11 rounded-xl bg-red-600 px-5 font-bold text-white" :disabled="busy || !forceReason.trim()">Force-close</button>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
