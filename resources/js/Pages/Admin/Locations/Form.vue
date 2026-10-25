<script setup>
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    location: { type: Object, default: null },
    types: Array,
});

const editing = computed(() => props.location !== null);

const form = useForm({
    name: props.location?.name ?? '',
    code: props.location?.code ?? '',
    type: props.location?.type ?? 'showroom',
    address: props.location?.address ?? '',
    phone: props.location?.phone ?? '',
    receipt_header: props.location?.receipt_header ?? '',
    receipt_footer: props.location?.receipt_footer ?? '',
    business_day_starts_at: props.location?.business_day_starts_at ?? '00:00',
    auto_lock_minutes: props.location?.auto_lock_minutes ?? 5,
});

const submit = () => (editing.value ? form.put(route('locations.update', props.location.id)) : form.post(route('locations.store')));
</script>

<template>
    <AdminLayout :title="editing ? `Edit ${location.name}` : 'Add location'">
        <form class="mx-auto max-w-3xl space-y-6" @submit.prevent="submit">
            <Card title="Details">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus placeholder="Main Showroom" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel for="code" value="Short code" />
                        <TextInput id="code" v-model="form.code" maxlength="10" class="mt-1 block w-full uppercase" required placeholder="MS" />
                        <p class="mt-1 text-xs text-gray-500">On document numbers and lists.</p>
                        <InputError class="mt-2" :message="form.errors.code" />
                    </div>
                    <div class="sm:col-span-3">
                        <InputLabel for="type" value="Type" />
                        <SelectInput id="type" v-model="form.type" class="mt-1 block w-full">
                            <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </SelectInput>
                        <InputError class="mt-2" :message="form.errors.type" />
                    </div>
                    <div class="sm:col-span-3">
                        <InputLabel for="phone" value="Phone" />
                        <TextInput id="phone" v-model="form.phone" type="tel" class="mt-1 block w-full" placeholder="011 234 5678" />
                        <InputError class="mt-2" :message="form.errors.phone" />
                    </div>
                    <div class="sm:col-span-6">
                        <InputLabel for="address" value="Address" />
                        <TextInput id="address" v-model="form.address" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.address" />
                    </div>
                </div>
            </Card>

            <Card title="Receipts" description="Printed above and below the items on this location's receipts.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <InputLabel for="receipt_header" value="Header" />
                        <textarea id="receipt_header" v-model="form.receipt_header" rows="4" class="mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500" placeholder="Thank you for shopping with us" />
                        <InputError class="mt-2" :message="form.errors.receipt_header" />
                    </div>
                    <div>
                        <InputLabel for="receipt_footer" value="Footer" />
                        <textarea id="receipt_footer" v-model="form.receipt_footer" rows="4" class="mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500" placeholder="Goods once sold can be exchanged within 7 days with the receipt" />
                        <InputError class="mt-2" :message="form.errors.receipt_footer" />
                    </div>
                </div>
            </Card>

            <Card title="Business day and terminals">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <InputLabel for="business_day_starts_at" value="Business day starts at" />
                        <TextInput id="business_day_starts_at" v-model="form.business_day_starts_at" type="time" max="06:00" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-gray-500">Sales before this time count for the previous day (e.g. 02:00 for a late close). Changes only affect new records.</p>
                        <InputError class="mt-2" :message="form.errors.business_day_starts_at" />
                    </div>
                    <div>
                        <InputLabel for="auto_lock_minutes" value="Shared terminals lock after (minutes idle)" />
                        <TextInput id="auto_lock_minutes" v-model="form.auto_lock_minutes" type="number" min="0" max="120" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-gray-500">0 = never lock automatically.</p>
                        <InputError class="mt-2" :message="form.errors.auto_lock_minutes" />
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('locations.index')" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : 'Add location' }}</PrimaryButton>
            </div>
        </form>
    </AdminLayout>
</template>
