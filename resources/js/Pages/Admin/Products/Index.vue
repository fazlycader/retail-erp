<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { postJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    products: Object,
    filters: Object,
    categories: Array,
    types: Array,
    canEdit: Boolean,
    canViewCost: Boolean,
});

const viewMode = ref('list');
const selectedIds = ref([]);

const filterForm = reactive({
    search: props.filters?.search ?? '',
    category_id: props.filters?.category_id ?? '',
    type: props.filters?.type ?? '',
    status: props.filters?.status ?? '',
    online: props.filters?.online ?? '',
    low_stock: Boolean(props.filters?.low_stock),
});

let searchTimer;
const applyFilters = () => {
    const query = Object.fromEntries(
        Object.entries({
            ...filterForm,
            low_stock: filterForm.low_stock ? 1 : '',
        }).filter(([, v]) => v !== '' && v !== null && v !== false),
    );

    router.get(route('products.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(
    () => filterForm.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 250);
    },
);

watch(
    () => [filterForm.category_id, filterForm.type, filterForm.status, filterForm.online, filterForm.low_stock],
    applyFilters,
);

const clearFilters = () => {
    filterForm.search = '';
    filterForm.category_id = '';
    filterForm.type = '';
    filterForm.status = '';
    filterForm.online = '';
    filterForm.low_stock = false;
};

const allSelected = computed(
    () => props.products.data.length > 0 && props.products.data.every((p) => selectedIds.value.includes(p.id)),
);

const toggleAll = (checked) => {
    selectedIds.value = checked ? props.products.data.map((p) => p.id) : [];
};

const toggleOne = (id, checked) => {
    selectedIds.value = checked
        ? [...new Set([...selectedIds.value, id])]
        : selectedIds.value.filter((x) => x !== id);
};

const priceSummary = (product) => {
    if (product.min_variant_price === product.max_variant_price) {
        return formatMoney(product.min_variant_price);
    }
    return `${formatMoney(product.min_variant_price)} – ${formatMoney(product.max_variant_price)}`;
};

const runBulk = (action, extra = {}) => {
    router.post(
        route('products.bulk'),
        { action, product_ids: selectedIds.value, ...extra },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = [];
                categoryModalOpen.value = false;
                priceModalOpen.value = false;
            },
        },
    );
};

// Move category modal
const categoryModalOpen = ref(false);
const bulkCategoryId = ref('');

// Bulk price % change modal with preview
const priceModalOpen = ref(false);
const bulkPercent = ref('5');
const bulkFields = ref(['price', 'min_price']);
const bulkRoundTo = ref(100);
const pricePreview = ref(null);
const priceError = ref(null);
const previewLoading = ref(false);

const loadPricePreview = async () => {
    priceError.value = null;
    pricePreview.value = null;

    if (!bulkPercent.value || bulkFields.value.length === 0) {
        return;
    }

    previewLoading.value = true;
    const { ok, data } = await postJson(route('products.bulk-price-preview'), {
        product_ids: selectedIds.value,
        percent: bulkPercent.value,
        fields: bulkFields.value,
        round_to: bulkRoundTo.value,
    });
    previewLoading.value = false;

    if (!ok) {
        priceError.value = data.message ?? 'Check the percentage and try again.';
        return;
    }

    pricePreview.value = data;
};

const openPriceModal = () => {
    priceModalOpen.value = true;
    loadPricePreview();
};
</script>

