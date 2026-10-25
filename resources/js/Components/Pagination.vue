<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <nav v-if="paginator.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Pagination">
        <p class="text-gray-500">
            Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5"
                    :class="link.active ? 'bg-brand-600 text-white' : 'text-gray-700 hover:bg-gray-100'"
                    v-html="link.label"
                />
                <span v-else class="rounded-md px-3 py-1.5 text-gray-300" v-html="link.label" />
            </template>
        </div>
    </nav>
</template>
