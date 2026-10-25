<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const manager = computed(() => !!page.props.auth?.can?.override);

const tabs = computed(() => [
    { label: 'My shift', href: route('cash.index'), active: route().current('cash.index') },
    ...(manager.value
        ? [
              { label: 'Shifts', href: route('cash.shifts.index'), active: route().current('cash.shifts.*') },
              { label: 'Bank deposits', href: route('cash.deposits.index'), active: route().current('cash.deposits.*') },
              { label: 'End of day', href: route('cash.eod'), active: route().current('cash.eod*') },
              { label: 'Registers & settings', href: route('cash.setup'), active: route().current('cash.setup*') },
          ]
        : []),
]);
</script>

<template>
    <nav class="mb-5 flex flex-wrap gap-1.5 border-b border-gray-200 pb-3 print:hidden" aria-label="Shift & cash sections">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href"
            class="rounded-lg px-3 py-1.5 text-sm font-medium"
            :class="tab.active ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
