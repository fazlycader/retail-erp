<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const tabs = computed(() => [
    {
        label: 'Sales orders',
        href: route('sales.index'),
        active: route().current('sales.index') || route().current('sales.orders.*'),
    },
    {
        label: 'Quotations',
        href: route('sales.quotations.index'),
        active: route().current('sales.quotations.*'),
    },
    {
        label: 'Payments',
        href: route('sales.payments.index'),
        active: route().current('sales.payments.*'),
    },
    {
        label: 'Returns',
        href: route('sales.returns.index'),
        active: route().current('sales.returns.*'),
    },
    {
        label: 'Credit notes',
        href: route('sales.credit-notes.index'),
        active: route().current('sales.credit-notes.*'),
    },
    {
        label: 'Customers',
        href: route('customers.index'),
        active: route().current('customers.*'),
    },
]);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 print:hidden">
        <nav class="flex flex-wrap gap-1.5" aria-label="Sales sections">
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

