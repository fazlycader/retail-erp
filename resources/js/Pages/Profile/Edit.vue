<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <AdminLayout title="My profile">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs sm:p-8">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs sm:p-8">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-xs sm:p-8">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">Two-factor login</h2>
                    <p class="mt-1 text-sm text-gray-600">A code from an authenticator app on your phone, after your password. Required for admins and accountants.</p>
                </div>
                <Link :href="route('two-factor.show')" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Set up</Link>
            </div>
        </div>
    </AdminLayout>
</template>
