<script setup>
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { csrfToken, postJson } from '@/lib/http';
import { reactive, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    spec: { type: Object, default: null },
    lineName: { type: String, default: 'Custom item' },
    readOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

const currentSpec = ref(null);
const saving = ref(false);
const uploading = ref(false);
const approving = ref(false);
const errorMsg = ref(null);

const form = reactive({
    id: null,
    width_mm: '',
    depth_mm: '',
    height_mm: '',
    material: '',
    fabric_code: '',
    colour_code: '',
    finish: '',
    lead_time_days: '',
    notes: '',
});

const approvalForm = reactive({
    approved_by_name: '',
    approved_on: new Date().toISOString().slice(0, 10),
    signed_quote: null,
});

const syncFromProp = (s) => {
    currentSpec.value = s ? JSON.parse(JSON.stringify(s)) : null;
    form.id = s?.id ?? null;
    form.width_mm = s?.width_mm ?? '';
    form.depth_mm = s?.depth_mm ?? '';
    form.height_mm = s?.height_mm ?? '';
    form.material = s?.material ?? '';
    form.fabric_code = s?.fabric_code ?? '';
    form.colour_code = s?.colour_code ?? '';
    form.finish = s?.finish ?? '';
    form.lead_time_days = s?.lead_time_days ?? '';
    form.notes = s?.notes ?? '';
    approvalForm.approved_by_name = s?.approved_by_name ?? '';
    approvalForm.approved_on = s?.approved_on ?? new Date().toISOString().slice(0, 10);
    approvalForm.signed_quote = null;
    errorMsg.value = null;
};

watch(
    () => [props.show, props.spec],
    ([isOpen, nextSpec]) => {
        if (isOpen) {
            syncFromProp(nextSpec);
        }
    },
    { immediate: true },
);

const saveSpec = async () => {
    errorMsg.value = null;
    saving.value = true;
    const payload = {
        id: form.id || null,
        width_mm: form.width_mm !== '' ? Number(form.width_mm) : null,
        depth_mm: form.depth_mm !== '' ? Number(form.depth_mm) : null,
        height_mm: form.height_mm !== '' ? Number(form.height_mm) : null,
        material: form.material || null,
        fabric_code: form.fabric_code || null,
        colour_code: form.colour_code || null,
        finish: form.finish || null,
        lead_time_days: form.lead_time_days !== '' ? Number(form.lead_time_days) : null,
        notes: form.notes || null,
    };

    const { ok, data } = await postJson(route('custom-specs.save'), payload);
    saving.value = false;

    if (!ok) {
        errorMsg.value = data?.message ?? 'Could not save specification.';
        return null;
    }

    syncFromProp(data.spec);
    emit('saved', data.spec);
    return data.spec;
};

const saveAndClose = async () => {
    const saved = await saveSpec();
    if (saved) {
        emit('close');
    }
};

const onPickAttachment = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = '';

    let specObj = currentSpec.value;
    if (!specObj?.id) {
        specObj = await saveSpec();
        if (!specObj?.id) return;
    }

    uploading.value = true;
    errorMsg.value = null;
    const fd = new FormData();
    fd.append('file', file);

    try {
        const res = await fetch(route('custom-specs.attachments.store', specObj.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok) {
            errorMsg.value = data?.message ?? 'Upload failed.';
        } else {
            syncFromProp(data.spec);
            emit('saved', data.spec);
        }
    } catch {
        errorMsg.value = 'Network error while uploading file.';
    } finally {
        uploading.value = false;
    }
};

const removeAttachment = async (index) => {
    if (!currentSpec.value?.id) return;
    errorMsg.value = null;

    const res = await fetch(
        route('custom-specs.attachments.destroy', { spec: currentSpec.value.id, index }),
        {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    );
    const data = await res.json();
    if (res.ok && data.spec) {
        syncFromProp(data.spec);
        emit('saved', data.spec);
    }
};

const recordApproval = async () => {
    let specObj = currentSpec.value;
    if (!specObj?.id) {
        specObj = await saveSpec();
        if (!specObj?.id) return;
    }

    approving.value = true;
    errorMsg.value = null;
    const fd = new FormData();
    fd.append('approved_by_name', approvalForm.approved_by_name);
    fd.append('approved_on', approvalForm.approved_on);
    if (approvalForm.signed_quote) {
        fd.append('signed_quote', approvalForm.signed_quote);
    }

    try {
        const res = await fetch(route('custom-specs.approve', specObj.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok) {
            errorMsg.value = data?.message ?? 'Could not record customer approval.';
        } else {
            syncFromProp(data.spec);
            emit('saved', data.spec);
        }
    } catch {
        errorMsg.value = 'Network error while recording approval.';
    } finally {
        approving.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Custom Specification</h3>
                    <p class="text-sm text-gray-500">{{ lineName }}</p>
                </div>
                <Badge v-if="currentSpec?.approved_on" tone="green">
                    Approved by {{ currentSpec.approved_by_name }} ({{ currentSpec.approved_on }})
                </Badge>
            </div>

            <InputError v-if="errorMsg" class="mt-3" :message="errorMsg" />

            <!-- Dimensions & Materials -->
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel value="Width (mm)" />
                    <TextInput v-model="form.width_mm" type="number" min="1" max="20000" :disabled="readOnly" class="mt-1 block w-full" placeholder="2100" />
                </div>
                <div>
                    <InputLabel value="Depth (mm)" />
                    <TextInput v-model="form.depth_mm" type="number" min="1" max="20000" :disabled="readOnly" class="mt-1 block w-full" placeholder="900" />
                </div>
                <div>
                    <InputLabel value="Height (mm)" />
                    <TextInput v-model="form.height_mm" type="number" min="1" max="20000" :disabled="readOnly" class="mt-1 block w-full" placeholder="850" />
                </div>

                <div>
                    <InputLabel value="Material / Timber" />
                    <TextInput v-model="form.material" :disabled="readOnly" class="mt-1 block w-full" placeholder="Solid Teak / Mahogany" />
                </div>
                <div>
                    <InputLabel value="Fabric code" />
                    <TextInput v-model="form.fabric_code" :disabled="readOnly" class="mt-1 block w-full" placeholder="VL-204" />
                </div>
                <div>
                    <InputLabel value="Colour code" />
                    <TextInput v-model="form.colour_code" :disabled="readOnly" class="mt-1 block w-full" placeholder="Sage Green" />
                </div>

                <div class="sm:col-span-2">
                    <InputLabel value="Finish / Polish" />
                    <TextInput v-model="form.finish" :disabled="readOnly" class="mt-1 block w-full" placeholder="Matte Natural Walnut" />
                </div>
                <div>
                    <InputLabel value="Lead time (days)" />
                    <TextInput v-model="form.lead_time_days" type="number" min="1" max="365" :disabled="readOnly" class="mt-1 block w-full" placeholder="14" />
                </div>

                <div class="sm:col-span-3">
                    <InputLabel value="Workshop & design notes" />
                    <textarea
                        v-model="form.notes"
                        rows="2"
                        :disabled="readOnly"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500"
                        placeholder="Brass leg caps, extra firm high-density foam seat cushions..."
                    />
                </div>
            </div>

            <!-- Reference sketches & photos -->
            <div class="mt-5 border-t border-gray-100 pt-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-900">Reference images &amp; sketches</h4>
                    <label v-if="!readOnly" class="cursor-pointer rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        {{ uploading ? 'Uploading…' : '+ Upload image / PDF' }}
                        <input type="file" accept="image/png,image/jpeg,image/webp,application/pdf" class="hidden" :disabled="uploading" @change="onPickAttachment" />
                    </label>
                </div>

                <div v-if="currentSpec?.attachments?.length" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="att in currentSpec.attachments" :key="att.index" class="group relative rounded-lg border border-gray-200 bg-gray-50 p-2">
                        <a :href="att.url" target="_blank" class="block">
                            <img v-if="att.thumb" :src="att.thumb" :alt="att.name" class="h-24 w-full rounded object-cover" />
                            <div v-else class="flex h-24 items-center justify-center rounded bg-white text-xs font-medium text-gray-600">
                                PDF / File
                            </div>
                            <p class="mt-1 truncate text-xs text-gray-700">{{ att.name }}</p>
                        </a>
                        <button
                            v-if="!readOnly"
                            type="button"
                            class="mt-1 text-xs font-semibold text-red-600 hover:underline"
                            @click="removeAttachment(att.index)"
                        >
                            Remove
                        </button>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-gray-500">No sketches or reference photos attached yet.</p>
            </div>

            <!-- Customer Approval -->
            <div class="mt-5 border-t border-gray-100 pt-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-900">Customer sign-off</h4>
                    <a
                        v-if="currentSpec?.signed_quote_url"
                        :href="currentSpec.signed_quote_url"
                        target="_blank"
                        class="text-xs font-semibold text-brand-600 hover:underline"
                    >
                        View signed quote
                    </a>
                </div>

                <div v-if="!readOnly" class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Approved by (customer name)" />
                        <TextInput v-model="approvalForm.approved_by_name" class="mt-1 block w-full text-sm" placeholder="Customer name" />
                    </div>
                    <div>
                        <InputLabel value="Approved date" />
                        <TextInput v-model="approvalForm.approved_on" type="date" class="mt-1 block w-full text-sm" />
                    </div>
                    <div>
                        <InputLabel value="Signed quote scan (optional)" />
                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp,application/pdf"
                            class="mt-1 block w-full text-xs text-gray-600"
                            @change="(e) => (approvalForm.signed_quote = e.target.files?.[0] ?? null)"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <SecondaryButton type="button" :disabled="approving || !approvalForm.approved_by_name" @click="recordApproval">
                            {{ approving ? 'Recording…' : 'Record customer approval' }}
                        </SecondaryButton>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2 border-t border-gray-100 pt-4">
                <SecondaryButton type="button" @click="emit('close')">
                    {{ readOnly ? 'Close' : 'Cancel' }}
                </SecondaryButton>
                <PrimaryButton v-if="!readOnly" type="button" :disabled="saving" @click="saveAndClose">
                    {{ saving ? 'Saving…' : 'Save specification' }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>

