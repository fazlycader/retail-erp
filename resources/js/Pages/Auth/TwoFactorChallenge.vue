<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });
const submit = () => form.post(route('two-factor.verify'), { onFinish: () => form.reset() });
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor login" />
        <h1 class="mb-2 text-lg font-semibold text-gray-900">Two-factor login</h1>
        <p class="mb-5 text-sm text-gray-600">
            {{ useRecovery ? 'Enter one of your recovery codes. Each code works once.' : 'Open your authenticator app and enter the 6-digit code for this account.' }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="!useRecovery">
                <InputLabel for="code" value="Code" />
                <TextInput id="code" v-model="form.code" class="mt-1 block w-full text-center text-2xl tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required />
            </div>
            <div v-else>
                <InputLabel for="recovery" value="Recovery code" />
                <TextInput id="recovery" v-model="form.recovery_code" class="mt-1 block w-full" autocomplete="off" placeholder="abcde-12345" required />
            </div>
            <InputError :message="form.errors.code ?? form.errors.recovery_code" />

            <div class="flex items-center justify-between">
                <button type="button" class="text-sm font-medium text-gray-600 underline" @click="(useRecovery = !useRecovery), form.clearErrors()">
                    {{ useRecovery ? 'Use the app code' : 'Use a recovery code' }}
                </button>
                <PrimaryButton :disabled="form.processing">Sign in</PrimaryButton>
            </div>
        </form>
        <Link :href="route('login')" class="mt-6 block text-center text-sm text-gray-500 underline">Back to sign in</Link>
    </GuestLayout>
</template>
