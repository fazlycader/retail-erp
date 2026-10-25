<script setup>
import Icon from '@/Components/Icon.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <AdminLayout :title="title">
        <div class="flex min-h-[60vh] items-center justify-center">
            <div class="max-w-md text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                    <Icon name="clock" class="size-7" />
                </span>
                <h2 class="mt-4 text-xl font-semibold text-gray-900">{{ title }} is coming soon</h2>
                <p class="mt-2 text-sm text-gray-500">{{ description }}</p>
                <Link
                    :href="route('dashboard')"
                    class="mt-6 inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
                >
                    Back to dashboard
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
