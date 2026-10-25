<script setup>
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    logs: Object,
    filters: Object,
    actions: Array,
    users: Array,
    allLocations: Boolean,
});

const filters = reactive({ action: props.filters.action ?? '', user: props.filters.user ?? '', date: props.filters.date ?? '', search: props.filters.search ?? '' });

let timer;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('audit.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});

const color = (log) => (log.is_override ? 'red' : log.action.startsWith('settings') ? 'amber' : log.action.startsWith('location') ? 'blue' : 'gray');
</script>

<template>
    <AdminLayout title="Audit log">
        <div class="space-y-4">
            <p class="text-sm text-gray-500">Who did what{{ allLocations ? ' at every location' : ' at this location' }}: staff and location changes, settings, PIN lockouts and refused approvals.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput v-model="filters.search" type="search" placeholder="Search summaries" />
                <SelectInput v-model="filters.action" aria-label="Action">
                    <option value="">All actions</option>
                    <option value="user.*">All staff actions</option>
                    <option value="location.*">All location actions</option>
                    <option v-for="action in actions" :key="action" :value="action">{{ action }}</option>
                </SelectInput>
                <SelectInput v-model="filters.user" aria-label="Staff">
                    <option value="">Everyone</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </SelectInput>
                <TextInput v-model="filters.date" type="date" aria-label="Date" />
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs">
                <ul class="divide-y divide-gray-100">
                    <li v-for="log in logs.data" :key="log.id" class="px-4 py-3">
                        <details>
                            <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span class="w-40 shrink-0 tabular-nums text-gray-500">{{ log.at }}</span>
                                <Badge :color="color(log)">{{ log.action }}</Badge>
                                <span v-if="allLocations && log.location" class="text-xs font-semibold text-gray-400">{{ log.location }}</span>
                                <span class="font-medium text-gray-900">{{ log.user }}</span>
                                <span class="min-w-0 flex-1 text-gray-700">{{ log.summary }}</span>
                            </summary>
                            <div class="mt-2 grid gap-3 pl-0 text-xs sm:grid-cols-2 sm:pl-44">
                                <div v-if="log.before"><p class="font-semibold text-gray-500">Before</p><pre class="overflow-x-auto rounded bg-gray-50 p-2">{{ JSON.stringify(log.before, null, 2) }}</pre></div>
                                <div v-if="log.after"><p class="font-semibold text-gray-500">After</p><pre class="overflow-x-auto rounded bg-gray-50 p-2">{{ JSON.stringify(log.after, null, 2) }}</pre></div>
                                <p class="text-gray-400 sm:col-span-2">{{ log.subject }} · IP {{ log.ip ?? 'n/a' }}</p>
                            </div>
                        </details>
                    </li>
                    <li v-if="logs.data.length === 0" class="px-4 py-12 text-center text-gray-500">Nothing logged for these filters.</li>
                </ul>
            </div>
            <Pagination :paginator="logs" />
        </div>
    </AdminLayout>
</template>
