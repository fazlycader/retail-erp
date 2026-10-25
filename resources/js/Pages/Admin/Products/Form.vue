<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DragHandle from '@/Components/DragHandle.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import SortableList from '@/Components/SortableList.vue';
import TagInput from '@/Components/TagInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { postJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { describeBytes, maxUploadBytes, uploadTooLarge } from '@/lib/upload';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    product: Object,
    priceLogs: {
        type: Array,
        default: () => [],
    },
    categories: Array,
    types: Array,
    attributes: Array,
    suppliers: Array,
    tagSuggestions: Array,
    canEdit: Boolean,
    canViewCost: Boolean,
});

const isEditing = computed(() => Boolean(props.product?.id));
const activeTab = ref('details');

const defaultVariant = () => ({
    id: null,
    combination_key: '',
    value_ids: [],
    name: '',
    sku: '',
    barcode: '',
    price: '0.00',
    min_price: '0.00',
    online_price: '',
    reorder_level: '0',
    reorder_qty: '0',
    is_active: true,
    average_cost: 0,
    last_cost: null,
    suppliers: [],
});

const initialVariants = props.product?.variants?.length
    ? props.product.variants.map((v) => ({
          id: v.id,
          combination_key: v.combination_key,
          value_ids: v.value_ids ?? [],
          name: v.name ?? '',
          sku: v.sku ?? '',
          barcode: v.barcode ?? '',
          price: v.price_input ?? '0.00',
          min_price: v.min_price_input ?? '0.00',
          online_price: v.online_price_input ?? '',
          reorder_level: v.reorder_level_input ?? '0',
          reorder_qty: v.reorder_qty_input ?? '0',
          is_active: Boolean(v.is_active),
          average_cost: v.average_cost,
          last_cost: v.last_cost,
          suppliers: (v.suppliers ?? []).map((s) => ({
              supplier_id: s.supplier_id,
              supplier_sku: s.supplier_sku ?? '',
              last_cost: s.last_cost_input ?? '',
              is_preferred: Boolean(s.is_preferred),
          })),
      }))
    : [defaultVariant()];

const form = useForm({
    category_id: props.product?.category_id ?? props.categories[0]?.id ?? '',
    name: props.product?.name ?? '',
    slug: props.product?.slug ?? '',
    brand: props.product?.brand ?? '',
    description: props.product?.description ?? '',
    type: props.product?.type ?? 'stock',
    unit: props.product?.unit ?? 'pcs',
    width_mm: props.product?.width_mm ?? '',
    depth_mm: props.product?.depth_mm ?? '',
    height_mm: props.product?.height_mm ?? '',
    weight_g: props.product?.weight_g ?? '',
    assembly_required: Boolean(props.product?.assembly_required),
    requires_delivery: Boolean(props.product?.requires_delivery),
    warranty_months: props.product?.warranty_months ?? '',
    is_serialized: Boolean(props.product?.is_serialized),
    publish_online: Boolean(props.product?.publish_online),
    seo_title: props.product?.seo_title ?? '',
    seo_description: props.product?.seo_description ?? '',
    is_active: props.product?.is_active ?? true,
    tags: props.product?.tags ?? [],
    attribute_ids: props.product?.attribute_ids ?? [],
    variants: initialVariants,
});

// Option generator state: selected value IDs per attribute ID
const pickedValues = reactive(
    Object.fromEntries(
        props.attributes.map((attr) => {
            const usedIds = new Set(initialVariants.flatMap((v) => v.value_ids));
            const matching = attr.values.filter((val) => usedIds.has(val.id)).map((val) => val.id);
            return [attr.id, matching];
        }),
    ),
);

const baseSku = ref(
    props.product?.variants?.[0]?.sku?.split('-')[0] ||
        (form.name ? form.name.replace(/[^A-Za-z0-9]/g, '').slice(0, 6).toUpperCase() : 'SKU'),
);
const generatorError = ref(null);
const generating = ref(false);

