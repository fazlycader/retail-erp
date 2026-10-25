<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { postJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    date: String,
    today: String,
    closed: Object,
    checklist: Object,
    report: Object,
    recent: Array,
});

const page = usePage();
const location = computed(() => page.props.location);
const t = computed(() => props.report.totals);
const accept = ref(false);
const busy = ref(false);
const message = ref(null);

const pick = (date) => router.get(route('cash.eod'), { date }, { preserveState: false });

const close = async () => {
    busy.value = true;
    const { ok, data } = await postJson(route('cash.eod.close'), { date: props.date, accept_warnings: accept.value });
    busy.value = false;
    message.value = { ok, text: data.message ?? 'Something went wrong.' };
    if (ok) router.reload();
};
</script>

<template>
    <AdminLayout title="End of day">
        <div class="mx-auto max-w-4xl space-y-5">
            <CashNav />

            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold text-gray-900">{{ location?.name }}: {{ date }}</h1>
                <input type="date" :value="date" :max="today" class="rounded-lg border-gray-300 text-sm" @change="pick($event.target.value)" />
                <a :href="route('cash.eod.print', { date, print: 1 })" target="_blank" class="ml-auto rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-300">Print report</a>
            </div>

            <p v-if="message" class="rounded-xl px-4 py-2 text-sm font-medium" :class="message.ok ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-800'">{{ message.text }}</p>

            <section v-if="closed" class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-950 ring-1 ring-emerald-200">
                Closed and locked by {{ closed.by }}. Changes to this day's records are admin-only and audited.
                <ul v-if="closed.warnings.length" class="mt-2 list-disc pl-5 text-emerald-900">
                    <li v-for="w in closed.warnings" :key="w.key">Accepted: {{ w.message }}</li>
                </ul>
            </section>

            <section v-else class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Checklist</h2>
                <p v-if="!checklist.blockers.length && !checklist.warnings.length" class="text-sm text-emerald-700">All clear.</p>
                <p v-for="b in checklist.blockers" :key="b.key" class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-800">
                    {{ b.message }} <Link :href="route('cash.shifts.index', { status: 'open' })" class="underline">Shifts</Link>
                </p>
                <p v-for="w in checklist.warnings" :key="w.key" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ w.message }}</p>
                <label v-if="checklist.warnings.length && !checklist.blockers.length" class="flex items-center gap-2 text-sm text-gray-700">
                    <input v-model="accept" type="checkbox" class="rounded border-gray-300" /> I've reviewed the warnings; close the day anyway.
                </label>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-bold text-white disabled:opacity-40"
                        :disabled="busy || checklist.blockers.length > 0 || (checklist.warnings.length > 0 && !accept)"
                        @click="close"
                    >
                        Close and lock {{ date }}
                    </button>
                </div>
            </section>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs text-gray-500">Invoices ({{ t.invoices }})</p><p class="font-mono text-lg font-bold">{{ formatMoney(t.sales) }}</p></div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs text-gray-500">Returns ({{ t.returns_count }})</p><p class="font-mono text-lg font-bold">{{ formatMoney(t.returns) }}</p></div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs text-gray-500">Net collected</p><p class="font-mono text-lg font-bold">{{ formatMoney(t.net_collected) }}</p></div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200"><p class="text-xs text-gray-500">Cash variance</p><p class="font-mono text-lg font-bold" :class="t.variance < 0 ? 'text-red-700' : ''">{{ formatMoney(t.variance) }}</p></div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="mb-2 font-semibold text-gray-900">Payments</h3>
                    <dl class="space-y-1 text-sm">
                        <div v-for="p in report.payments" :key="p.method" class="flex justify-between"><dt>{{ p.method }} ({{ p.count }})</dt><dd class="font-mono">{{ formatMoney(p.amount) }}</dd></div>
                        <div v-for="r in report.refunds" :key="`r${r.method}`" class="flex justify-between text-red-700"><dt>Refunds: {{ r.method }}</dt><dd class="font-mono">−{{ formatMoney(r.amount) }}</dd></div>
                        <p v-if="!report.payments.length" class="text-gray-500">No payments.</p>
                    </dl>
                </section>
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="mb-2 font-semibold text-gray-900">Shifts</h3>
                    <ul class="space-y-1 text-sm">
                        <li v-for="s in report.shifts" :key="s.id" class="flex justify-between">
                            <Link :href="route('cash.shifts.show', s.id)" class="text-indigo-700 hover:underline">{{ s.register }} · {{ s.cashier }}</Link>
                            <span class="font-mono" :class="(s.variance ?? 0) < 0 ? 'text-red-700' : ''">{{ s.variance === null ? 'open' : formatMoney(s.variance) }}</span>
                        </li>
                        <p v-if="!report.shifts.length" class="text-gray-500">No shifts.</p>
                    </ul>
                    <p v-if="report.deposits.count" class="mt-3 border-t border-gray-100 pt-2 text-sm text-gray-600">Bank deposits ({{ report.deposits.count }}): cash {{ formatMoney(report.deposits.cash) }}, cheques {{ formatMoney(report.deposits.cheques) }}</p>
                </section>
            </div>

            <p v-if="recent.length" class="text-sm text-gray-500">
                Recently closed:
                <button v-for="d in recent" :key="d" type="button" class="mr-2 text-indigo-700 hover:underline" @click="pick(d)">{{ d }}</button>
            </p>
        </div>
    </AdminLayout>
</template>
