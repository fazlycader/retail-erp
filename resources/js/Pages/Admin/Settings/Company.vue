<script setup>
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { describeBytes, maxUploadBytes, uploadTooLarge } from '@/lib/upload';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    settings: Object,
    documentTypes: Array,
    timezone: String,
    currency: String,
});

const { logo_url: _logoUrl, ...fields } = props.settings;

const form = useForm({
    ...Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value ?? ''])),
    document_prefixes: { ...props.settings.document_prefixes },
    logo: null,
    remove_logo: false,
});

const logoInput = ref(null);
const logoPreview = ref(null);
const logoError = ref(null);

const currentLogo = computed(() => {
    if (logoPreview.value) return logoPreview.value;
    if (form.remove_logo) return null;
    return props.settings.logo_url;
});

const pickLogo = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    // Catch big files here: the server would drop the whole form, not just the logo.
    logoError.value = uploadTooLarge(file);
    if (logoError.value) {
        event.target.value = '';
        return;
    }

    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = URL.createObjectURL(file);
};

const removeLogo = () => {
    form.logo = null;
    form.remove_logo = true;
    logoPreview.value = null;
    if (logoInput.value) logoInput.value.value = '';
};

const example = (prefix) => `${(prefix || '…').toUpperCase()}-000123`;

const submit = () => {
    form.post(route('settings.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.remove_logo = false;
            logoPreview.value = null;
            if (logoInput.value) logoInput.value.value = '';
        },
    });
};
</script>

