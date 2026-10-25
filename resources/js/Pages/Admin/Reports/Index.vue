<script setup>
import Icon from '@/Components/Icon.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson } from '@/lib/http';
import { Link, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    groups: Array,
    today: String,
    exports: Array,
});

const page = usePage();
const exports = ref(props.exports);
let timer;

// Background exports finish in the scheduler's queue run: check every 10 s while any is pending.
onMounted(() => {
    timer = setInterval(async () => {
        if (!exports.value.some((e) => ['queued', 'running'].includes(e.status))) return;
        const { ok, data } = await getJson(route('reports.exports'));
        if (ok) exports.value = data.exports;
    }, 10000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AdminLayout title="Reports">
        <div class="space-y-6">
            <div v-if="page.props.auth?.can?.override" class="flex flex-wrap items-center gap-3 rounded-2xl bg-slate-900 p-4 text-white">
                <Icon name="chart" class="size-8 text-indigo-300" />
                <div class="flex-1">
                    <p class="font-semibold">End of day</p>
                    <p class="text-sm text-gray-300">Checklist, day report and lock the day for this location.</p>
                </div>
                <Link :href="route('cash.eod')" class="min-h-11 rounded-xl bg-indigo-500 px-5 py-2.5 font-bold text-white">Close the day</Link>
            </div>

            <section v-for="group in groups" :key="group.label">
                <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-500">{{ group.label }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="report in group.reports"
                        :key="report.key"
                        :href="route('reports.show', { report: report.key, preset: 'today' })"
                        class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-indigo-400"
                    >
                        <p class="font-semibold text-gray-900">{{ report.title }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ report.description }}</p>
                    </Link>
                </div>
            </section>

            <section v-if="exports.length">
                <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-500">Downloads</h2>
                <ul class="divide-y divide-gray-100 rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                    <li v-for="e in exports" :key="e.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <span class="flex-1 font-medium text-gray-900">{{ e.title }} <span class="text-gray-500">· {{ e.format }}</span></span>
                        <span v-if="e.status === 'ready'" class="text-gray-500">{{ e.rows }} rows</span>
                        <a v-if="e.url" :href="e.url" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 font-semibold text-white"><Icon name="download" class="size-4" /> Download</a>
                        <span v-else-if="e.status === 'failed'" class="text-red-700">Failed: {{ e.error }}</span>
                        <span v-else class="text-amber-700">Preparing…</span>
                    </li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
