<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const role = computed(() => page.props.auth?.user?.role);
const canManage = computed(() => ['admin', 'manager'].includes(role.value));

const tabs = computed(() => [
    { label: 'Jobs Board', href: route('workshop.board'), active: route().current('workshop.board') || route().current('workshop.jobs.*') },
    { label: 'Raw Materials', href: route('workshop.materials.index'), active: route().current('workshop.materials.*') },
    { label: 'Supplier Jobs', href: route('workshop.suppliers.index'), active: route().current('workshop.suppliers.*') },
    ...(canManage.value
        ? [{ label: 'Workshop & SMS Setup', href: route('workshop.setup.index'), active: route().current('workshop.setup.*') }]
        : []),
]);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3">
        <nav class="flex flex-wrap gap-2">
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="tab.href"
                class="rounded-lg px-3.5 py-2 text-sm font-semibold transition"
                :class="tab.active ? 'bg-brand-600 text-white shadow-xs' : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'"
            >
                {{ tab.label }}
            </Link>
        </nav>
        <div class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>