<template>
    <AdminLayout title="Products">
        <CatalogNav>
            <Link
                :href="route('products.labels', selectedIds.length ? { product_ids: selectedIds } : {})"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                <Icon name="printer" class="size-4" />
                Print labels
            </Link>
            <Link
                v-if="canEdit"
                :href="route('products.create')"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-brand-500"
            >
                <Icon name="plus" class="size-4" />
                Add product
            </Link>
        </CatalogNav>

        <!-- Search & filters -->
        <Card class="mb-5">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div class="relative sm:col-span-2">
                    <Icon name="search" class="pointer-events-none absolute left-3 top-2.5 size-5 text-gray-400" />
                    <TextInput
                        v-model="filterForm.search"
                        type="search"
                        placeholder="Search by name, SKU or barcode…"
                        class="block w-full pl-10"
                    />
                </div>

                <SelectInput v-model="filterForm.category_id">
                    <option value="">All categories</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">
                        {{ '— '.repeat(Math.max(0, c.depth - 1)) + c.name }}
                    </option>
                </SelectInput>

                <SelectInput v-model="filterForm.type">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </SelectInput>

                <SelectInput v-model="filterForm.status">
                    <option value="">Active & inactive</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                    <option value="trashed">Trash</option>
                </SelectInput>

                <div class="flex items-center gap-2">
                    <SelectInput v-model="filterForm.online" class="flex-1">
                        <option value="">Online: all</option>
                        <option value="yes">Published online</option>
                        <option value="no">In-store only</option>
                    </SelectInput>
                    <div class="inline-flex rounded-lg border border-gray-300 bg-white p-0.5">
                        <button
                            type="button"
                            class="rounded-md p-1.5"
                            :class="viewMode === 'list' ? 'bg-slate-900 text-white' : 'text-gray-500 hover:text-gray-900'"
                            title="List view"
                            @click="viewMode = 'list'"
                        >
                            <Icon name="list" class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5"
                            :class="viewMode === 'grid' ? 'bg-slate-900 text-white' : 'text-gray-500 hover:text-gray-900'"
                            title="Grid view"
                            @click="viewMode = 'grid'"
                        >
                            <Icon name="map" class="size-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
                <label class="inline-flex items-center gap-2">
                    <Checkbox v-model:checked="filterForm.low_stock" />
                    <span>Low stock (at or below reorder level)</span>
                </label>
                <button
                    v-if="filterForm.search || filterForm.category_id || filterForm.type || filterForm.status || filterForm.online || filterForm.low_stock"
                    type="button"
                    class="font-semibold text-brand-600 hover:text-brand-500"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>
        </Card>

        <!-- Bulk actions bar -->
        <div
            v-if="canEdit && selectedIds.length"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-sm"
        >
            <span class="font-medium">{{ selectedIds.length }} product(s) selected</span>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20" @click="runBulk('activate')">
                    Activate
                </button>
                <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20" @click="runBulk('deactivate')">
                    Deactivate
                </button>
                <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20" @click="runBulk('publish_online')">
                    Publish online
                </button>
                <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20" @click="runBulk('unpublish_online')">
                    Unpublish
                </button>
                <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20" @click="categoryModalOpen = true">
                    Change category…
                </button>
                <button type="button" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold hover:bg-brand-400" @click="openPriceModal">
                    Change prices (%)…
                </button>
                <button type="button" class="ml-2 text-xs text-slate-400 hover:text-white" @click="selectedIds = []">Clear</button>
            </div>
        </div>

        <!-- Empty state -->
        <Card v-if="products.data.length === 0" class="py-12 text-center">
            <Icon name="tag" class="mx-auto size-10 text-gray-300" />
            <p class="mt-3 text-sm font-medium text-gray-900">No products found</p>
            <p class="mt-1 text-sm text-gray-500">Try clearing filters or add your first product to the catalog.</p>
        </Card>

        <!-- List view -->
        <Card v-else-if="viewMode === 'list'" flush>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <tr>
                            <th v-if="canEdit" class="w-10 px-4 py-3">
                                <Checkbox :checked="allSelected" @update:checked="toggleAll" />
                            </th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">SKU / Variants</th>
                            <th class="px-4 py-3 text-right">Price</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Avg / Last cost</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="product in products.data" :key="product.id" class="hover:bg-gray-50/70">
                            <td v-if="canEdit" class="px-4 py-3">
                                <Checkbox
                                    :checked="selectedIds.includes(product.id)"
                                    @update:checked="(checked) => toggleOne(product.id, checked)"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                        <img v-if="product.thumbnail_url" :src="product.thumbnail_url" :alt="product.name" class="size-full object-cover" />
                                        <Icon v-else name="photo" class="size-5 text-gray-300" />
                                    </div>
                                    <div class="min-w-0">
                                        <Link :href="route('products.edit', product.id)" class="font-semibold text-gray-900 hover:text-brand-600">
                                            {{ product.name }}
                                        </Link>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                                            <span v-if="product.brand">{{ product.brand }}</span>
                                            <span v-if="product.brand">·</span>
                                            <span>{{ product.type_label }}</span>
                                            <Badge v-if="product.is_serialized" tone="amber">Serialized</Badge>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ product.category_path }}</td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-gray-800">{{ product.first_sku }}</span>
                                <span v-if="product.variants_count > 1" class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                    {{ product.variants_count }} variants
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-gray-900">
                                {{ priceSummary(product) }}
                            </td>
                            <td v-if="canViewCost" class="whitespace-nowrap px-4 py-3 text-right text-xs text-gray-600">
                                <template v-if="product.variants[0]">
                                    <div>Avg: {{ formatMoney(product.variants[0].average_cost ?? 0) }}</div>
                                    <div v-if="product.variants[0].last_cost != null" class="text-gray-400">
                                        Last: {{ formatMoney(product.variants[0].last_cost) }}
                                    </div>
                                </template>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-if="product.deleted_at" tone="red">Deleted</Badge>
                                    <Badge v-else :tone="product.is_active ? 'green' : 'gray'">
                                        {{ product.is_active ? 'Active' : 'Inactive' }}
                                    </Badge>
                                    <Badge v-if="product.publish_online" tone="blue">Online</Badge>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Link
                                    :href="route('products.edit', product.id)"
                                    class="font-semibold text-brand-600 hover:text-brand-500"
                                >
                                    {{ canEdit ? 'Edit' : 'View' }}
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Grid view -->
        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="product in products.data" :key="product.id" flush class="flex flex-col overflow-hidden">
                <div class="relative aspect-4/3 bg-gray-100">
                    <img v-if="product.thumbnail_url" :src="product.thumbnail_url" :alt="product.name" class="size-full object-cover" />
                    <div v-else class="flex size-full items-center justify-center">
                        <Icon name="photo" class="size-10 text-gray-300" />
                    </div>
                    <div v-if="canEdit" class="absolute left-3 top-3 rounded bg-white/90 p-1 shadow-xs">
                        <Checkbox :checked="selectedIds.includes(product.id)" @update:checked="(checked) => toggleOne(product.id, checked)" />
                    </div>
                    <div class="absolute right-3 top-3 flex gap-1">
                        <Badge :tone="product.is_active ? 'green' : 'gray'">{{ product.is_active ? 'Active' : 'Inactive' }}</Badge>
                        <Badge v-if="product.publish_online" tone="blue">Online</Badge>
                    </div>
                </div>
                <div class="flex flex-1 flex-col justify-between p-4">
                    <div>
                        <p class="text-xs text-gray-500">{{ product.category_path }}</p>
                        <Link :href="route('products.edit', product.id)" class="mt-0.5 block font-semibold text-gray-900 hover:text-brand-600">
                            {{ product.name }}
                        </Link>
                        <p class="mt-1 font-mono text-xs text-gray-500">
                            {{ product.first_sku }}
                            <span v-if="product.variants_count > 1">· {{ product.variants_count }} variants</span>
                        </p>
                    </div>
                    <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3">
                        <span class="text-sm font-semibold text-gray-900">{{ priceSummary(product) }}</span>
                        <Link :href="route('products.edit', product.id)" class="text-xs font-semibold text-brand-600 hover:text-brand-500">
                            {{ canEdit ? 'Edit →' : 'View →' }}
                        </Link>
                    </div>
                </div>
            </Card>
        </div>

        <Pagination :links="products.links" class="mt-5" />

        <!-- Change Category Modal -->
        <Modal :show="categoryModalOpen" max-width="md" @close="categoryModalOpen = false">
            <div class="p-6">
                <h2 class="text-base font-semibold text-gray-900">Move {{ selectedIds.length }} product(s) to category</h2>
                <div class="mt-4">
                    <InputLabel for="bulk-category" value="Category" />
                    <SelectInput id="bulk-category" v-model="bulkCategoryId" class="mt-1 block w-full">
                        <option value="">Choose a category…</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.path }}</option>
                    </SelectInput>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="categoryModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="!bulkCategoryId" @click="runBulk('change_category', { category_id: bulkCategoryId })">
                        Move products
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Bulk Price % Change Modal -->
        <Modal :show="priceModalOpen" max-width="2xl" @close="priceModalOpen = false">
            <div class="p-6">
                <h2 class="text-base font-semibold text-gray-900">Change prices by percentage ({{ selectedIds.length }} product(s))</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Use a positive number to increase (e.g. <code>5</code>) or a negative number to reduce (e.g. <code>-10</code>).
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel for="bulk-percent" value="Change (%)" />
                        <TextInput id="bulk-percent" v-model="bulkPercent" class="mt-1 block w-full" placeholder="5" @input="loadPricePreview" />
                    </div>
                    <div>
                        <InputLabel for="bulk-round" value="Round to" />
                        <SelectInput id="bulk-round" v-model="bulkRoundTo" class="mt-1 block w-full" @change="loadPricePreview">
                            <option :value="1">Exact cents</option>
                            <option :value="100">Nearest Rs. 1</option>
                            <option :value="1000">Nearest Rs. 10</option>
                            <option :value="10000">Nearest Rs. 100</option>
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Apply to" />
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2">
                                <input v-model="bulkFields" type="checkbox" value="price" class="rounded border-gray-300 text-brand-600" @change="loadPricePreview" />
                                <span>Selling price</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="bulkFields" type="checkbox" value="min_price" class="rounded border-gray-300 text-brand-600" @change="loadPricePreview" />
                                <span>Minimum price</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="bulkFields" type="checkbox" value="online_price" class="rounded border-gray-300 text-brand-600" @change="loadPricePreview" />
                                <span>Online price</span>
                            </label>
                        </div>
                    </div>
                </div>

                <InputError class="mt-3" :message="priceError" />

                <div v-if="pricePreview" class="mt-4">
                    <div
                        v-if="pricePreview.blocked.length"
                        class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                    >
                        <p class="font-semibold">{{ pricePreview.blocked.length }} variant(s) would violate the minimum price rule:</p>
                        <ul class="mt-1 list-disc pl-4">
                            <li v-for="b in pricePreview.blocked" :key="b.variant_id">
                                {{ b.sku }} ({{ b.product }}): {{ b.problem }}
                            </li>
                        </ul>
                    </div>

                    <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                            <thead class="bg-gray-50 text-left font-semibold text-gray-600">
                                <tr>
                                    <th class="px-3 py-2">Variant</th>
                                    <th class="px-3 py-2">Changes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="row in pricePreview.rows" :key="row.variant_id">
                                    <td class="px-3 py-2">
                                        <span class="font-mono font-medium text-gray-900">{{ row.sku }}</span>
                                        <span class="ml-1.5 text-gray-500">{{ row.product }} {{ row.variant ? `(${row.variant})` : '' }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span v-for="(change, field) in row.changes" :key="field" class="mr-3 inline-block">
                                            <span class="text-gray-500">{{ field }}:</span>
                                            {{ formatMoney(change.old) }} → <strong>{{ formatMoney(change.new) }}</strong>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="priceModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton
                        :disabled="previewLoading || !pricePreview || pricePreview.blocked.length > 0 || pricePreview.count === 0"
                        @click="runBulk('price_change', { percent: bulkPercent, fields: bulkFields, round_to: bulkRoundTo })"
                    >
                        Apply to {{ pricePreview?.count ?? 0 }} variant(s)
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>

