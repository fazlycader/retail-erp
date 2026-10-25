<script setup>
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useStaffSync } from '@/Composables/useStaffSync';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { postJson } from '@/lib/http';
import WorkshopNav from '@/Pages/Admin/Workshop/Partials/WorkshopNav.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    location: { type: Object, default: null },
    workshops: { type: Array, default: () => [] },
    stages: { type: Array, default: () => [] },
    jobs: { type: Array, default: () => [] },
    version: { type: Number, default: null },
});

// Inertia wraps props in reactive proxies, which structuredClone cannot clone in browsers.
const cloneJobs = (jobs) => JSON.parse(JSON.stringify(jobs ?? []));
const localJobs = ref(cloneJobs(props.jobs));
watch(
    () => props.jobs,
    (next) => {
        localJobs.value = cloneJobs(next);
    },
);

const upsertOrRemoveJob = (job) => {
    const idx = localJobs.value.findIndex((j) => j.id === job.id);
    if (job.status === 'cancelled') {
        if (idx !== -1) localJobs.value.splice(idx, 1);
        return;
    }
    if (idx !== -1) {
        localJobs.value.splice(idx, 1, job);
    } else {
        localJobs.value.push(job);
    }
};

const { online } = useStaffSync({
    url: route('workshop.board.sync'),
    version: props.version,
    interval: 5,
    onChanges(data) {
        for (const job of data.jobs ?? []) {
            upsertOrRemoveJob(job);
        }
    },
});

const columns = computed(() => {
    return [
        { key: 'pending', stage_id: null, name: 'Not started', isReady: false },
        ...props.stages.map((s) => ({ key: `stage-${s.id}`, stage_id: s.id, name: s.name, isReady: false })),
        { key: 'ready', stage_id: null, name: 'Ready (finished)', isReady: true },
    ];
});

const jobsForColumn = (col) => {
    if (col.isReady) {
        return localJobs.value.filter((j) => j.status === 'ready');
    }
    if (col.stage_id === null) {
        return localJobs.value.filter((j) => j.status !== 'ready' && !j.stage_id);
    }
    return localJobs.value.filter((j) => j.status !== 'ready' && j.stage_id === col.stage_id);
};

const draggingJobId = ref(null);
const dragOverKey = ref(null);
const moveError = ref(null);

const onDragStart = (job) => {
    if (job.status === 'ready') return;
    draggingJobId.value = job.id;
};

const onDropColumn = async (col) => {
    dragOverKey.value = null;
    if (!draggingJobId.value || col.isReady) return;

    const job = localJobs.value.find((j) => j.id === draggingJobId.value);
    draggingJobId.value = null;
    if (!job || job.stage_id === col.stage_id) return;

    moveError.value = null;
    const { ok, data } = await postJson(route('workshop.board.move', job.id), {
        stage_id: col.stage_id,
    });

    if (!ok) {
        moveError.value = data?.message ?? 'Could not move job.';
        return;
    }

    if (data.job) {
        upsertOrRemoveJob(data.job);
    }
};

const switchToWorkshop = (locationId) => {
    router.post(route('location.switch'), { location: locationId });
};

const priorityTone = (p) => {
    if (p === 3) return 'red';
    if (p === 2) return 'amber';
    return 'gray';
};
</script>

<template>
    <AdminLayout title="Workshop Jobs Board">
        <WorkshopNav>
            <span v-if="location" class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                <span class="size-2 rounded-full" :class="online ? 'bg-emerald-500' : 'bg-amber-500'" />
                {{ location.name }} ({{ location.code }}) · Live
            </span>
        </WorkshopNav>

        <div v-if="moveError" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ moveError }}
        </div>

        <!-- Prompt to switch if current location is not a workshop -->
        <div v-if="!location" class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-xs">
            <Icon name="wrench" class="mx-auto size-10 text-brand-600" />
            <h2 class="mt-3 text-lg font-semibold text-gray-900">Switch to a Workshop Location</h2>
            <p class="mt-1 text-sm text-gray-600">
                Select a workshop location to view and move its production jobs on the Kanban board.
            </p>
            <div v-if="workshops.length" class="mt-4 flex flex-wrap justify-center gap-2">
                <PrimaryButton v-for="w in workshops" :key="w.id" @click="switchToWorkshop(w.id)">
                    Open {{ w.name }} ({{ w.code }})
                </PrimaryButton>
            </div>
            <p v-else class="mt-4 text-xs text-gray-500">
                No workshop location is configured yet. Create a location of type <strong>Workshop</strong> in Locations.
            </p>
        </div>

        <!-- Kanban columns -->
        <div v-else class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="col in columns"
                :key="col.key"
                class="flex w-72 shrink-0 flex-col rounded-xl border bg-gray-50/80 transition"
                :class="dragOverKey === col.key && !col.isReady ? 'border-brand-500 ring-2 ring-brand-200' : 'border-gray-200'"
                @dragover.prevent="!col.isReady && (dragOverKey = col.key)"
                @dragleave="dragOverKey === col.key && (dragOverKey = null)"
                @drop.prevent="onDropColumn(col)"
            >
                <div class="flex items-center justify-between border-b border-gray-200/80 bg-white px-3.5 py-2.5 rounded-t-xl">
                    <span class="text-sm font-semibold text-gray-900">{{ col.name }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">
                        {{ jobsForColumn(col).length }}
                    </span>
                </div>

                <div class="flex-1 space-y-2.5 p-2.5">
                    <div
                        v-for="job in jobsForColumn(col)"
                        :key="job.id"
                        :draggable="job.status !== 'ready'"
                        class="group rounded-xl border bg-white p-3 shadow-2xs transition hover:border-brand-300 hover:shadow-xs"
                        :class="job.overdue ? 'border-red-300 bg-red-50/20' : 'border-gray-200'"
                        @dragstart="onDragStart(job)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <Link :href="route('workshop.jobs.show', job.id)" class="font-mono text-xs font-bold text-brand-700 hover:underline">
                                {{ job.number }}
                            </Link>
                            <div class="flex items-center gap-1">
                                <Badge v-if="job.overdue" tone="red">Overdue</Badge>
                                <Badge v-if="job.priority > 1" :tone="priorityTone(job.priority)">{{ job.priority_label }}</Badge>
                            </div>
                        </div>

                        <Link :href="route('workshop.jobs.show', job.id)" class="mt-1 block text-sm font-semibold text-gray-900 group-hover:text-brand-700">
                            {{ job.title }}
                            <span v-if="job.pieces > 1" class="text-xs font-normal text-gray-500">× {{ job.pieces }}</span>
                        </Link>

                        <img
                            v-if="job.thumb"
                            :src="job.thumb"
                            :alt="job.title"
                            class="mt-2 h-24 w-full rounded-lg border border-gray-100 object-cover"
                        />

                        <div class="mt-2.5 flex flex-wrap items-center justify-between gap-1 text-xs text-gray-500">
                            <span v-if="job.customer" class="truncate font-medium text-gray-700">{{ job.customer }}</span>
                            <span v-if="job.order" class="font-mono text-[11px]">{{ job.order }}</span>
                        </div>

                        <div class="mt-1.5 flex items-center justify-between text-[11px] text-gray-500">
                            <span v-if="job.due_date" :class="job.overdue ? 'font-semibold text-red-700' : ''">
                                Due {{ job.due_date }}
                            </span>
                            <span v-else>No due date</span>
                            <span v-if="job.assignee" class="truncate text-gray-600">{{ job.assignee }}</span>
                        </div>
                    </div>

                    <p v-if="!jobsForColumn(col).length" class="py-6 text-center text-xs text-gray-400">
                        No jobs
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

