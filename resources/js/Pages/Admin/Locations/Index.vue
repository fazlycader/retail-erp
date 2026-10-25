<script setup>
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    locations: Array,
    canCreate: Boolean,
});

const toggleActive = (location) => {
    const action = location.is_active ? 'Deactivate' : 'Activate';

    if (confirm(`${action} ${location.name}?${location.is_active ? ' Staff can no longer switch to it.' : ''}`)) {
        router.patch(route('locations.toggle-active', location.id), {}, { preserveScroll: true });
    }
};
</script>

<template>
    <AdminLayout title="Locations">
        <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500">Warehouses and showrooms. Each keeps its own stock, documents and settings.</p>
                <Link
                    v-if="canCreate"
                    :href="route('locations.create')"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-brand-700"
                >
                    <Icon name="plus" class="size-4" />
                    Add location
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="location in locations"
                    :key="location.id"
                    class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-xs"
                    :class="!location.is_active && 'opacity-70'"
                >
                    <div class="flex items-start gap-3">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-bold text-brand-700">{{ location.code }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-gray-900">{{ location.name }}</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <Badge :color="location.type === 'warehouse' ? 'blue' : 'brand'">{{ location.type_label }}</Badge>
                                <Badge v-if="!location.is_active">Inactive</Badge>
                            </div>
                        </div>
                    </div>
                    <dl class="mt-4 space-y-1 text-sm text-gray-600">
                        <div v-if="location.address" class="flex gap-2"><dt class="sr-only">Address</dt><dd>{{ location.address }}</dd></div>
                        <div v-if="location.phone" class="flex gap-2"><dt class="sr-only">Phone</dt><dd>{{ location.phone }}</dd></div>
                        <div class="flex gap-2"><dt class="sr-only">Staff</dt><dd>{{ location.staff_count }} active staff</dd></div>
                    </dl>
                    <div class="mt-auto flex gap-4 pt-4 text-sm">
                        <Link v-if="location.can.update" :href="route('locations.edit', location.id)" class="font-semibold text-brand-600 hover:text-brand-700">Edit</Link>
                        <button
                            v-if="location.can.toggleActive"
                            type="button"
                            class="font-semibold"
                            :class="location.is_active ? 'text-gray-500 hover:text-red-600' : 'text-emerald-600 hover:text-emerald-700'"
                            @click="toggleActive(location)"
                        >
                            {{ location.is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
