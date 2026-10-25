<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const tabs = computed(() => [
    {
        label: 'Stock overview',
        href: route('stock.index'),
        active: route().current('stock.index') || route().current('stock.show'),
    },
    {
        label: 'Adjustments',
        href: route('stock.adjustments.index'),
        active: route().current('stock.adjustments.*'),
    },
    {
        label: 'Stock counts',
        href: route('stock.counts.index'),
        active: route().current('stock.counts.*'),
    },
    {
        label: 'Serialized units',
        href: route('stock.units.index'),
        active: route().current('stock.units.*'),
    },
    {
        label: 'Opening stock import',
        href: route('stock.opening.index'),
        active: route().current('stock.opening.*'),
    },
]);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 print:hidden">
        <nav class="flex flex-wrap gap-1.5" aria-label="Inventory sections">
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="tab.href"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="tab.active ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
            >
                {{ tab.label }}
            </Link>
        </nav>
        <div class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>

