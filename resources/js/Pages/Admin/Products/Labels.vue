<script setup>
import Card from '@/Components/Card.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Icon from '@/Components/Icon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson } from '@/lib/http';
import { formatPrice } from '@/lib/money';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    initialVariants: Array,
});

const companyName = computed(() => usePage().props.company?.name ?? '');

const items = ref(
    (props.initialVariants ?? []).map((v) => ({
        ...v,
        copies: v.copies ?? 1,
    })),
);

const layout = ref('a4'); // 'a4' (3x8 = 24 per A4 sheet) | 'thermal' (50x25 mm)
const showPrice = ref(true);
const showSku = ref(true);
const showDimensions = ref(true);
const showCompany = ref(false);

// Search to add variants
const searchQuery = ref('');
const searchResults = ref([]);
let searchTimer;

watch(searchQuery, (q) => {
    clearTimeout(searchTimer);
    if (!q.trim()) {
        searchResults.value = [];
        return;
    }
    searchTimer = setTimeout(async () => {
        const { ok, data } = await getJson(route('products.labels.search', { q: q.trim() }));
        if (ok) {
            searchResults.value = data.variants ?? [];
        }
    }, 200);
});

const addVariant = (variant) => {
    const existing = items.value.find((i) => i.id === variant.id);
    if (existing) {
        existing.copies += 1;
    } else {
        items.value.push({ ...variant, copies: 1 });
    }
    searchQuery.value = '';
    searchResults.value = [];
};

const removeVariant = (index) => {
    items.value.splice(index, 1);
};

// Expanded list of individual labels according to copies
const expandedLabels = computed(() => {
    const out = [];
    for (const item of items.value) {
        const count = Math.max(0, Math.min(200, Number(item.copies) || 0));
        for (let i = 0; i < count; i++) {
            out.push(item);
        }
    }
    return out;
});

// Chunk into pages of 24 for A4 3x8
const a4Pages = computed(() => {
    const pages = [];
    for (let i = 0; i < expandedLabels.value.length; i += 24) {
        pages.push(expandedLabels.value.slice(i, i + 24));
    }
    return pages;
});

const printLabels = () => {
    window.print();
};
</script>

