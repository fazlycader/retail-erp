<script setup>
import Card from '@/Components/Card.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DragHandle from '@/Components/DragHandle.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SortableList from '@/Components/SortableList.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { uploadTooLarge } from '@/lib/upload';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    attributes: Array,
    canEdit: Boolean,
});

const cloneData = (value) => JSON.parse(JSON.stringify(value ?? []));
const localAttributes = ref(cloneData(props.attributes));
watch(
    () => props.attributes,
    (next) => {
        localAttributes.value = cloneData(next);
    },
);

const newAttrForm = useForm({ name: '' });
const createAttribute = () => {
    newAttrForm.post(route('attributes.store'), {
        preserveScroll: true,
        onSuccess: () => newAttrForm.reset(),
    });
};

const reorderAttributes = (reordered) => {
    localAttributes.value = reordered;
    router.post(route('attributes.reorder'), { ids: reordered.map((a) => a.id) }, { preserveScroll: true });
};

const reorderValues = (attr, reordered) => {
    attr.values = reordered;
    router.post(
        route('attributes.values.reorder', attr.id),
        { ids: reordered.map((v) => v.id) },
        { preserveScroll: true },
    );
};

const deleteAttribute = (attr) => {
    if (confirm(`Delete option "${attr.name}"?`)) {
        router.delete(route('attributes.destroy', attr.id), { preserveScroll: true });
    }
};

// Rename attribute modal
const editAttrModal = ref(null);
const editAttrForm = useForm({ name: '' });
const openRenameAttr = (attr) => {
    editAttrModal.value = attr;
    editAttrForm.name = attr.name;
};
const saveRenameAttr = () => {
    editAttrForm.patch(route('attributes.update', editAttrModal.value.id), {
        preserveScroll: true,
        onSuccess: () => (editAttrModal.value = null),
    });
};

// Value modal (add or edit)
const valueModalOpen = ref(false);
const activeAttribute = ref(null);
const editingValue = ref(null);
const swatchError = ref(null);

const valueForm = useForm({
    value: '',
    code: '',
    swatch_color: '',
    swatch_image: null,
    remove_swatch_image: false,
});

const openAddValue = (attr) => {
    activeAttribute.value = attr;
    editingValue.value = null;
    swatchError.value = null;
    valueForm.reset();
    valueForm.clearErrors();
    valueModalOpen.value = true;
};

const openEditValue = (attr, val) => {
    activeAttribute.value = attr;
    editingValue.value = val;
    swatchError.value = null;
    valueForm.clearErrors();
    valueForm.value = val.value;
    valueForm.code = val.code;
    valueForm.swatch_color = val.swatch_color ?? '';
    valueForm.swatch_image = null;
    valueForm.remove_swatch_image = false;
    valueModalOpen.value = true;
};

const pickSwatchImage = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    swatchError.value = uploadTooLarge(file);
    if (swatchError.value) {
        e.target.value = '';
        return;
    }
    valueForm.swatch_image = file;
};

const submitValue = () => {
    const url = editingValue.value
        ? route('attributes.values.update', [activeAttribute.value.id, editingValue.value.id])
        : route('attributes.values.store', activeAttribute.value.id);

    valueForm.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (valueModalOpen.value = false),
    });
};

const deleteValue = (attr, val) => {
    if (confirm(`Delete "${val.value}" from ${attr.name}?`)) {
        router.delete(route('attributes.values.destroy', [attr.id, val.id]), {
            preserveScroll: true,
            onSuccess: () => (valueModalOpen.value = false),
        });
    }
};
</script>