const toggleAttributeAxis = (attrId, checked) => {
    if (checked) {
        if (!form.attribute_ids.includes(attrId)) {
            form.attribute_ids = [...form.attribute_ids, attrId];
        }
    } else {
        form.attribute_ids = form.attribute_ids.filter((id) => id !== attrId);
        pickedValues[attrId] = [];
    }
};

const togglePickedValue = (attrId, valId, checked) => {
    const current = pickedValues[attrId] ?? [];
    pickedValues[attrId] = checked ? [...new Set([...current, valId])] : current.filter((id) => id !== valId);
};

const generateCombinations = async () => {
    generatorError.value = null;

    if (form.attribute_ids.length === 0) {
        form.variants = [
            {
                ...defaultVariant(),
                ...(form.variants[0] ?? {}),
                combination_key: '',
                value_ids: [],
                name: '',
                sku: form.variants[0]?.sku || baseSku.value || 'SKU-001',
            },
        ];
        return;
    }

    const groups = {};
    for (const attrId of form.attribute_ids) {
        if (!pickedValues[attrId]?.length) {
            const attrName = props.attributes.find((a) => a.id === attrId)?.name ?? 'option';
            generatorError.value = `Pick at least one value for ${attrName}, or untick it.`;
            return;
        }
        groups[attrId] = pickedValues[attrId];
    }

    generating.value = true;
    const { ok, data } = await postJson(route('products.generate-variants'), {
        groups,
        base_sku: baseSku.value || 'SKU',
        product_id: props.product?.id ?? null,
    });
    generating.value = false;

    if (!ok) {
        generatorError.value = data.message ?? 'Could not generate combinations.';
        return;
    }

    const byKey = new Map(form.variants.map((v) => [v.combination_key, v]));
    const fallbackPrice = form.variants[0]?.price ?? '0.00';
    const fallbackMinPrice = form.variants[0]?.min_price ?? '0.00';

    form.variants = data.variants.map((gen) => {
        const existing = byKey.get(gen.combination_key);
        return {
            id: existing?.id ?? gen.existing_id ?? null,
            combination_key: gen.combination_key,
            value_ids: gen.value_ids,
            name: gen.name,
            sku: existing?.sku || gen.sku,
            barcode: existing?.barcode ?? '',
            price: existing?.price ?? fallbackPrice,
            min_price: existing?.min_price ?? fallbackMinPrice,
            online_price: existing?.online_price ?? '',
            reorder_level: existing?.reorder_level ?? '0',
            reorder_qty: existing?.reorder_qty ?? '0',
            is_active: existing?.is_active ?? true,
            average_cost: existing?.average_cost ?? 0,
            last_cost: existing?.last_cost ?? null,
            suppliers: existing?.suppliers ?? [],
        };
    });
};

const copyFirstPricesToAll = () => {
    if (form.variants.length < 2) return;
    const first = form.variants[0];
    form.variants = form.variants.map((v, idx) =>
        idx === 0
            ? v
            : {
                  ...v,
                  price: first.price,
                  min_price: first.min_price,
                  online_price: first.online_price,
              },
    );
};

const selectedCategoryWarranty = computed(() => {
    const cat = props.categories.find((c) => Number(c.id) === Number(form.category_id));
    return cat?.effective_warranty_months ?? null;
});

const submit = () => {
    const payload = (data) => ({
        ...data,
        width_mm: data.width_mm === '' ? null : Number(data.width_mm),
        depth_mm: data.depth_mm === '' ? null : Number(data.depth_mm),
        height_mm: data.height_mm === '' ? null : Number(data.height_mm),
        weight_g: data.weight_g === '' ? null : Number(data.weight_g),
        warranty_months: data.warranty_months === '' ? null : Number(data.warranty_months),
    });

    if (isEditing.value) {
        form.transform(payload).put(route('products.update', props.product.id), { preserveScroll: true });
    } else {
        form.transform(payload).post(route('products.store'));
    }
};

