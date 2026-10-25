<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const canEdit = computed(() => page.props.auth?.can?.editCatalog ?? false);

const tabs = computed(() => [
    { label: 'Products', href: route('products.index'), active: route().current('products.index') || route().current('products.create') || route().current('products.edit') },
    { label: 'Categories', href: route('categories.index'), active: route().current('categories.*') },
    { label: 'Options', href: route('attributes.index'), active: route().current('attributes.*') },
    { label: 'Print labels', href: route('products.labels'), active: route().current('products.labels*') },
    ...(canEdit.value ? [{ label: 'Import', href: route('products.import.index'), active: route().current('products.import.*') }] : []),
]);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 print:hidden">
        <nav class="flex flex-wrap gap-1.5" aria-label="Catalog sections">
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

