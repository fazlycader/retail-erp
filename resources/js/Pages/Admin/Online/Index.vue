<script setup>
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** WooCommerce sync status and the things staff must act on. */
const props = defineProps({
    status: Object,
    failing: Array,
    log: Array,
    webhooks: Array,
    attention: Array,
    unmatched: Object,
    unlinked: Array,
    enquiries: Array,
    variants: Array,
    settings: Object, // null for managers
    locations: Array,
    plugin: Object,
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');
const when = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Colombo' }) : 'never');

const settingsForm = props.settings ? useForm({ ...props.settings }) : null;
const saveSettings = () => settingsForm.put(route('online.settings'), { preserveScroll: true });
const toggleIn = (list, value) => (list.includes(value) ? list.splice(list.indexOf(value), 1) : list.push(value));

const busy = ref(false);
const run = (name) => {
    busy.value = true;
    router.post(route(name), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

const buffers = ref(Object.fromEntries(props.variants.map((v) => [v.id, v.buffer])));
const saveBuffer = (v) => router.put(route('online.buffer', v.id), { buffer: buffers.value[v.id] }, { preserveScroll: true });
const variantFilter = ref('');
const shownVariants = computed(() => props.variants.filter((v) => !variantFilter.value || `${v.sku} ${v.name}`.toLowerCase().includes(variantFilter.value.toLowerCase())).slice(0, 100));
</script>

<template>
    <AdminLayout title="Online store">
        <div class="mx-auto max-w-6xl space-y-5">
            <section class="flex flex-wrap items-center gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex-1">
                    <p class="font-semibold text-gray-900">
                        WooCommerce
                        <span class="ml-2 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="status.configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">{{ status.configured ? 'Connected' : 'Not set up' }}</span>
                    </p>
                    <p class="text-sm text-gray-500">
                        Last push {{ when(status.last_push_at) }} · last reconcile {{ when(status.last_reconcile_at) }} ·
                        {{ status.linked_products }} of {{ status.online_products }} online products linked ·
                        queue {{ status.queue }}<template v-if="status.failing"> ({{ status.failing }} retrying)</template>
                    </p>
                    <p v-if="!status.configured && isAdmin" class="text-sm"><Link :href="route('integrations.index')" class="font-semibold text-indigo-700">Enter the WooCommerce keys in Settings → Integrations</Link></p>
                </div>
                <button type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !status.configured" @click="run('online.push')">Push all</button>
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold ring-1 ring-gray-300 disabled:opacity-50" :disabled="busy || !status.configured" @click="run('online.reconcile')">Reconcile now</button>
                <a :href="route('online.plugin')" class="rounded-lg px-4 py-2 text-sm font-semibold ring-1 ring-gray-300">WordPress plugin {{ plugin.version }}</a>
            </section>

            <section v-if="attention.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-amber-300">
                <h2 class="mb-2 font-semibold text-gray-900">Web orders needing attention ({{ attention.length }})</h2>
                <ul class="divide-y divide-gray-100">
                    <li v-for="o in attention" :key="o.id" class="flex flex-wrap items-start gap-3 py-2 text-sm">
                        <div class="min-w-0 flex-1">
                            <Link :href="route('sales.orders.show', o.id)" class="font-semibold text-indigo-700">{{ o.number }}</Link>
                            <span class="text-gray-500"> · Woo #{{ o.woo_number }} ({{ o.woo_status }}) · {{ o.customer ?? 'no customer' }} · {{ formatMoney(o.grand_total) }}</span>
                            <p class="whitespace-pre-line text-amber-900">{{ o.reason }}</p>
                        </div>
                        <button type="button" class="rounded-lg px-3 py-1 text-xs font-semibold ring-1 ring-gray-300" @click="router.post(route('online.resolve', o.id), {}, { preserveScroll: true })">Mark handled</button>
                    </li>
                </ul>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="mb-2 font-semibold text-gray-900">Last errors</h2>
                    <p v-if="!failing.length && !webhooks.length && !log.length" class="text-sm text-gray-500">None.</p>
                    <ul class="space-y-2 text-sm">
                        <li v-for="i in failing" :key="`q${i.id}`" class="flex gap-2">
                            <span class="flex-1"><strong>{{ i.item }}</strong> · try {{ i.attempts }} · {{ i.error }} <span class="text-xs text-gray-400">next {{ when(i.next_at) }}</span></span>
                            <button type="button" class="text-xs font-semibold text-indigo-700" @click="router.post(route('online.retry-item', i.id), {}, { preserveScroll: true })">Retry now</button>
                        </li>
                        <li v-for="w in webhooks" :key="`w${w.id}`" class="flex gap-2">
                            <span class="flex-1"><strong>Webhook {{ w.topic }}</strong> (order {{ w.woo_order_id }}) · {{ w.error }}</span>
                            <button type="button" class="text-xs font-semibold text-indigo-700" @click="router.post(route('online.retry-webhook', w.id), {}, { preserveScroll: true })">Retry</button>
                        </li>
                        <li v-for="(l, n) in log" :key="`l${n}`" :class="l.level === 'error' ? 'text-red-800' : 'text-amber-800'">{{ when(l.at) }} · {{ l.action }} · {{ l.message }}</li>
                    </ul>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h2 class="mb-2 font-semibold text-gray-900">Unmatched SKUs</h2>
                    <p class="text-sm text-gray-500">Online here but not yet in WooCommerce (the next push creates them): {{ unlinked.length ? unlinked.join(', ') : 'none' }}.</p>
                    <template v-if="unmatched">
                        <p class="mt-2 text-sm text-gray-500">Last <code>woo:link</code>{{ unmatched.dry_run ? ' (dry run)' : '' }} {{ when(unmatched.at) }}: {{ unmatched.linked.length }} linked.</p>
                        <p v-if="unmatched.woo_only.length" class="mt-1 text-sm">In WooCommerce only: {{ unmatched.woo_only.slice(0, 30).join(', ') }}</p>
                        <p v-for="c in unmatched.conflicts.slice(0, 10)" :key="c" class="mt-1 text-sm text-red-800">{{ c }}</p>
                    </template>
                </section>
            </div>

            <section v-if="enquiries.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="mb-2 font-semibold text-gray-900">Showroom visit requests</h2>
                <ul class="divide-y divide-gray-100 text-sm">
                    <li v-for="e in enquiries" :key="e.id" class="flex flex-wrap items-center gap-3 py-2">
                        <span class="flex-1">
                            <strong>{{ e.name }}</strong> {{ e.phone }} <span v-if="e.email" class="text-gray-500">{{ e.email }}</span> · {{ e.sku ?? 'no item' }} at {{ e.location ?? 'any showroom' }}<template v-if="e.preferred_date"> on {{ e.preferred_date }}</template>
                            <span class="block text-xs text-gray-500">{{ when(e.at) }}<template v-if="e.message"> · {{ e.message }}</template></span>
                        </span>
                        <select :value="e.status" class="rounded-lg border-gray-300 text-xs" @change="router.put(route('online.enquiry', e.id), { status: $event.target.value }, { preserveScroll: true })">
                            <option value="new">New</option><option value="contacted">Contacted</option><option value="closed">Closed</option>
                        </select>
                    </li>
                </ul>
            </section>

            <section v-if="settingsForm" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Settings</h2>
                <form class="mt-3 space-y-4" @submit.prevent="saveSettings">
                    <div>
                        <p class="text-sm font-medium text-gray-900">Stock sold online comes from</p>
                        <div class="mt-1 flex flex-wrap gap-3">
                            <label v-for="l in locations" :key="l.id" class="flex items-center gap-1.5 text-sm"><input type="checkbox" :checked="settingsForm.online_stock_locations.includes(l.id)" class="rounded border-gray-300" @change="toggleIn(settingsForm.online_stock_locations, l.id)" /> {{ l.name }}</label>
                        </div>
                        <p class="text-xs text-gray-500">Online stock = available (on hand − reserved) at these locations − the item's buffer, never below 0.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-gray-900">Web orders are fulfilled by
                            <select v-model="settingsForm.web_fulfilment_location_id" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option :value="null">Choose…</option>
                                <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-gray-900">Reserve stock at
                            <select v-model="settingsForm.web_reserve_at" class="mt-1 block w-full rounded-lg border-gray-300">
                                <option value="fulfilment">The fulfilment location</option>
                                <option value="stock">An online location that has it</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-gray-900">Made-to-order lead time (shown on the shop)
                            <input v-model="settingsForm.woo_backorder_text" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300" placeholder="Made to order: 3–4 weeks" />
                        </label>
                        <div class="text-sm font-medium text-gray-900">WooCommerce statuses that mean "paid"
                            <div class="mt-1 flex flex-wrap gap-3 font-normal">
                                <label v-for="s in ['pending', 'on-hold', 'processing', 'completed']" :key="s" class="flex items-center gap-1.5"><input type="checkbox" :checked="settingsForm.woo_paid_statuses.includes(s)" class="rounded border-gray-300" @change="toggleIn(settingsForm.woo_paid_statuses, s)" /> {{ s }}</label>
                            </div>
                        </div>
                    </div>
                    <label class="flex items-center justify-between gap-4">
                        <span><span class="block text-sm font-medium text-gray-900">Send prices to WooCommerce</span><span class="text-sm text-gray-500">The online price (or the shop price when blank). Off: prices are kept in WooCommerce.</span></span>
                        <Toggle v-model="settingsForm.woo_sync_prices" />
                    </label>
                    <p v-if="Object.keys(settingsForm.errors).length" class="text-sm text-red-600">{{ Object.values(settingsForm.errors)[0] }}</p>
                    <div class="flex justify-end"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="settingsForm.processing">Save settings</button></div>
                </form>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <h2 class="flex-1 font-semibold text-gray-900">Online stock</h2>
                    <input v-model="variantFilter" type="search" placeholder="Filter by SKU or name" class="rounded-lg border-gray-300 text-sm" />
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500"><tr><th class="py-2">SKU</th><th>Item</th><th class="text-right">Online</th><th class="text-right">Buffer</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="v in shownVariants" :key="v.id">
                            <td class="py-1.5 font-mono text-xs">{{ v.sku }}<span v-if="!v.linked" class="ml-1 text-amber-700">(not linked)</span></td>
                            <td>{{ v.name }}</td>
                            <td class="text-right font-mono">{{ v.made_to_order ? 'backorder' : v.online }}</td>
                            <td class="text-right"><input v-model="buffers[v.id]" inputmode="decimal" class="w-20 rounded border-gray-300 text-right text-xs" @change="saveBuffer(v)" /></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">The buffer is held back from the website (e.g. display pieces). Changes are sent with the next push.</p>
            </section>
        </div>
    </AdminLayout>
</template>