<template>
    <AdminLayout title="Settings">
        <form class="mx-auto max-w-4xl space-y-6" @submit.prevent="submit">
            <Card title="Company" description="Printed on invoices, receipts and documents, and shown in the sidebar.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel value="Logo" />
                        <div class="mt-2 flex items-center gap-4">
                            <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-gray-300 bg-gray-50">
                                <img v-if="currentLogo" :src="currentLogo" alt="Logo preview" class="size-full object-contain" />
                                <Icon v-else name="photo" class="size-8 text-gray-300" />
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="cursor-pointer rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50">
                                    {{ currentLogo ? 'Change' : 'Upload' }}
                                    <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pickLogo" />
                                </label>
                                <button v-if="currentLogo" type="button" class="text-sm font-semibold text-gray-500 hover:text-red-600" @click="removeLogo">Remove</button>
                                <p class="w-full text-xs text-gray-500">PNG, JPG or WebP, up to {{ describeBytes(maxUploadBytes()) }}. It's resized to 512px.</p>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="logoError ?? form.errors.logo" />
                    </div>

                    <div class="sm:col-span-2">
                        <InputLabel for="name" value="Company name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel for="address" value="Registered address" />
                        <TextInput id="address" v-model="form.address" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.address" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Phone" />
                        <TextInput id="phone" v-model="form.phone" type="tel" class="mt-1 block w-full" placeholder="011 234 5678" />
                        <InputError class="mt-2" :message="form.errors.phone" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>
                    <div>
                        <InputLabel value="Time zone" />
                        <p class="mt-2 text-sm text-gray-700">{{ timezone }} <span class="text-gray-400">(fixed)</span></p>
                    </div>
                    <div>
                        <InputLabel value="Currency" />
                        <p class="mt-2 text-sm text-gray-700">{{ currency }} <span class="text-gray-400">(fixed)</span></p>
                    </div>
                </div>
            </Card>

            <Card title="VAT" description="Used when pricing sales and on tax invoices.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="flex items-center justify-between gap-4 sm:col-span-2">
                        <div>
                            <p class="text-sm font-medium text-gray-900">VAT registered</p>
                            <p class="text-sm text-gray-500">Invoices become tax invoices with your VAT number.</p>
                        </div>
                        <Toggle v-model="form.vat_registered" />
                    </div>
                    <div>
                        <InputLabel for="vat_number" value="VAT registration number" />
                        <TextInput id="vat_number" v-model="form.vat_number" class="mt-1 block w-full" :disabled="!form.vat_registered" placeholder="123456789-7000" />
                        <InputError class="mt-2" :message="form.errors.vat_number" />
                    </div>
                    <div>
                        <InputLabel for="vat_rate" value="VAT rate (%)" />
                        <TextInput id="vat_rate" v-model="form.vat_rate" inputmode="decimal" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.vat_rate" />
                    </div>
                    <div class="flex items-center justify-between gap-4 sm:col-span-2">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Prices include VAT</p>
                            <p class="text-sm text-gray-500">On: shelf prices already contain VAT. Off: VAT is added at checkout.</p>
                        </div>
                        <Toggle v-model="form.prices_include_vat" />
                    </div>
                </div>
            </Card>

            <Card title="Document numbers" description="Each document type gets a prefix and its own gap-free running number.">
                <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="type in documentTypes" :key="type.value">
                        <InputLabel :for="`prefix-${type.value}`" :value="type.label" />
                        <TextInput :id="`prefix-${type.value}`" v-model="form.document_prefixes[type.value]" maxlength="6" class="mt-1 block w-full uppercase" required />
                        <p class="mt-1 text-xs text-gray-400">e.g. {{ example(form.document_prefixes[type.value]) }}</p>
                        <InputError class="mt-1" :message="form.errors[`document_prefixes.${type.value}`]" />
                    </div>
                </div>
            </Card>

            <Card title="Catalog & stock">
                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Managers may edit the product catalog</p>
                            <p class="text-sm text-gray-500">On: managers can add and edit products, categories, options and prices. Off: only admins can.</p>
                        </div>
                        <Toggle v-model="form.managers_edit_catalog" />
                    </div>
                    <div class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Allow negative stock</p>
                            <p class="text-sm text-gray-500">On: you can sell or transfer more than the system shows on hand (fix it later with a stock count). Off: the sale is blocked.</p>
                        </div>
                        <Toggle v-model="form.allow_negative_stock" />
                    </div>
                    <div class="flex flex-col justify-between gap-4 border-t border-gray-100 pt-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Stock adjustment approval threshold ({{ currency }})</p>
                            <p class="text-sm text-gray-500">Adjustments above this cost value require a manager PIN (0 = every adjustment needs a PIN).</p>
                        </div>
                        <div class="w-full sm:w-48">
                            <TextInput id="adjustment_approval_value" v-model="form.adjustment_approval_value" inputmode="decimal" class="block w-full" placeholder="50000.00" />
                            <InputError class="mt-1" :message="form.errors.adjustment_approval_value" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card title="Returns & credit notes">
                <div class="space-y-4">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Return window (days)</p>
                            <p class="text-sm text-gray-500">Staff can take returns this many days after the sale. Later returns need a manager.</p>
                        </div>
                        <div class="w-full sm:w-48">
                            <TextInput id="return_days" v-model="form.return_days" type="number" min="0" max="365" class="block w-full" />
                            <InputError class="mt-1" :message="form.errors.return_days" />
                        </div>
                    </div>
                    <div class="flex flex-col justify-between gap-4 border-t border-gray-100 pt-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Credit notes expire after (days)</p>
                            <p class="text-sm text-gray-500">0 = credit notes never expire.</p>
                        </div>
                        <div class="w-full sm:w-48">
                            <TextInput id="credit_note_expiry_days" v-model="form.credit_note_expiry_days" type="number" min="0" max="3650" class="block w-full" />
                            <InputError class="mt-1" :message="form.errors.credit_note_expiry_days" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card title="Reports & the daily email">
                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Managers see profit</p>
                            <p class="text-sm text-gray-500">On: managers see gross profit and margins in reports. Admins and accountants always do.</p>
                        </div>
                        <Toggle v-model="form.managers_see_profit" />
                    </div>
                    <div class="grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <InputLabel for="report_emails" value="Send the daily report to" />
                            <TextInput id="report_emails" v-model="form.report_emails" class="mt-1 block w-full" placeholder="owner@example.com, accounts@example.com" />
                            <InputError class="mt-1" :message="form.errors.report_emails || form.errors['report_emails_list.0']" />
                        </div>
                        <div>
                            <InputLabel for="daily_email_time" value="Send by (if not all days closed)" />
                            <TextInput id="daily_email_time" v-model="form.daily_email_time" type="time" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.daily_email_time" />
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Yesterday's sales per location, top items, low stock and cash differences. Sent once, as soon as every location has closed the day, or at the time above.</p>
                </div>
            </Card>

            <Card title="Integrations">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-500">WooCommerce keys and webhook secret (write-only, with undo).</p>
                    <Link :href="route('integrations.index')" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Open Integrations</Link>
                </div>
            </Card>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Save settings</PrimaryButton>
            </div>
        </form>
    </AdminLayout>
</template>
