<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CatalogNav from '@/Pages/Admin/Products/Partials/CatalogNav.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    import: Object,
    rows: Object,
    rowFilter: String,
    recentImports: Array,
    fields: Object,
    targets: Object,
    maxKilobytes: Number,
    maxRows: Number,
});

// Upload form
const uploadForm = useForm({
    file: null,
});

const pickFile = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    uploadForm.file = file;
    uploadForm.post(route('products.import.upload'), {
        forceFormData: true,
    });
};

// Mapping form
const mappingForm = useForm({
    mapping: props.import?.mapping ? [...props.import.mapping] : [],
    create_missing: Boolean(props.import?.create_missing),
});

const showMappingEditor = ref(props.import?.status === 'uploaded');

watch(
    () => props.import,
    (imp) => {
        if (imp) {
            mappingForm.mapping = [...(imp.mapping ?? [])];
            mappingForm.create_missing = Boolean(imp.create_missing);
            showMappingEditor.value = imp.status === 'uploaded';
        }
    },
);

const runDryRun = () => {
    mappingForm.post(route('products.import.dry-run', props.import.id), {
        preserveScroll: true,
        onSuccess: () => {
            showMappingEditor.value = false;
        },
    });
};

const commitForm = useForm({});
const commitImport = () => {
    commitForm.post(route('products.import.commit', props.import.id), {
        preserveScroll: true,
    });
};

