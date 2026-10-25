<script setup>
import ReportChart from '@/Components/Reports/ReportChart.vue';
import ReportTable from '@/Components/Reports/ReportTable.vue';
import Icon from '@/Components/Icon.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatValue } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    report: Object,
    range: Object,
    preset: String,
    presets: Array,
    today: String,
    error: String,
    data: Object,
    locations: Array,
    location: String,
    days: Number,
});

const currency = 'LKR';

const presetLabels = { today: 'Today', yesterday: 'Yesterday', this_week: 'This week', last_week: 'Last week', this_month: 'This month', last_month: 'Last month', last_30_days: 'Last 30 days', this_year: 'This year' };
const custom = reactive({ from: props.range.from, to: props.range.to });
const showCustom = ref(!props.preset);

const where = ref(props.location);
const deadDays = ref(props.days);
const extra = () => ({ location: where.value, ...(props.days ? { days: deadDays.value } : {}) });
const go = (params) => router.get(route('reports.show', props.report.key), { ...params, ...extra() }, { preserveScroll: true });
const reload = () => go(props.preset ? { preset: props.preset } : { from: props.range.from, to: props.range.to });
const choosePreset = (preset) => go({ preset });
const applyCustom = () => go({ from: custom.from, to: custom.to });

const query = computed(() => ({ ...(props.preset ? { preset: props.preset } : { from: props.range.from, to: props.range.to }), ...extra() }));
const exportUrl = (table, format) => route('reports.export', { report: props.report.key, ...query.value, table: table.key, format });

const exporting = ref(null);
const doExport = async (table, format) => {
    exporting.value = `${table.key}-${format}`;
    const response = await fetch(exportUrl(table, format), { headers: { Accept: 'application/json, */*' }, credentials: 'same-origin' });
    exporting.value = null;

    if (response.headers.get('content-type')?.includes('application/json')) {
        const data = await response.json();
        alert(data.message ?? 'The export could not be made.');

        return;
    }

    const blob = await response.blob();
    const name = /filename="?([^"]+)"?/.exec(response.headers.get('content-disposition') ?? '')?.[1] ?? `${props.report.key}.${format}`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    URL.revokeObjectURL(link.href);
};

const changeClass = (card) => (card.change === null || card.change === undefined ? '' : card.change >= 0 ? 'text-emerald-700' : 'text-red-700');
const rangeLabel = computed(() => (props.range.from === props.range.to ? props.range.from : `${props.range.from} → ${props.range.to}`));
</script>

<template>
    <AdminLayout :title="report.title">
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('reports.index')" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="All reports"><Icon name="back" class="size-5" /></Link>
                <h1 class="truncate text-lg font-semibold text-gray-900">{{ report.title }}</h1>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-2">
                <select v-model="where" class="min-h-10 rounded-lg border-gray-300 text-sm" aria-label="Location" @change="reload">
                    <option v-if="locations.length > 1" value="all">All my locations</option>
                    <option v-for="l in locations" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
                </select>
                <label v-if="days" class="flex items-center gap-1 text-sm text-gray-600">No sale in
                    <input v-model.number="deadDays" type="number" min="7" max="730" class="w-20 rounded-lg border-gray-300 text-sm" @change="reload" /> days
                </label>
                <span v-if="report.snapshot" class="text-sm text-gray-500">As of now (dates don't apply).</span>
            </div>

            <div v-if="!report.snapshot" class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="p in presets"
                    :key="p"
                    type="button"
                    class="min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1 ring-inset"
                    :class="preset === p ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-700 ring-gray-300'"
                    @click="choosePreset(p)"
                >
                    {{ presetLabels[p] }}
                </button>
                <button type="button" class="min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1 ring-inset" :class="!preset ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-700 ring-gray-300'" @click="showCustom = !showCustom">Custom</button>
            </div>
            <div class="flex justify-end">
                <a :href="route('reports.print', { report: report.key, ...query })" target="_blank" class="inline-flex min-h-10 items-center gap-1 rounded-lg px-3 text-sm font-semibold ring-1 ring-gray-300"><Icon name="printer" class="size-4" /> Print / PDF</a>
            </div>

            <form v-if="showCustom" class="flex flex-wrap items-end gap-2 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200" @submit.prevent="applyCustom">
                <label class="text-sm">From <input v-model="custom.from" type="date" :max="today" class="mt-1 block rounded-md border-gray-300 text-sm" /></label>
                <label class="text-sm">To <input v-model="custom.to" type="date" :max="today" class="mt-1 block rounded-md border-gray-300 text-sm" /></label>
                <button class="min-h-10 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white">Show</button>
                <span class="text-xs text-gray-500">Up to {{ report.max_days }} days. Dates are business days.</span>
            </form>

            <p v-if="!report.snapshot" class="text-sm text-gray-500">
                Business dates <strong class="text-gray-800">{{ rangeLabel }}</strong> ({{ range.days }} {{ range.days === 1 ? 'day' : 'days' }})
                <template v-if="data?.comparison"> · compared with {{ data.comparison.from === data.comparison.to ? data.comparison.from : `${data.comparison.from} → ${data.comparison.to}` }}</template>
            </p>

            <p v-if="error" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ error }}</p>

            <template v-if="data">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div v-for="card in data.cards" :key="card.key" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm text-gray-500">{{ card.label }}</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-gray-900">{{ formatValue(card.value, card.format, currency) }}</p>
                        <p v-if="card.change !== null && card.change !== undefined" class="text-xs font-semibold" :class="changeClass(card)">
                            {{ card.change >= 0 ? '▲' : '▼' }} {{ Math.abs(card.change) }}% <span class="font-normal text-gray-400">vs {{ formatValue(card.previous, card.format, currency) }}</span>
                        </p>
                    </div>
                </div>

                <p v-for="note in data.notes ?? []" :key="note" class="text-sm text-gray-500">{{ note }}</p>

                <div v-if="data.charts.length" class="grid gap-4 lg:grid-cols-2">
                    <template v-for="chart in data.charts" :key="chart.key">
                        <ReportChart :chart="chart" :currency="currency" :class="data.charts.length === 1 && 'lg:col-span-2'" />
                    </template>
                </div>

                <ReportTable v-for="table in data.tables" :key="table.key" :table="table" :currency="currency">
                    <template v-if="table.rows.length" #actions>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-gray-300 disabled:opacity-50" :disabled="exporting" @click="doExport(table, 'csv')">
                            <Icon name="download" class="size-3.5" /> CSV
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-gray-300 disabled:opacity-50" :disabled="exporting" @click="doExport(table, 'xlsx')">
                            <Icon name="download" class="size-3.5" /> Excel
                        </button>
                    </template>
                </ReportTable>
            </template>
        </div>
    </AdminLayout>
</template>