const destroyProduct = () => {
    if (confirm(`Move "${props.product.name}" to trash?`)) {
        router.delete(route('products.destroy', props.product.id));
    }
};

const restoreProduct = () => {
    router.post(route('products.restore', props.product.id));
};

// Image uploads & drag-and-drop
const imageDragging = ref(false);
const imageUploadError = ref(null);
const imageVariantId = ref('');

const uploadFiles = (fileList) => {
    const files = Array.from(fileList ?? []);
    if (!files.length || !isEditing.value) return;

    for (const file of files) {
        const err = uploadTooLarge(file);
        if (err) {
            imageUploadError.value = err;
            return;
        }
    }

    imageUploadError.value = null;
    router.post(
        route('products.images.store', props.product.id),
        {
            images: files,
            product_variant_id: imageVariantId.value || null,
        },
        { forceFormData: true, preserveScroll: true },
    );
};

const onDropFiles = (event) => {
    imageDragging.value = false;
    uploadFiles(event.dataTransfer?.files);
};

const reorderImages = (reordered) => {
    router.post(
        route('products.images.reorder', props.product.id),
        { ids: reordered.map((img) => img.id) },
        { preserveScroll: true },
    );
};

const updateImage = (image, changes) => {
    router.patch(
        route('products.images.update', [props.product.id, image.id]),
        { alt: image.alt, product_variant_id: image.variant_id, ...changes },
        { preserveScroll: true },
    );
};

const deleteImage = (image) => {
    router.delete(route('products.images.destroy', [props.product.id, image.id]), { preserveScroll: true });
};

// Supplier modal per variant
const supplierModalIndex = ref(null);
const activeSupplierVariant = computed(() =>
    supplierModalIndex.value !== null ? form.variants[supplierModalIndex.value] : null,
);

const addSupplierRow = () => {
    if (!activeSupplierVariant.value || !props.suppliers.length) return;
    activeSupplierVariant.value.suppliers.push({
        supplier_id: props.suppliers[0].id,
        supplier_sku: '',
        last_cost: '',
        is_preferred: activeSupplierVariant.value.suppliers.length === 0,
    });
};

// Price history modal
const priceLogModalOpen = ref(false);
</script>