const filterByAction = (action) => {
    router.get(
        route('products.import.show', props.import.id),
        action === 'all' ? {} : { action },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

// Poll while checking or committing
let pollTimer;
const syncPolling = () => {
    clearInterval(pollTimer);
    if (props.import?.running) {
        pollTimer = setInterval(() => {
            router.reload({ only: ['import', 'rows'] });
        }, 2000);
    }
};
watch(() => props.import?.running, syncPolling, { immediate: true });
onBeforeUnmount(() => clearInterval(pollTimer));

const actionTone = (action) =>
    ({
        create: 'green',
        created: 'green',
        update: 'blue',
        updated: 'blue',
        unchanged: 'gray',
        fail: 'red',
        failed: 'red',
    })[action] ?? 'gray';
</script>

<template>
    <AdminLayout title="Catalog import">
        <CatalogNav>
            <a
                :href="route('products.import.template', 'xlsx')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                <Icon name="download" class="size-4" />
                Template (.xlsx)
            </a>
            <a
                :href="route('products.import.template', 'csv')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                <Icon name="download" class="size-4" />
                Template (.csv)
            </a>
        </CatalogNav>

        <!-- Upload card -->
        <Card
            title="Upload catalog spreadsheet"
            :description="`Upload an .xlsx or UTF-8 .csv file (up to ${maxRows.toLocaleString()} rows). Nothing is written until you review the dry run and click Commit.`"
            class="mb-6"
        >
            <div class="flex flex-wrap items-center gap-4">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-brand-500">
                    <Icon name="plus" class="size-4" />
                    <span>{{ uploadForm.processing ? 'Uploading…' : 'Choose .xlsx or .csv file' }}</span>
                    <input type="file" accept=".xlsx,.csv" class="sr-only" :disabled="uploadForm.processing" @change="pickFile" />
                </label>
                <p class="text-xs text-gray-500">
                    Matches existing variants by SKU; never deletes products or variants; blank cells leave existing values untouched.
                </p>
            </div>
            <InputError class="mt-2" :message="uploadForm.errors.file" />
        </Card>

        <!-- Active Import View -->
        <div v-if="props.import" class="space-y-6">
            <Card>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h2 class="text-base font-semibold text-gray-900">{{ props.import.original_name }}</h2>
                            <Badge :tone="props.import.status === 'failed' ? 'red' : props.import.status === 'committed' ? 'green' : 'blue'">
                                {{ props.import.status_label }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            <span v-if="props.import.total_rows">{{ props.import.total_rows }} data row(s)</span>
                            <span v-if="props.import.dry_run_at"> · Dry run {{ props.import.dry_run_at }}</span>
                            <span v-if="props.import.committed_at"> · Committed {{ props.import.committed_at }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <SecondaryButton
                            v-if="props.import.status !== 'uploaded' && !props.import.running && props.import.status !== 'committed'"
                            type="button"
                            @click="showMappingEditor = !showMappingEditor"
                        >
                            {{ showMappingEditor ? 'Hide column mapping' : 'Edit column mapping' }}
                        </SecondaryButton>

                        <a
                            v-if="props.import.total_rows > 0 && !props.import.running"
                            :href="route('products.import.report', props.import.id)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
                        >
                            <Icon name="download" class="size-4" />
                            Download report (.csv)
                        </a>

                        <PrimaryButton
                            v-if="props.import.status === 'checked' && (props.import.counts?.create + props.import.counts?.update) > 0"
                            :disabled="commitForm.processing"
                            @click="commitImport"
                        >
                            Commit {{ (props.import.counts?.create ?? 0) + (props.import.counts?.update ?? 0) }} row(s)
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="props.import.error" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ props.import.error }}
                </div>

                <div v-if="props.import.running" class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                    Processing rows: {{ props.import.processed_rows }} / {{ props.import.total_rows || '…' }}
                </div>
            </Card>

            <!-- Step 1: Column Mapping -->
            <Card
                v-if="showMappingEditor"
                title="Map spreadsheet columns"
                description="Choose which catalog field each column in your spreadsheet corresponds to. Unmapped columns are ignored."
            >
                <form class="space-y-4" @submit.prevent="runDryRun">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(header, idx) in props.import.headers"
                            :key="idx"
                            class="rounded-lg border border-gray-200 bg-gray-50/60 p-3"
                        >
                            <p class="truncate text-xs font-semibold text-gray-700" :title="header">
                                Column {{ idx + 1 }}: <span class="font-mono text-gray-900">{{ header }}</span>
                            </p>
                            <SelectInput v-model="mappingForm.mapping[idx]" class="mt-1.5 block w-full text-sm">
                                <option :value="null">— Ignore column —</option>
                                <option v-for="(label, key) in targets" :key="key" :value="key">{{ label }}</option>
                            </SelectInput>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-100 pt-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <Checkbox v-model:checked="mappingForm.create_missing" />
                            <span>Create missing categories and option values automatically</span>
                        </label>

                        <PrimaryButton :disabled="mappingForm.processing">
                            Run dry run
                        </PrimaryButton>
                    </div>
                </form>
            </Card>

            <!-- Step 2: Dry Run / Commit Summary & Rows -->
            <template v-if="props.import.counts">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <button
                        v-for="[key, label, count] in [
                            ['create', 'To create / created', props.import.counts.create ?? props.import.counts.created ?? 0],
                            ['update', 'To update / updated', props.import.counts.update ?? props.import.counts.updated ?? 0],
                            ['unchanged', 'Unchanged', props.import.counts.unchanged ?? 0],
                            ['fail', 'Failed (not imported)', props.import.counts.fail ?? props.import.counts.failed ?? 0],
                        ]"
                        :key="key"
                        type="button"
                        class="rounded-xl border p-4 text-left transition-colors"
                        :class="rowFilter === key ? 'border-brand-500 bg-brand-50/40 ring-1 ring-brand-500' : 'border-gray-200 bg-white hover:bg-gray-50'"
                        @click="filterByAction(rowFilter === key ? 'all' : key)"
                    >
                        <p class="text-xs font-medium text-gray-500">{{ label }}</p>
                        <p class="mt-1 text-2xl font-bold" :class="key === 'fail' && count > 0 ? 'text-red-600' : 'text-gray-900'">
                            {{ count }}
                        </p>
                    </button>
                </div>

                <Card flush>
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="[fKey, fLabel] in [
                                    ['all', 'All rows'],
                                    ['fail', 'Failed'],
                                    ['create', 'Create'],
                                    ['update', 'Update'],
                                    ['unchanged', 'Unchanged'],
                                ]"
                                :key="fKey"
                                type="button"
                                class="rounded-md px-2.5 py-1 text-xs font-semibold"
                                :class="rowFilter === fKey ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                @click="filterByAction(fKey)"
                            >
                                {{ fLabel }}
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                                <tr>
                                    <th class="w-20 px-4 py-2.5">Row</th>
                                    <th class="px-4 py-2.5">SKU</th>
                                    <th class="px-4 py-2.5">Product</th>
                                    <th class="px-4 py-2.5">Dry run</th>
                                    <th v-if="props.import.status === 'committed'" class="px-4 py-2.5">Result</th>
                                    <th class="px-4 py-2.5">Details / Reasons</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="row in rows?.data ?? []" :key="row.id">
                                    <td class="px-4 py-2.5 font-mono text-xs text-gray-500">#{{ row.row_number }}</td>
                                    <td class="px-4 py-2.5 font-mono text-xs font-semibold text-gray-900">{{ row.sku || '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-600">{{ row.product_key || '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        <Badge :tone="actionTone(row.action)">{{ row.action }}</Badge>
                                    </td>
                                    <td v-if="props.import.status === 'committed'" class="px-4 py-2.5">
                                        <Badge v-if="row.result" :tone="actionTone(row.result)">{{ row.result }}</Badge>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs">
                                        <ul v-if="row.reasons?.length" class="space-y-0.5 text-red-600">
                                            <li v-for="(r, rIdx) in row.reasons" :key="rIdx">{{ r }}</li>
                                        </ul>
                                        <div v-else-if="Object.keys(row.changes ?? {}).length" class="space-y-0.5 text-gray-700">
                                            <div v-for="(pair, field) in row.changes" :key="field">
                                                <span class="font-medium text-gray-500">{{ field }}:</span>
                                                {{ pair[0] ?? '—' }} → <strong>{{ pair[1] ?? '—' }}</strong>
                                            </div>
                                        </div>
                                        <span v-else-if="row.action === 'create'" class="text-gray-600">
                                            New variant{{ row.product?.product_name ? ` on "${row.product.product_name}"` : '' }}
                                        </span>
                                        <span v-else class="text-gray-400">No changes</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Pagination v-if="rows?.links" :links="rows.links" />
            </template>
        </div>

        <!-- Recent imports -->
        <Card v-if="recentImports?.length" title="Recent imports" class="mt-6" flush>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">File</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Rows</th>
                            <th class="px-4 py-2.5">Summary</th>
                            <th class="px-4 py-2.5">By</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="imp in recentImports" :key="imp.id">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ imp.original_name }}</td>
                            <td class="px-4 py-2.5">
                                <Badge :tone="imp.status === 'committed' ? 'green' : imp.status === 'failed' ? 'red' : 'blue'">
                                    {{ imp.status_label }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2.5 text-gray-600">{{ imp.total_rows }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-600">
                                <template v-if="imp.counts">
                                    +{{ imp.counts.create ?? imp.counts.created ?? 0 }} /
                                    ~{{ imp.counts.update ?? imp.counts.updated ?? 0 }} /
                                    !{{ imp.counts.fail ?? imp.counts.failed ?? 0 }}
                                </template>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-500">{{ imp.user }} · {{ imp.created_at }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <Link :href="route('products.import.show', imp.id)" class="font-semibold text-brand-600 hover:text-brand-500">
                                    Open
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>

