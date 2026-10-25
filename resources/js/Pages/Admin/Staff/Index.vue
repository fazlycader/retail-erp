<script setup>
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    staff: Object,
    filters: Object,
    roles: Array,
});

const filters = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});

const page = usePage();
const where = computed(() => (page.props.locationScope?.all ? 'all locations' : page.props.location?.name));

let searchTimer;
watch(filters, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('staff.index'),
            Object.fromEntries(Object.entries(filters).filter(([, value]) => value)),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const roleBadge = {
    admin: 'bg-purple-50 text-purple-700 ring-purple-600/20',
    manager: 'bg-blue-50 text-blue-700 ring-blue-600/20',
    cashier: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    storekeeper: 'bg-teal-50 text-teal-700 ring-teal-600/20',
    accountant: 'bg-amber-50 text-amber-800 ring-amber-600/20',
};

const toggleActive = (member) => {
    const action = member.is_active ? 'Deactivate' : 'Activate';

    if (!confirm(`${action} ${member.name}?${member.is_active ? ' They will be signed out and unable to log in.' : ''}`)) {
        return;
    }

    router.patch(route('staff.toggle-active', member.id), {}, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout title="Staff">
        <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500">Staff who work at {{ where }}. Deactivated staff cannot sign in.</p>
                <Link
                    :href="route('staff.create')"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-brand-700"
                >
                    <Icon name="plus" class="size-4" />
                    Add staff
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto]">
                <label class="relative block">
                    <span class="sr-only">Search staff</span>
                    <Icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <TextInput v-model="filters.search" type="search" placeholder="Search name, email or phone" class="w-full pl-9" />
                </label>
                <SelectInput v-model="filters.role" aria-label="Filter by role">
                    <option value="">All roles</option>
                    <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                </SelectInput>
                <SelectInput v-model="filters.status" aria-label="Filter by status">
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </SelectInput>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="hidden px-4 py-3 lg:table-cell">Phone</th>
                            <th scope="col" class="px-4 py-3">Role</th>
                            <th scope="col" class="hidden px-4 py-3 md:table-cell">Locations</th>
                            <th scope="col" class="hidden px-4 py-3 md:table-cell">PIN</th>
                            <th scope="col" class="hidden px-4 py-3 sm:table-cell">Status</th>
                            <th scope="col" class="hidden px-4 py-3 xl:table-cell">Last login</th>
                            <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="member in staff.data" :key="member.id" :class="!member.is_active && 'bg-gray-50/60'">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900" :class="!member.is_active && 'text-gray-500'">{{ member.name }}</p>
                                <p class="text-gray-500">{{ member.email }}</p>
                                <p v-if="!member.is_active" class="mt-1 text-xs font-medium text-gray-500 sm:hidden">Inactive</p>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">{{ member.phone || '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset" :class="roleBadge[member.role]">
                                    {{ member.role_label }}
                                </span>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                <p>{{ member.home_location || (member.role === 'admin' ? 'All locations' : '—') }}</p>
                                <p v-if="member.other_locations.length" class="text-xs text-gray-500">+ {{ member.other_locations.join(', ') }}</p>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                {{ member.has_pin ? 'Set' : '—' }}
                                <button v-if="member.pin_locked" type="button" class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-xs font-semibold text-red-700" title="Locked after too many wrong tries" @click="member.can.update && router.patch(route('staff.unlock-pin', member.id), {}, { preserveScroll: true })">Locked{{ member.can.update ? ' · unlock' : '' }}</button>
                            </td>
                            <td class="hidden px-4 py-3 sm:table-cell">
                                <span
                                    class="inline-flex items-center gap-1.5 text-xs font-medium"
                                    :class="member.is_active ? 'text-emerald-700' : 'text-gray-500'"
                                >
                                    <span class="size-1.5 rounded-full" :class="member.is_active ? 'bg-emerald-500' : 'bg-gray-400'" />
                                    {{ member.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-500 xl:table-cell">{{ member.last_login_at || 'Never' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <div class="flex justify-end gap-3">
                                    <Link
                                        v-if="member.can.update"
                                        :href="route('staff.edit', member.id)"
                                        class="font-semibold text-brand-600 hover:text-brand-700"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        v-if="member.can.toggleActive"
                                        type="button"
                                        class="font-semibold"
                                        :class="member.is_active ? 'text-gray-500 hover:text-red-600' : 'text-emerald-600 hover:text-emerald-700'"
                                        @click="toggleActive(member)"
                                    >
                                        {{ member.is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="staff.data.length === 0">
                            <td colspan="8" class="px-4 py-12 text-center text-gray-500">No staff match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="staff" />
        </div>
    </AdminLayout>
</template>