<template>
    <AdminLayout :title="isEditing ? product.name : 'Add product'">
        <CatalogNav>
            <Link
                v-if="isEditing"
                :href="route('products.labels', { product_ids: [product.id] })"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                <Icon name="printer" class="size-4" />
                Print labels
            </Link>
            <button
                v-if="isEditing && priceLogs.length"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
                @click="priceLogModalOpen = true"
            >
                <Icon name="clock" class="size-4" />
                Price history ({{ priceLogs.length }})
            </button>
        </CatalogNav>

        <div
            v-if="product?.deleted_at"
            class="mb-5 flex items-center justify-between rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
        >
            <span>This product is in the trash.</span>
            <SecondaryButton v-if="canEdit" @click="restoreProduct">Restore product</SecondaryButton>
        </div>

        <!-- Section tabs -->
        <div class="mb-6 flex flex-wrap gap-2">
            <button
                v-for="[key, label] in [
                    ['details', 'Details'],
                    ['variants', `Variants & pricing (${form.variants.length})`],
                    ['images', `Photos (${product?.images?.length ?? 0})`],
                    ['dimensions', 'Dimensions & warranty'],
                    ['online', 'Online & SEO'],
                ]"
                :key="key"
                type="button"
                class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors"
                :class="activeTab === key ? 'bg-brand-600 text-white shadow-xs' : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'"
                @click="activeTab = key"
            >
                {{ label }}
            </button>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- TAB 1: DETAILS -->
            <div v-show="activeTab === 'details'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Product details" class="lg:col-span-2">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel for="name" value="Product name" />
                            <TextInput id="name" v-model="form.name" :disabled="!canEdit" class="mt-1 block w-full" required placeholder="Roma 3 Seater Sofa" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="category_id" value="Category" />
                            <SelectInput id="category_id" v-model="form.category_id" :disabled="!canEdit" class="mt-1 block w-full" required>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.path }}</option>
                            </SelectInput>
                            <InputError class="mt-1" :message="form.errors.category_id" />
                        </div>

                        <div>
                            <InputLabel for="type" value="Product type" />
                            <SelectInput id="type" v-model="form.type" :disabled="!canEdit" class="mt-1 block w-full">
                                <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </SelectInput>
                            <InputError class="mt-1" :message="form.errors.type" />
                        </div>

                        <div>
                            <InputLabel for="unit" value="Unit of measure" />
                            <SelectInput id="unit" v-model="form.unit" :disabled="!canEdit" class="mt-1 block w-full">
                                <option value="pcs">Pieces (pcs)</option>
                                <option value="m">Metres (m)</option>
                                <option value="sqft">Square feet (sqft)</option>
                                <option value="sheet">Sheets (sheet)</option>
                                <option value="roll">Rolls (roll)</option>
                                <option value="kg">Kilograms (kg)</option>
                                <option value="l">Litres (l)</option>
                            </SelectInput>
                            <InputError class="mt-1" :message="form.errors.unit" />
                        </div>

                        <div>
                            <InputLabel for="brand" value="Brand" />
                            <TextInput id="brand" v-model="form.brand" :disabled="!canEdit" class="mt-1 block w-full" placeholder="Damro" />
                            <InputError class="mt-1" :message="form.errors.brand" />
                        </div>

                        <div>
                            <InputLabel for="slug" value="URL slug (optional)" />
                            <TextInput id="slug" v-model="form.slug" :disabled="!canEdit" class="mt-1 block w-full font-mono text-sm" placeholder="Auto-generated from name" />
                            <InputError class="mt-1" :message="form.errors.slug" />
                        </div>

                        <div class="sm:col-span-2">
                            <InputLabel value="Description" />
                            <RichTextEditor v-model="form.description" :disabled="!canEdit" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.description" />
                        </div>

                        <div class="sm:col-span-2">
                            <InputLabel value="Tags" />
                            <TagInput v-model="form.tags" :suggestions="tagSuggestions" placeholder="Add tag and press Enter…" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.tags" />
                        </div>
                    </div>
                </Card>

                <Card title="Status & tracking" class="space-y-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Active</p>
                            <p class="text-xs text-gray-500">Inactive products are hidden from POS and new orders.</p>
                        </div>
                        <Toggle v-model="form.is_active" :disabled="!canEdit" />
                    </div>

                    <div class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Serialized item</p>
                            <p class="text-xs text-gray-500">Track each individual piece by its own serial number when received and sold.</p>
                        </div>
                        <Toggle v-model="form.is_serialized" :disabled="!canEdit" />
                    </div>

                    <div class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Publish online</p>
                            <p class="text-xs text-gray-500">Show this product on the online storefront.</p>
                        </div>
                        <Toggle v-model="form.publish_online" :disabled="!canEdit" />
                    </div>
                </Card>
            </div>

            <!-- TAB 2: VARIANTS & PRICING -->
            <div v-show="activeTab === 'variants'" class="space-y-6">
                <Card
                    v-if="canEdit"
                    title="Options & variant generator"
                    description="Leave options unticked for a standard single-SKU product, or pick options (e.g. Colour × Size) to generate all combinations."
                >
                    <div class="space-y-4">
                        <div class="flex flex-wrap gap-3">
                            <label
                                v-for="attr in attributes"
                                :key="attr.id"
                                class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                                :class="form.attribute_ids.includes(attr.id) ? 'border-brand-500 bg-brand-50 text-brand-900' : 'border-gray-300 bg-white text-gray-700'"
                            >
                                <Checkbox
                                    :checked="form.attribute_ids.includes(attr.id)"
                                    @update:checked="(checked) => toggleAttributeAxis(attr.id, checked)"
                                />
                                <span>{{ attr.name }}</span>
                            </label>
                        </div>

                        <div v-for="attrId in form.attribute_ids" :key="attrId" class="rounded-lg border border-gray-200 bg-gray-50/70 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">
                                {{ attributes.find((a) => a.id === attrId)?.name }} values
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label
                                    v-for="val in attributes.find((a) => a.id === attrId)?.values ?? []"
                                    :key="val.id"
                                    class="inline-flex items-center gap-2 rounded-md border bg-white px-2.5 py-1 text-xs font-medium shadow-2xs"
                                    :class="pickedValues[attrId]?.includes(val.id) ? 'border-brand-500 ring-1 ring-brand-500' : 'border-gray-200'"
                                >
                                    <Checkbox
                                        :checked="pickedValues[attrId]?.includes(val.id)"
                                        @update:checked="(checked) => togglePickedValue(attrId, val.id, checked)"
                                    />
                                    <span
                                        v-if="val.swatch_color"
                                        class="size-3 rounded-full border border-black/10"
                                        :style="{ backgroundColor: val.swatch_color }"
                                    />
                                    <span>{{ val.value }}</span>
                                    <span class="font-mono text-[10px] text-gray-400">({{ val.code }})</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-3 pt-1">
                            <div>
                                <InputLabel for="base_sku" value="Base SKU prefix" />
                                <TextInput id="base_sku" v-model="baseSku" class="mt-1 w-44 font-mono uppercase" placeholder="SOFA-ROMA" />
                            </div>
                            <SecondaryButton type="button" :disabled="generating" @click="generateCombinations">
                                <Icon name="sparkle" class="mr-1.5 size-4" />
                                Generate combinations
                            </SecondaryButton>
                            <SecondaryButton v-if="form.variants.length > 1" type="button" @click="copyFirstPricesToAll">
                                Copy row 1 prices to all
                            </SecondaryButton>
                        </div>

                        <InputError :message="generatorError ?? form.errors.variants" />
                    </div>
                </Card>

                <Card title="Variants" description="Selling below the minimum price requires a manager override. Leave barcode blank to generate a Code 128 barcode." flush>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-3 py-3">Option</th>
                                    <th class="px-3 py-3">SKU</th>
                                    <th class="px-3 py-3">Barcode</th>
                                    <th class="px-3 py-3">Price</th>
                                    <th class="px-3 py-3">Min price</th>
                                    <th class="px-3 py-3">Online price</th>
                                    <th class="px-3 py-3">Reorder level / qty</th>
                                    <th v-if="canViewCost" class="px-3 py-3">Cost & suppliers</th>
                                    <th class="px-3 py-3">Active</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white align-top">
                                <tr v-for="(variant, idx) in form.variants" :key="variant.combination_key || idx">
                                    <td class="whitespace-nowrap px-3 py-3 font-medium text-gray-900">
                                        {{ variant.name || 'Default' }}
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.value_ids`]" />
                                    </td>
                                    <td class="min-w-40 px-3 py-3">
                                        <TextInput v-model="variant.sku" :disabled="!canEdit" class="w-full font-mono text-xs uppercase" placeholder="SKU-001" required />
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.sku`]" />
                                    </td>
                                    <td class="min-w-40 px-3 py-3">
                                        <TextInput v-model="variant.barcode" :disabled="!canEdit" class="w-full font-mono text-xs" placeholder="Auto Code 128" />
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.barcode`]" />
                                    </td>
                                    <td class="min-w-44 px-3 py-3">
                                        <MoneyInput v-model="variant.price" :disabled="!canEdit" />
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.price`]" />
                                    </td>
                                    <td class="min-w-44 px-3 py-3">
                                        <MoneyInput v-model="variant.min_price" :disabled="!canEdit" />
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.min_price`]" />
                                    </td>
                                    <td class="min-w-44 px-3 py-3">
                                        <MoneyInput v-model="variant.online_price" :disabled="!canEdit" placeholder="Same as price" />
                                        <InputError class="mt-1" :message="form.errors[`variants.${idx}.online_price`]" />
                                    </td>
                                    <td class="min-w-36 px-3 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <TextInput v-model="variant.reorder_level" :disabled="!canEdit" class="w-16 text-xs" title="Reorder level" />
                                            <span class="text-gray-400">/</span>
                                            <TextInput v-model="variant.reorder_qty" :disabled="!canEdit" class="w-16 text-xs" title="Reorder quantity" />
                                        </div>
                                    </td>
                                    <td v-if="canViewCost" class="whitespace-nowrap px-3 py-3 text-xs text-gray-600">
                                        <div>Avg: <strong class="text-gray-900">{{ formatMoney(variant.average_cost ?? 0) }}</strong></div>
                                        <div v-if="variant.last_cost != null">Last: {{ formatMoney(variant.last_cost) }}</div>
                                        <button
                                            v-if="canEdit && suppliers.length"
                                            type="button"
                                            class="mt-1 font-semibold text-brand-600 hover:text-brand-500"
                                            @click="supplierModalIndex = idx"
                                        >
                                            Suppliers ({{ variant.suppliers?.length ?? 0 }})
                                        </button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <Toggle v-model="variant.is_active" :disabled="!canEdit" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            <!-- TAB 3: PHOTOS -->
            <div v-show="activeTab === 'images'">
                <Card v-if="!isEditing" title="Product photos">
                    <p class="text-sm text-gray-500">Save the product first, then drop photos here to upload and reorder them.</p>
                </Card>

                <Card v-else title="Product photos" description="Converted to WebP + thumbnail automatically. Drag to reorder; the first photo is the cover.">
                    <div
                        v-if="canEdit"
                        class="mb-5 flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center transition-colors"
                        :class="imageDragging ? 'border-brand-500 bg-brand-50' : 'border-gray-300 bg-gray-50/60'"
                        @dragover.prevent="imageDragging = true"
                        @dragleave.prevent="imageDragging = false"
                        @drop.prevent="onDropFiles"
                    >
                        <Icon name="photo" class="size-8 text-gray-400" />
                        <p class="mt-2 text-sm font-medium text-gray-900">
                            Drag and drop photos here, or
                            <label class="cursor-pointer font-semibold text-brand-600 hover:text-brand-500">
                                browse files
                                <input type="file" multiple accept="image/png,image/jpeg,image/webp" class="sr-only" @change="(e) => uploadFiles(e.target.files)" />
                            </label>
                        </p>
                        <p class="mt-1 text-xs text-gray-500">JPG, PNG or WebP up to {{ describeBytes(maxUploadBytes()) }} each.</p>
                        <InputError class="mt-2" :message="imageUploadError" />
                    </div>

                    <p v-if="!product.images?.length" class="text-sm text-gray-500">No photos uploaded yet.</p>

                    <SortableList
                        v-else
                        :items="product.images"
                        :disabled="!canEdit"
                        tag="div"
                        item-tag="div"
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                        @reorder="reorderImages"
                    >
                        <template #default="{ item: img, index }">
                            <div class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                                <div class="relative aspect-4/3 bg-gray-100">
                                    <img :src="img.thumbnail_url" :alt="img.alt || product.name" class="size-full object-cover" />
                                    <Badge v-if="index === 0" tone="blue" class="absolute left-2 top-2">Cover</Badge>
                                </div>
                                <div class="flex flex-1 flex-col gap-2 p-3">
                                    <div class="flex items-center gap-2">
                                        <DragHandle v-if="canEdit" />
                                        <SelectInput
                                            :model-value="img.variant_id ?? ''"
                                            :disabled="!canEdit"
                                            class="flex-1 text-xs"
                                            @update:model-value="(val) => updateImage(img, { product_variant_id: val || null })"
                                        >
                                            <option value="">All variants</option>
                                            <option v-for="v in product.variants" :key="v.id" :value="v.id">
                                                {{ v.name || v.sku }}
                                            </option>
                                        </SelectInput>
                                        <button
                                            v-if="canEdit"
                                            type="button"
                                            class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                            title="Delete photo"
                                            @click="deleteImage(img)"
                                        >
                                            <Icon name="trash" class="size-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </SortableList>
                </Card>
            </div>

            <!-- TAB 4: DIMENSIONS & WARRANTY -->
            <div v-show="activeTab === 'dimensions'">
                <Card title="Dimensions, delivery & warranty" description="Used on price tags, delivery scheduling and warranty certificates.">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
                        <div>
                            <InputLabel for="width_mm" value="Width (mm)" />
                            <TextInput id="width_mm" v-model="form.width_mm" :disabled="!canEdit" type="number" min="0" class="mt-1 block w-full" placeholder="2100" />
                            <InputError class="mt-1" :message="form.errors.width_mm" />
                        </div>
                        <div>
                            <InputLabel for="depth_mm" value="Depth (mm)" />
                            <TextInput id="depth_mm" v-model="form.depth_mm" :disabled="!canEdit" type="number" min="0" class="mt-1 block w-full" placeholder="900" />
                            <InputError class="mt-1" :message="form.errors.depth_mm" />
                        </div>
                        <div>
                            <InputLabel for="height_mm" value="Height (mm)" />
                            <TextInput id="height_mm" v-model="form.height_mm" :disabled="!canEdit" type="number" min="0" class="mt-1 block w-full" placeholder="850" />
                            <InputError class="mt-1" :message="form.errors.height_mm" />
                        </div>
                        <div>
                            <InputLabel for="weight_g" value="Weight (g)" />
                            <TextInput id="weight_g" v-model="form.weight_g" :disabled="!canEdit" type="number" min="0" class="mt-1 block w-full" placeholder="45000" />
                            <InputError class="mt-1" :message="form.errors.weight_g" />
                        </div>

                        <div class="sm:col-span-2">
                            <InputLabel for="warranty_months" value="Warranty (months)" />
                            <TextInput
                                id="warranty_months"
                                v-model="form.warranty_months"
                                :disabled="!canEdit"
                                type="number"
                                min="0"
                                max="600"
                                class="mt-1 block w-full"
                                :placeholder="selectedCategoryWarranty !== null ? `Category default (${selectedCategoryWarranty} months)` : 'None'"
                            />
                            <InputError class="mt-1" :message="form.errors.warranty_months" />
                        </div>

                        <div class="flex items-center justify-between gap-4 sm:col-span-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Requires delivery (bulky item)</p>
                                <p class="text-xs text-gray-500">Prompts for delivery address and date at checkout.</p>
                            </div>
                            <Toggle v-model="form.requires_delivery" :disabled="!canEdit" />
                        </div>

                        <div class="flex items-center justify-between gap-4 sm:col-span-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Assembly required</p>
                                <p class="text-xs text-gray-500">Flags delivery teams that on-site assembly is needed.</p>
                            </div>
                            <Toggle v-model="form.assembly_required" :disabled="!canEdit" />
                        </div>
                    </div>
                </Card>
            </div>

            <!-- TAB 5: ONLINE & SEO -->
            <div v-show="activeTab === 'online'">
                <Card title="Online storefront & SEO">
                    <div class="space-y-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Publish online</p>
                                <p class="text-xs text-gray-500">Make this product visible on the public website.</p>
                            </div>
                            <Toggle v-model="form.publish_online" :disabled="!canEdit" />
                        </div>

                        <div>
                            <InputLabel for="seo_title" value="SEO title" />
                            <TextInput id="seo_title" v-model="form.seo_title" :disabled="!canEdit" class="mt-1 block w-full" :placeholder="form.name || 'Product title'" />
                            <InputError class="mt-1" :message="form.errors.seo_title" />
                        </div>

                        <div>
                            <InputLabel for="seo_description" value="SEO description" />
                            <textarea
                                id="seo_description"
                                v-model="form.seo_description"
                                :disabled="!canEdit"
                                rows="3"
                                maxlength="320"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500"
                            />
                            <InputError class="mt-1" :message="form.errors.seo_description" />
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Footer actions -->
            <div v-if="canEdit" class="flex items-center justify-between pt-2">
                <div>
                    <DangerButton v-if="isEditing && !product.deleted_at" type="button" @click="destroyProduct">
                        Move to trash
                    </DangerButton>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('products.index')" class="text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</Link>
                    <PrimaryButton :disabled="form.processing">
                        {{ isEditing ? 'Save product' : 'Create product' }}
                    </PrimaryButton>
                </div>
            </div>
        </form>

        <!-- Preferred Suppliers Modal -->
        <Modal :show="supplierModalIndex !== null" max-width="lg" @close="supplierModalIndex = null">
            <div v-if="activeSupplierVariant" class="p-6">
                <h2 class="text-base font-semibold text-gray-900">
                    Suppliers for {{ activeSupplierVariant.sku }}
                </h2>
                <div class="mt-4 space-y-3">
                    <div
                        v-for="(sup, sIdx) in activeSupplierVariant.suppliers"
                        :key="sIdx"
                        class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-3"
                    >
                        <div>
                            <InputLabel value="Supplier" />
                            <SelectInput v-model="sup.supplier_id" class="mt-1 block w-full text-xs">
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Supplier SKU" />
                            <TextInput v-model="sup.supplier_sku" class="mt-1 block w-full text-xs" placeholder="Optional" />
                        </div>
                        <div>
                            <InputLabel value="Last cost" />
                            <MoneyInput v-model="sup.last_cost" class="mt-1" />
                        </div>
                        <div class="flex items-center justify-between sm:col-span-3">
                            <label class="inline-flex items-center gap-2 text-xs text-gray-700">
                                <Checkbox v-model:checked="sup.is_preferred" />
                                <span>Preferred supplier</span>
                            </label>
                            <button
                                type="button"
                                class="text-xs font-semibold text-red-600 hover:text-red-500"
                                @click="activeSupplierVariant.suppliers.splice(sIdx, 1)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                    <SecondaryButton type="button" @click="addSupplierRow">+ Add supplier</SecondaryButton>
                </div>
                <div class="mt-6 flex justify-end">
                    <PrimaryButton type="button" @click="supplierModalIndex = null">Done</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Price Change History Modal -->
        <Modal :show="priceLogModalOpen" max-width="2xl" @close="priceLogModalOpen = false">
            <div class="p-6">
                <h2 class="text-base font-semibold text-gray-900">Price change history</h2>
                <div class="mt-4 max-h-96 overflow-y-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                        <thead class="bg-gray-50 text-left font-semibold text-gray-600">
                            <tr>
                                <th class="px-3 py-2">When</th>
                                <th class="px-3 py-2">Who</th>
                                <th class="px-3 py-2">Variant</th>
                                <th class="px-3 py-2">Field</th>
                                <th class="px-3 py-2">Change</th>
                                <th class="px-3 py-2">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="log in priceLogs" :key="log.id">
                                <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ log.created_at }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ log.user }}</td>
                                <td class="px-3 py-2 font-mono">{{ log.sku }}</td>
                                <td class="px-3 py-2">{{ log.field }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="log.old_value !== null">{{ formatMoney(log.old_value) }} → </span>
                                    <strong>{{ log.new_value !== null ? formatMoney(log.new_value) : '—' }}</strong>
                                </td>
                                <td class="px-3 py-2 text-gray-500">
                                    {{ log.source }}{{ log.note ? ` (${log.note})` : '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-5 flex justify-end">
                    <SecondaryButton @click="priceLogModalOpen = false">Close</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>

