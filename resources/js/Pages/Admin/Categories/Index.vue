<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DragHandle from '@/Components/DragHandle.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import SortableList from '@/Components/SortableList.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { uploadTooLarge } from '@/lib/upload';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    tree: Array,
    options: Array,
    maxDepth: Number,
    canEdit: Boolean,
});

const cloneData = (value) => JSON.parse(JSON.stringify(value ?? []));
const localTree = ref(cloneData(props.tree));
watch(
    () => props.tree,
    (next) => {
        localTree.value = cloneData(next);
    },
);

const saveRearrange = (parentId, list) => {
    const nodes = list.map((item, idx) => ({
        id: item.id,
        parent_id: parentId,
        sort_order: idx,
    }));

    router.post(route('categories.rearrange'), { nodes }, { preserveScroll: true });
};

const onReorderLevel1 = (reordered) => {
    localTree.value = reordered;
    saveRearrange(null, reordered);
};

const onReorderChildren = (parent, reordered) => {
    parent.children = reordered;
    saveRearrange(parent.id, reordered);
};

// Add / edit modal
const modalOpen = ref(false);
const editingCategory = ref(null);
const imageError = ref(null);

const form = useForm({
    parent_id: '',
    name: '',
    slug: '',
    is_active: true,
    is_material: false,
    default_warranty_months: '',
    commission_group: '',
    image: null,
    remove_image: false,
});

const allowedParents = computed(() =>
    props.options.filter((opt) => opt.depth < props.maxDepth && opt.id !== editingCategory.value?.id),
);

const openCreate = (parentId = '') => {
    editingCategory.value = null;
    imageError.value = null;
    form.reset();
    form.clearErrors();
    form.parent_id = parentId ?? '';
    form.is_active = true;
    form.is_material = false;
    modalOpen.value = true;
};

const openEdit = (cat) => {
    editingCategory.value = cat;
    imageError.value = null;
    form.clearErrors();
    form.parent_id = cat.parent_id ?? '';
    form.name = cat.name;
    form.slug = cat.slug;
    form.is_active = Boolean(cat.is_active);
    form.is_material = Boolean(cat.is_material);
    form.default_warranty_months = cat.default_warranty_months ?? '';
    form.commission_group = cat.commission_group ?? '';
    form.image = null;
    form.remove_image = false;
    modalOpen.value = true;
};

const pickImage = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    imageError.value = uploadTooLarge(file);
    if (imageError.value) {
        e.target.value = '';
        return;
    }
    form.image = file;
    form.remove_image = false;
};

const submit = () => {
    const transform = (data) => ({
        ...data,
        parent_id: data.parent_id === '' ? null : Number(data.parent_id),
        default_warranty_months: data.default_warranty_months === '' ? null : Number(data.default_warranty_months),
    });

    const url = editingCategory.value ? route('categories.update', editingCategory.value.id) : route('categories.store');

    form.transform(transform).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
};

const deleteCategory = (cat) => {
    if (confirm(`Delete category "${cat.name}"?`)) {
        router.delete(route('categories.destroy', cat.id), {
            preserveScroll: true,
            onSuccess: () => {
                modalOpen.value = false;
            },
        });
    }
};
</script>

