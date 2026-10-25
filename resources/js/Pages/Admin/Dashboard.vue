<script setup>
import Icon from '@/Components/Icon.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    businessDate: String,
    stats: Object,
    owner: { type: Object, default: null },
});

const change = (now, before) => (before ? Math.round(((now - before) * 1000) / Math.abs(before)) / 10 : null);
const statusColor = { confirmed: 'bg-blue-50 text-blue-800', in_production: 'bg-purple-50 text-purple-800', ready: 'bg-emerald-50 text-emerald-800', partially_fulfilled: 'bg-amber-50 text-amber-800' };
const alerts = computed(() => {
    const o = props.owner;
    if (!o) return [];
    return [
        { label: 'Awaiting delivery', value: o.deliveries.count, href: route('sales.index', { status: 'confirmed' }) },
        { label: 'Low stock items', value: o.lowStock, href: route('reports.show', { report: 'low_stock' }) },
        { label: 'Transfers in transit', value: o.transfersInTransit, href: route('transfers.index') },
        { label: 'Pending cheques', value: o.pendingCheques.count, sub: formatMoney(o.pendingCheques.amount), href: route('reports.show', { report: 'cheques', preset: 'this_month' }) },
        { label: 'Quotes to follow up', value: o.followUps.count, href: route('sales.quotations.index') },
        { label: 'Overdue workshop jobs', value: o.overdueJobs?.count ?? 0, href: route('workshop.board') },
        { label: 'Shift differences waiting', value: o.shiftsWaiting, href: route('cash.shifts.index', { status: 'closed' }) },
    ];
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const where = computed(() => (page.props.locationScope?.all ? 'All locations' : page.props.location?.name));
const modules = computed(() => (page.props.navigation ?? []).filter((item) => item.key !== 'dashboard'));

const formatDate = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-LK', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <AdminLayout title="Dashboard">
        <div class="space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs">
                <p class="text-sm text-gray-500">{{ formatDate(businessDate) }}</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Welcome, {{ user.name.split(' ')[0] }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    You're working at <span class="font-semibold text-gray-900">{{ where }}</span> as {{ user.role_label.toLowerCase() }}.
                </p>
                <dl class="mt-4 flex flex-wrap gap-6 text-sm">
                    <div v-if="stats.locations !== null">
                        <dt class="text-gray-500">Active locations</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ stats.locations }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Active staff here</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ stats.staff }}</dd>
                    </div>
                    <div v-if="stats.overdue_deliveries > 0">
                        <dt class="text-gray-500">Overdue deliveries</dt>
                        <dd class="text-lg font-semibold text-amber-700">{{ stats.overdue_deliveries }}</dd>
                    </div>
                </dl>
            </div>

            <template v-if="owner">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="col-span-2 rounded-xl border border-gray-200 bg-white p-4 shadow-xs lg:col-span-1">
                        <p class="text-sm text-gray-500">Net sales today</p>
                        <p class="text-2xl font-bold tabular-nums text-gray-900">{{ formatMoney(owner.totals.net_sales) }}</p>
                        <p v-if="change(owner.totals.net_sales, owner.totals.last_week) !== null" class="text-xs font-semibold" :class="owner.totals.net_sales >= owner.totals.last_week ? 'text-emerald-700' : 'text-red-700'">
                            {{ owner.totals.net_sales >= owner.totals.last_week ? '▲' : '▼' }} {{ Math.abs(change(owner.totals.net_sales, owner.totals.last_week)) }}%
                            <span class="font-normal text-gray-400">vs {{ formatMoney(owner.totals.last_week) }} same day last week</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <p class="text-sm text-gray-500">Collected</p>
                        <p class="text-xl font-bold tabular-nums">{{ formatMoney(owner.totals.collected) }}</p>
                        <p class="text-xs text-gray-500">{{ owner.totals.invoices }} invoices</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <p class="text-sm text-gray-500">Cash collected</p>
                        <p class="text-xl font-bold tabular-nums">{{ formatMoney(owner.totals.cash) }}</p>
                    </div>
                    <div v-if="owner.canSeeProfit" class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <p class="text-sm text-gray-500">Gross profit</p>
                        <p class="text-xl font-bold tabular-nums">{{ formatMoney(owner.totals.gross_profit) }}</p>
                        <p class="text-xs text-gray-500">last week {{ formatMoney(owner.totals.last_week_profit) }}</p>
                    </div>
                </div>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs">
                    <h3 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900">Sales by location</h3>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="l in owner.locations" :key="l.id" class="grid grid-cols-2 gap-2 px-4 py-3 text-sm sm:grid-cols-5">
                            <span class="col-span-2 font-medium text-gray-900 sm:col-span-1">{{ l.name }}</span>
                            <span class="tabular-nums"><span class="block text-xs text-gray-500">Net sales</span>{{ formatMoney(l.net_sales) }}
                                <span v-if="change(l.net_sales, l.last_week) !== null" class="text-xs" :class="l.net_sales >= l.last_week ? 'text-emerald-700' : 'text-red-700'">{{ change(l.net_sales, l.last_week) > 0 ? '+' : '' }}{{ change(l.net_sales, l.last_week) }}%</span>
                            </span>
                            <span class="tabular-nums"><span class="block text-xs text-gray-500">Collected</span>{{ formatMoney(l.collected) }}</span>
                            <span class="tabular-nums"><span class="block text-xs text-gray-500">Cash</span>{{ formatMoney(l.cash) }}</span>
                            <span v-if="owner.canSeeProfit" class="tabular-nums"><span class="block text-xs text-gray-500">Gross profit</span>{{ formatMoney(l.gross_profit) }}</span>
                        </li>
                    </ul>
                </section>

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <Link v-for="a in alerts" :key="a.label" :href="a.href" class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs hover:border-indigo-300">
                        <p class="text-sm text-gray-500">{{ a.label }}</p>
                        <p class="text-2xl font-bold tabular-nums" :class="a.value ? 'text-gray-900' : 'text-gray-300'">{{ a.value }}</p>
                        <p v-if="a.sub" class="text-xs text-gray-500">{{ a.sub }}</p>
                    </Link>
                </div>

                <div class="grid gap-3 lg:grid-cols-3">
                    <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <h3 class="mb-2 font-semibold text-gray-900">Open orders</h3>
                        <p v-if="!owner.openOrders.length" class="text-sm text-gray-500">None.</p>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="o in owner.openOrders" :key="o.status" class="rounded-lg px-2.5 py-1 text-sm font-medium" :class="statusColor[o.status] ?? 'bg-gray-100'">{{ o.label }}: {{ o.count }}</span>
                        </div>
                    </section>
                    <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <h3 class="mb-2 font-semibold text-gray-900">Next deliveries</h3>
                        <p v-if="!owner.deliveries.next.length" class="text-sm text-gray-500">None waiting.</p>
                        <Link v-for="d in owner.deliveries.next" :key="d.id" :href="route('sales.orders.show', d.id)" class="flex justify-between py-1 text-sm hover:text-indigo-700">
                            <span>{{ d.number }} · {{ d.customer }} <span class="text-xs text-gray-400">{{ d.location }}</span></span><span class="text-gray-500">{{ d.date ?? 'no date' }}</span>
                        </Link>
                    </section>
                    <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs">
                        <h3 class="mb-2 font-semibold text-gray-900">Quotes to follow up</h3>
                        <p v-if="!owner.followUps.next.length" class="text-sm text-gray-500">Nothing due.</p>
                        <Link v-for="q in owner.followUps.next" :key="q.id" :href="route('sales.quotations.show', q.id)" class="flex justify-between py-1 text-sm hover:text-indigo-700">
                            <span>{{ q.number }} · {{ q.customer }}</span><span class="text-gray-500">{{ q.date }}</span>
                        </Link>
                    </section>
                    <section v-if="owner.overdueJobs?.count" class="rounded-xl border border-red-200 bg-red-50/40 p-4 shadow-xs lg:col-span-3">
                        <h3 class="mb-2 font-semibold text-red-900">Overdue custom &amp; workshop jobs ({{ owner.overdueJobs.count }})</h3>
                        <div class="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                            <Link v-for="j in owner.overdueJobs.next" :key="j.id" :href="route('workshop.jobs.show', j.id)" class="flex justify-between rounded-lg bg-white px-3 py-1.5 text-sm shadow-2xs hover:text-indigo-700">
                                <span><span class="font-mono font-medium">{{ j.number }}</span> · {{ j.title }} <span v-if="j.customer" class="text-xs text-gray-500">({{ j.customer }})</span></span>
                                <span class="text-xs font-semibold text-red-700">Due {{ j.due_date }}</span>
                            </Link>
                        </div>
                    </section>
                </div>
            </template>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="item in modules"
                    :key="item.key"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-xs transition hover:border-brand-300 hover:shadow-sm"
                >
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <Icon :name="item.icon" class="size-5" />
                    </span>
                    <span class="font-semibold text-gray-900">{{ item.label }}</span>
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