<template>
    <AdminLayout title="Print price & barcode labels">
        <CatalogNav>
            <PrimaryButton :disabled="expandedLabels.length === 0" @click="printLabels">
                <Icon name="printer" class="mr-1.5 size-4" />
                Print {{ expandedLabels.length }} label(s)
            </PrimaryButton>
        </CatalogNav>

        <!-- Controls (hidden when printing) -->
        <div class="mb-6 grid grid-cols-1 gap-6 print:hidden lg:grid-cols-3">
            <Card title="Variants & copies" class="lg:col-span-2">
                <div class="relative mb-4">
                    <Icon name="search" class="pointer-events-none absolute left-3 top-2.5 size-5 text-gray-400" />
                    <TextInput
                        v-model="searchQuery"
                        type="search"
                        class="block w-full pl-10"
                        placeholder="Search variant by name, SKU or barcode to add…"
                    />
                    <div
                        v-if="searchResults.length"
                        class="absolute z-20 mt-1 max-h-64 w-full divide-y divide-gray-100 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-lg"
                    >
                        <button
                            v-for="res in searchResults"
                            :key="res.id"
                            type="button"
                            class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-gray-50"
                            @click="addVariant(res)"
                        >
                            <div>
                                <span class="font-medium text-gray-900">{{ res.product_name }}</span>
                                <span v-if="res.variant_name" class="ml-1.5 text-gray-500">({{ res.variant_name }})</span>
                                <span class="ml-2 font-mono text-xs text-gray-400">{{ res.sku }}</span>
                            </div>
                            <span class="font-semibold text-gray-900">{{ formatPrice(res.price) }}</span>
                        </button>
                    </div>
                </div>

                <p v-if="!items.length" class="text-sm text-gray-500">Search above to add variants to print.</p>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Product / Variant</th>
                                <th class="px-3 py-2">SKU / Barcode</th>
                                <th class="px-3 py-2 text-right">Price</th>
                                <th class="w-28 px-3 py-2 text-center">Copies</th>
                                <th class="w-10 px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(item, idx) in items" :key="item.id">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-gray-900">{{ item.product_name }}</div>
                                    <div v-if="item.variant_name" class="text-xs text-gray-500">{{ item.variant_name }}</div>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-600">
                                    <div>{{ item.sku }}</div>
                                    <div class="text-gray-400">{{ item.barcode }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-medium text-gray-900">
                                    {{ formatPrice(item.price) }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <TextInput v-model.number="item.copies" type="number" min="0" max="200" class="w-20 text-center text-sm" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" class="text-gray-400 hover:text-red-600" @click="removeVariant(idx)">
                                        <Icon name="trash" class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Label layout">
                <div class="space-y-4">
                    <div>
                        <InputLabel for="label-layout" value="Paper size" />
                        <SelectInput id="label-layout" v-model="layout" class="mt-1 block w-full">
                            <option value="a4">A4 sheet — 3 × 8 (24 labels)</option>
                            <option value="thermal">50 × 25 mm thermal label</option>
                        </SelectInput>
                    </div>

                    <div class="space-y-2 border-t border-gray-100 pt-3 text-sm">
                        <label class="flex items-center gap-2">
                            <Checkbox v-model:checked="showPrice" />
                            <span>Show price</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <Checkbox v-model:checked="showSku" />
                            <span>Show SKU</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <Checkbox v-model:checked="showDimensions" />
                            <span>Show dimensions (W×D×H)</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <Checkbox v-model:checked="showCompany" />
                            <span>Show store name</span>
                        </label>
                    </div>

                    <div class="border-t border-gray-100 pt-3">
                        <SecondaryButton class="w-full justify-center" @click="items.forEach((i) => (i.copies = 1))">
                            Reset all to 1 copy
                        </SecondaryButton>
                    </div>
                </div>
            </Card>
        </div>

        <!-- PRINT PREVIEW / PRINTABLE AREA -->
        <div class="label-print-area">
            <!-- Layout 1: A4 3x8 sheet -->
            <template v-if="layout === 'a4'">
                <div
                    v-for="(pageLabels, pIdx) in a4Pages"
                    :key="pIdx"
                    class="a4-sheet mx-auto mb-6 grid grid-cols-3 gap-0 rounded-lg border border-gray-300 bg-white shadow-xs print:mb-0 print:rounded-none print:border-0 print:shadow-none"
                >
                    <div
                        v-for="(label, lIdx) in pageLabels"
                        :key="lIdx"
                        class="a4-cell flex flex-col justify-between overflow-hidden border border-dashed border-gray-200 p-2 text-center print:border-0"
                    >
                        <div>
                            <p v-if="showCompany" class="truncate text-[9px] font-semibold uppercase tracking-wider text-gray-500">
                                {{ companyName }}
                            </p>
                            <p class="truncate text-xs font-bold leading-tight text-gray-900">{{ label.product_name }}</p>
                            <p v-if="label.variant_name" class="truncate text-[10px] text-gray-600">{{ label.variant_name }}</p>
                            <p v-if="showDimensions && label.dimensions" class="truncate text-[9px] text-gray-500">{{ label.dimensions }}</p>
                        </div>

                        <div class="my-1 flex flex-col items-center">
                            <!-- Code 128 SVG generated server-side from Picqer Barcode -->
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div class="barcode-svg max-w-full overflow-hidden" v-html="label.barcode_svg" />
                            <div class="mt-0.5 flex items-center justify-center gap-2 font-mono text-[9px] text-gray-700">
                                <span>{{ label.barcode }}</span>
                                <span v-if="showSku">· {{ label.sku }}</span>
                            </div>
                        </div>

                        <div v-if="showPrice" class="text-sm font-extrabold text-gray-900">
                            {{ formatPrice(label.price) }}
                        </div>
                    </div>
                </div>
            </template>

            <!-- Layout 2: 50x25 mm thermal roll -->
            <template v-else>
                <div class="flex flex-wrap gap-4 print:block">
                    <div
                        v-for="(label, idx) in expandedLabels"
                        :key="idx"
                        class="thermal-label flex flex-col justify-between overflow-hidden rounded border border-gray-300 bg-white p-1.5 text-center shadow-2xs print:rounded-none print:border-0 print:shadow-none"
                    >
                        <div>
                            <p v-if="showCompany" class="truncate text-[7px] font-semibold uppercase text-gray-500">{{ companyName }}</p>
                            <p class="truncate text-[10px] font-bold leading-tight text-gray-900">{{ label.product_name }}</p>
                            <p v-if="label.variant_name" class="truncate text-[8px] leading-tight text-gray-600">{{ label.variant_name }}</p>
                        </div>

                        <div class="flex flex-col items-center">
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div class="barcode-svg-thermal max-w-full overflow-hidden" v-html="label.barcode_svg" />
                            <p class="font-mono text-[7px] leading-none text-gray-700">
                                {{ label.barcode }}<span v-if="showSku"> · {{ label.sku }}</span>
                            </p>
                        </div>

                        <div v-if="showPrice" class="text-[11px] font-extrabold leading-tight text-gray-900">
                            {{ formatPrice(label.price) }}
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AdminLayout>
</template>

<style scoped>
.a4-sheet {
    width: 210mm;
    min-height: 297mm;
    grid-template-rows: repeat(8, 37.125mm);
}
.a4-cell {
    height: 37.125mm;
}
.barcode-svg :deep(svg) {
    height: 28px;
    width: auto;
    max-width: 100%;
}
.thermal-label {
    width: 50mm;
    height: 25mm;
}
.barcode-svg-thermal :deep(svg) {
    height: 20px;
    width: auto;
    max-width: 100%;
}

@media print {
    :global(aside),
    :global(header) {
        display: none !important;
    }
    :global(main) {
        padding: 0 !important;
    }
    .a4-sheet {
        page-break-after: always;
    }
    .thermal-label {
        page-break-after: always;
    }
}
</style>