<template>
    <AdminLayout title="Categories">
        <CatalogNav>
            <PrimaryButton v-if="canEdit" @click="openCreate()">
                <Icon name="plus" class="mr-1.5 size-4" />
                Add category
            </PrimaryButton>
        </CatalogNav>

        <Card
            title="Category tree"
            :description="`Nested up to ${maxDepth} levels. Drag by the handle to reorder siblings, or edit a category to move it under another parent.`"
        >
            <p v-if="!localTree.length" class="text-sm text-gray-500">No categories yet.</p>

            <SortableList v-else :items="localTree" :disabled="!canEdit" class="space-y-3" @reorder="onReorderLevel1">
                <template #default="{ item: root }">
                    <div class="rounded-xl border border-gray-200 bg-white">
                        <!-- Level 1 row -->
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <DragHandle v-if="canEdit" />
                            <div class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                <img v-if="root.thumbnail_url" :src="root.thumbnail_url" :alt="root.name" class="size-full object-cover" />
                                <Icon v-else name="tag" class="size-4 text-gray-400" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900">{{ root.name }}</span>
                                    <span class="font-mono text-xs text-gray-400">/{{ root.slug }}</span>
                                    <Badge :tone="root.is_active ? 'green' : 'gray'">{{ root.is_active ? 'Active' : 'Inactive' }}</Badge>
                                    <Badge v-if="root.is_material" tone="amber">Raw materials</Badge>
                                    <Badge v-if="root.default_warranty_months != null" tone="blue">{{ root.default_warranty_months }}m warranty</Badge>
                                    <Badge v-if="root.commission_group" tone="amber">{{ root.commission_group }}</Badge>
                                </div>
                            </div>
                            <span class="text-xs text-gray-500">{{ root.products_count }} product(s)</span>
                            <div v-if="canEdit" class="flex items-center gap-2">
                                <button type="button" class="text-xs font-semibold text-gray-600 hover:text-gray-900" @click="openCreate(root.id)">
                                    + Subcategory
                                </button>
                                <button type="button" class="text-xs font-semibold text-brand-600 hover:text-brand-500" @click="openEdit(root)">
                                    Edit
                                </button>
                            </div>
                        </div>

                        <!-- Level 2 children -->
                        <SortableList
                            v-if="root.children?.length"
                            :items="root.children"
                            :disabled="!canEdit"
                            class="space-y-1.5 border-t border-gray-100 bg-gray-50/60 py-2 pl-8 pr-3"
                            @reorder="(reordered) => onReorderChildren(root, reordered)"
                        >
                            <template #default="{ item: child }">
                                <div class="rounded-lg border border-gray-200/80 bg-white">
                                    <div class="flex items-center gap-3 px-3 py-2">
                                        <DragHandle v-if="canEdit" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-gray-900">{{ child.name }}</span>
                                                <span class="font-mono text-xs text-gray-400">/{{ child.slug }}</span>
                                                <Badge v-if="!child.is_active" tone="gray">Inactive</Badge>
                                                <Badge v-if="child.default_warranty_months != null" tone="blue">{{ child.default_warranty_months }}m warranty</Badge>
                                                <Badge v-if="child.commission_group" tone="amber">{{ child.commission_group }}</Badge>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-500">{{ child.products_count }} product(s)</span>
                                        <div v-if="canEdit" class="flex items-center gap-2">
                                            <button
                                                v-if="child.depth < maxDepth"
                                                type="button"
                                                class="text-xs font-semibold text-gray-600 hover:text-gray-900"
                                                @click="openCreate(child.id)"
                                            >
                                                + Subcategory
                                            </button>
                                            <button type="button" class="text-xs font-semibold text-brand-600 hover:text-brand-500" @click="openEdit(child)">
                                                Edit
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Level 3 grandchildren -->
                                    <SortableList
                                        v-if="child.children?.length"
                                        :items="child.children"
                                        :disabled="!canEdit"
                                        class="space-y-1 border-t border-gray-100 bg-gray-50/60 py-2 pl-8 pr-3"
                                        @reorder="(reordered) => onReorderChildren(child, reordered)"
                                    >
                                        <template #default="{ item: leaf }">
                                            <div class="flex items-center gap-3 rounded-md border border-gray-200/70 bg-white px-3 py-1.5">
                                                <DragHandle v-if="canEdit" />
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="text-sm text-gray-800">{{ leaf.name }}</span>
                                                        <span class="font-mono text-xs text-gray-400">/{{ leaf.slug }}</span>
                                                        <Badge v-if="!leaf.is_active" tone="gray">Inactive</Badge>
                                                        <Badge v-if="leaf.default_warranty_months != null" tone="blue">{{ leaf.default_warranty_months }}m warranty</Badge>
                                                        <Badge v-if="leaf.commission_group" tone="amber">{{ leaf.commission_group }}</Badge>
                                                    </div>
                                                </div>
                                                <span class="text-xs text-gray-500">{{ leaf.products_count }} product(s)</span>
                                                <button
                                                    v-if="canEdit"
                                                    type="button"
                                                    class="text-xs font-semibold text-brand-600 hover:text-brand-500"
                                                    @click="openEdit(leaf)"
                                                >
                                                    Edit
                                                </button>
                                            </div>
                                        </template>
                                    </SortableList>
                                </div>
                            </template>
                        </SortableList>
                    </div>
                </template>
            </SortableList>
        </Card>

        <!-- Category Modal -->
        <Modal :show="modalOpen" max-width="lg" @close="modalOpen = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-base font-semibold text-gray-900">
                    {{ editingCategory ? `Edit ${editingCategory.name}` : 'Add category' }}
                </h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="cat-parent" value="Parent category" />
                        <SelectInput id="cat-parent" v-model="form.parent_id" class="mt-1 block w-full">
                            <option value="">None (top level)</option>
                            <option v-for="p in allowedParents" :key="p.id" :value="p.id">{{ p.path }}</option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.parent_id" />
                    </div>

                    <div>
                        <InputLabel for="cat-name" value="Name" />
                        <TextInput id="cat-name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="cat-slug" value="Slug (optional)" />
                        <TextInput id="cat-slug" v-model="form.slug" class="mt-1 block w-full font-mono text-sm" placeholder="Auto-generated" />
                        <InputError class="mt-1" :message="form.errors.slug" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="cat-warranty" value="Default warranty (months)" />
                            <TextInput id="cat-warranty" v-model="form.default_warranty_months" type="number" min="0" max="600" class="mt-1 block w-full" placeholder="Inherited" />
                            <InputError class="mt-1" :message="form.errors.default_warranty_months" />
                        </div>
                        <div>
                            <InputLabel for="cat-comm" value="Commission group" />
                            <TextInput id="cat-comm" v-model="form.commission_group" class="mt-1 block w-full" placeholder="e.g. sofas, mattresses" />
                            <InputError class="mt-1" :message="form.errors.commission_group" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Category image (optional)" />
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block text-xs text-gray-600" @change="pickImage" />
                        <InputError class="mt-1" :message="imageError ?? form.errors.image" />
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <div>
                            <span class="text-sm font-medium text-gray-900">Raw materials category</span>
                            <p class="text-xs text-gray-500">Products here can be issued to workshop jobs in fractional units (m, kg, sqft…).</p>
                        </div>
                        <Toggle v-model="form.is_material" />
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <span class="text-sm font-medium text-gray-900">Active</span>
                        <Toggle v-model="form.is_active" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <div>
                        <DangerButton v-if="editingCategory" type="button" @click="deleteCategory(editingCategory)">
                            Delete
                        </DangerButton>
                    </div>
                    <div class="flex gap-2">
                        <SecondaryButton type="button" @click="modalOpen = false">Cancel</SecondaryButton>
                        <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                    </div>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>