<template>
    <AdminLayout title="Product options">
        <CatalogNav />

        <!-- Add new option -->
        <Card v-if="canEdit" class="mb-6" title="Add option" description="Options like Material, Colour, Fabric, Size or Finish are shared across all products.">
            <form class="flex flex-wrap items-start gap-3" @submit.prevent="createAttribute">
                <div class="w-full sm:w-72">
                    <TextInput v-model="newAttrForm.name" class="block w-full" placeholder="e.g. Colour, Size, Fabric…" required />
                    <InputError class="mt-1" :message="newAttrForm.errors.name" />
                </div>
                <PrimaryButton :disabled="newAttrForm.processing">Add option</PrimaryButton>
            </form>
        </Card>

        <Card v-if="!localAttributes.length" class="py-10 text-center">
            <p class="text-sm text-gray-500">No options defined yet.</p>
        </Card>

        <SortableList v-else :items="localAttributes" :disabled="!canEdit" class="space-y-4" @reorder="reorderAttributes">
            <template #default="{ item: attr }">
                <Card>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
                        <div class="flex items-center gap-2">
                            <DragHandle v-if="canEdit" />
                            <h3 class="text-base font-semibold text-gray-900">{{ attr.name }}</h3>
                            <span class="text-xs text-gray-400">({{ attr.values.length }} values)</span>
                        </div>
                        <div v-if="canEdit" class="flex items-center gap-3">
                            <button type="button" class="text-xs font-semibold text-brand-600 hover:text-brand-500" @click="openAddValue(attr)">
                                + Add value
                            </button>
                            <button type="button" class="text-xs font-semibold text-gray-600 hover:text-gray-900" @click="openRenameAttr(attr)">
                                Rename
                            </button>
                            <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-500" @click="deleteAttribute(attr)">
                                Delete
                            </button>
                        </div>
                    </div>

                    <p v-if="!attr.values.length" class="mt-3 text-xs text-gray-500">No values yet.</p>

                    <SortableList
                        v-else
                        :items="attr.values"
                        :disabled="!canEdit"
                        class="mt-3 flex flex-wrap gap-2"
                        @reorder="(reordered) => reorderValues(attr, reordered)"
                    >
                        <template #default="{ item: val }">
                            <div class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-sm">
                                <DragHandle v-if="canEdit" class="size-5!" />
                                <img
                                    v-if="val.swatch_image_url"
                                    :src="val.swatch_image_url"
                                    :alt="val.value"
                                    class="size-4 rounded-full object-cover"
                                />
                                <span
                                    v-else-if="val.swatch_color"
                                    class="size-4 rounded-full border border-black/15"
                                    :style="{ backgroundColor: val.swatch_color }"
                                />
                                <span class="font-medium text-gray-900">{{ val.value }}</span>
                                <span class="font-mono text-xs text-gray-400">{{ val.code }}</span>
                                <button
                                    v-if="canEdit"
                                    type="button"
                                    class="ml-1 text-xs font-semibold text-brand-600 hover:text-brand-500"
                                    @click="openEditValue(attr, val)"
                                >
                                    Edit
                                </button>
                            </div>
                        </template>
                    </SortableList>
                </Card>
            </template>
        </SortableList>

        <!-- Rename Option Modal -->
        <Modal :show="Boolean(editAttrModal)" max-width="sm" @close="editAttrModal = null">
            <form class="p-6" @submit.prevent="saveRenameAttr">
                <h2 class="text-base font-semibold text-gray-900">Rename option</h2>
                <div class="mt-4">
                    <InputLabel value="Name" />
                    <TextInput v-model="editAttrForm.name" class="mt-1 block w-full" required />
                    <InputError class="mt-1" :message="editAttrForm.errors.name" />
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" @click="editAttrModal = null">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="editAttrForm.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Add/Edit Value Modal -->
        <Modal :show="valueModalOpen" max-width="md" @close="valueModalOpen = false">
            <form class="p-6" @submit.prevent="submitValue">
                <h2 class="text-base font-semibold text-gray-900">
                    {{ editingValue ? `Edit ${editingValue.value}` : `Add value to ${activeAttribute?.name}` }}
                </h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="val-name" value="Value" />
                        <TextInput id="val-name" v-model="valueForm.value" class="mt-1 block w-full" placeholder="e.g. Charcoal Grey, 3 Seater, Teak" required />
                        <InputError class="mt-1" :message="valueForm.errors.value" />
                    </div>

                    <div>
                        <InputLabel for="val-code" value="SKU short code (optional)" />
                        <TextInput id="val-code" v-model="valueForm.code" class="mt-1 block w-full font-mono uppercase" maxlength="12" placeholder="Auto (e.g. CHA)" />
                        <InputError class="mt-1" :message="valueForm.errors.code" />
                    </div>

                    <div>
                        <InputLabel for="val-color" value="Swatch colour (optional #RRGGBB)" />
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="color"
                                :value="valueForm.swatch_color || '#6366f1'"
                                class="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white p-0.5"
                                @input="(e) => (valueForm.swatch_color = e.target.value)"
                            />
                            <TextInput id="val-color" v-model="valueForm.swatch_color" class="w-36 font-mono text-sm" placeholder="#4B5563" />
                            <button
                                v-if="valueForm.swatch_color"
                                type="button"
                                class="text-xs text-gray-500 hover:text-gray-800"
                                @click="valueForm.swatch_color = ''"
                            >
                                Clear
                            </button>
                        </div>
                        <InputError class="mt-1" :message="valueForm.errors.swatch_color" />
                    </div>

                    <div>
                        <InputLabel value="Swatch texture image (optional)" />
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block text-xs text-gray-600" @change="pickSwatchImage" />
                        <InputError class="mt-1" :message="swatchError ?? valueForm.errors.swatch_image" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <div>
                        <DangerButton v-if="editingValue" type="button" @click="deleteValue(activeAttribute, editingValue)">
                            Delete
                        </DangerButton>
                    </div>
                    <div class="flex gap-2">
                        <SecondaryButton type="button" @click="valueModalOpen = false">Cancel</SecondaryButton>
                        <PrimaryButton :disabled="valueForm.processing">Save</PrimaryButton>
                    </div>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>

