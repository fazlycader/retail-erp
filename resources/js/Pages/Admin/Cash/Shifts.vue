<script setup>
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { formatMoney } from '@/lib/money';
import { clock } from '@/lib/time';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    shifts: Object,
    filters: Object,
    statuses: Array,
});

const money = (c) => (c === null || c === undefined ? '—' : formatMoney(c));
const color = { open: 'blue', closed: 'amber', approved: 'green' };
const filter = (status) => router.get(route('cash.shifts.index'), status ? { status } : {}, { preserveState: true, replace: true });
const needsApproval = computed(() => props.shifts.data.filter((s) => s.status === 'closed').length);
</script>

<template>
    <AdminLayout title="Shifts">
        <CashNav />
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button type="button" class="min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1 ring-inset" :class="!filters.status ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white ring-gray-300'" @click="filter(null)">All</button>
            <button v-for="s in statuses" :key="s.value" type="button" class="min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1 ring-inset" :class="filters.status === s.value ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white ring-gray-300'" @click="filter(s.value)">
                {{ s.label }}
            </button>
        </div>

        <p v-if="needsApproval" class="mb-4 rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-950">{{ needsApproval }} shift(s) closed with a difference over the limit and need your approval.</p>

        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
            <p v-if="!shifts.data.length" class="px-4 py-10 text-center text-sm text-gray-500">No shifts yet.</p>
            <ul class="divide-y divide-gray-100">
                <li v-for="s in shifts.data" :key="s.id">
                    <Link :href="route('cash.shifts.show', s.id)" class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ s.cashier }} · {{ s.register }} <Badge :color="color[s.status]">{{ s.status_label }}</Badge></p>
                            <p class="text-sm text-gray-500">{{ s.business_date }} · {{ clock(s.opened_at) }}<template v-if="s.closed_at"> – {{ clock(s.closed_at) }}</template><template v-if="s.force_close_reason"> · force-closed</template></p>
                        </div>
                        <div class="grid grid-cols-3 gap-4 text-right text-sm tabular-nums">
                            <div><p class="text-xs text-gray-500">Expected</p>{{ money(s.expected) }}</div>
                            <div><p class="text-xs text-gray-500">Counted</p>{{ money(s.counted) }}</div>
                            <div><p class="text-xs text-gray-500">Variance</p><span :class="(s.variance ?? 0) < 0 ? 'font-bold text-red-700' : (s.variance ?? 0) > 0 ? 'font-bold text-amber-700' : ''">{{ money(s.variance) }}</span></div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
        <Pagination class="mt-4" :paginator="shifts" />
    </AdminLayout>
</template>
