<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    canReceive: { type: Boolean, default: true },
    canManageBills: { type: Boolean, default: false },
});

const tabs = computed(() => {
    const items = [
        {
            label: 'Purchase orders',
            href: route('purchasing.index'),
            active: route().current('purchasing.index') || route().current('purchasing.orders.*'),
        },
        {
            label: 'Reorder suggestions',
            href: route('purchasing.reorder'),
            active: route().current('purchasing.reorder*'),
        },
    ];

    if (props.canReceive) {
        items.push({
            label: 'Receive stock',
            href: route('purchasing.receive'),
            active: route().current('purchasing.receive*'),
        });
    }

    items.push(
        {
            label: 'Goods receipts (GRN)',
            href: route('purchasing.receipts.index'),
            active: route().current('purchasing.receipts.*'),
        },
        {
            label: 'Supplier returns',
            href: route('purchasing.returns.index'),
            active: route().current('purchasing.returns.*'),
        },
    );

    if (props.canManageBills) {
        items.push({
            label: 'Bills & payments',
            href: route('purchasing.bills.index'),
            active: route().current('purchasing.bills.*'),
        });
    }

    items.push({
        label: 'Suppliers',
        href: route('suppliers.index'),
        active: route().current('suppliers.*'),
    });

    return items;
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 print:hidden">
        <nav class="flex flex-wrap gap-1.5" aria-label="Purchasing sections">
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

