<script setup>
import Card from '@/Components/Card.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';

defineProps({
    enabled: Boolean,
    required: Boolean,
    pending: Object,
    recoveryCodes: Array,
    remaining: Number,
});

const start = useForm({ password: '' });
const confirm = useForm({ code: '' });
const regenerate = useForm({ password: '' });
const disable = useForm({ password: '' });
const options = { preserveScroll: true, onFinish: () => {} };
</script>

<template>
    <AdminLayout title="Two-factor login">
        <div class="max-w-2xl space-y-6">
            <p v-if="required && !enabled" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950">Two-factor login is required for admins and managers. Set it up to continue.</p>

            <Card v-if="recoveryCodes" title="Your recovery codes" description="Store these somewhere safe (not on the same phone). Each works once if you lose your phone. They won't be shown again.">
                <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
                    <li v-for="code in recoveryCodes" :key="code" class="rounded-lg bg-gray-100 px-3 py-2 text-center">{{ code }}</li>
                </ul>
            </Card>

            <Card v-if="enabled" title="Two-factor login is on" :description="`You'll enter a code from your authenticator app after your password. ${remaining} recovery code(s) left.`">
                <div class="space-y-6">
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="regenerate.post(route('two-factor.recovery'), { ...options, onSuccess: () => regenerate.reset() })">
                        <div class="flex-1">
                            <InputLabel for="regen-password" value="Password" />
                            <TextInput id="regen-password" v-model="regenerate.password" type="password" class="mt-1 block w-full" autocomplete="current-password" required />
                            <InputError class="mt-1" :message="regenerate.errors.password" />
                        </div>
                        <PrimaryButton :disabled="regenerate.processing">New recovery codes</PrimaryButton>
                    </form>
                    <form v-if="!required" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-5" @submit.prevent="disable.delete(route('two-factor.disable'), { ...options, onSuccess: () => disable.reset() })">
                        <div class="flex-1">
                            <InputLabel for="off-password" value="Password" />
                            <TextInput id="off-password" v-model="disable.password" type="password" class="mt-1 block w-full" autocomplete="current-password" required />
                            <InputError class="mt-1" :message="disable.errors.password" />
                        </div>
                        <DangerButton :disabled="disable.processing">Turn off</DangerButton>
                    </form>
                </div>
            </Card>

            <Card v-else-if="pending" title="Scan this QR code" description="Use Google Authenticator, Microsoft Authenticator, 1Password or similar, then enter the 6-digit code it shows.">
                <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                    <div class="w-48 shrink-0 rounded-xl bg-white p-2 ring-1 ring-gray-200" v-html="pending.qr" />
                    <form class="w-full space-y-3" @submit.prevent="confirm.post(route('two-factor.confirm'), options)">
                        <p class="text-sm text-gray-600">Can't scan? Enter this key in the app: <code class="block break-all rounded bg-gray-100 px-2 py-1 font-mono text-xs">{{ pending.secret }}</code></p>
                        <div>
                            <InputLabel for="confirm-code" value="6-digit code" />
                            <TextInput id="confirm-code" v-model="confirm.code" class="mt-1 block w-full text-center text-xl tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                            <InputError class="mt-1" :message="confirm.errors.code" />
                        </div>
                        <PrimaryButton :disabled="confirm.processing">Turn on two-factor login</PrimaryButton>
                    </form>
                </div>
            </Card>

            <Card v-else title="Two-factor login" description="Protect your admin/manager account with a code from your phone in addition to your password.">
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="start.post(route('two-factor.start'), { ...options, onSuccess: () => start.reset() })">
                    <div class="flex-1">
                        <InputLabel for="start-password" value="Confirm your password" />
                        <TextInput id="start-password" v-model="start.password" type="password" class="mt-1 block w-full" autocomplete="current-password" required />
                        <InputError class="mt-1" :message="start.errors.password" />
                    </div>
                    <PrimaryButton :disabled="start.processing">Set up</PrimaryButton>
                </form>
            </Card>
        </div>
    </AdminLayout>
</template>
