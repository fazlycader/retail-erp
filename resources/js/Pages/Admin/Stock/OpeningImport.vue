<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    locations: Array,
    defaultLocationId: Number,
    imports: Array,
    activeImport: Object,
    canViewCost: Boolean,
});

const uploadForm = useForm({
    location_id: props.defaultLocationId ?? props.locations[0]?.id ?? '',
    file: null,
});

const submitUpload = () => {
    uploadForm.post(route('stock.opening.upload'), {
        forceFormData: true,
        onSuccess: () => {
            uploadForm.file = null;
        },
    });
};

const commitImport = () => {
    if (!props.activeImport) return;
    router.post(route('stock.opening.commit', props.activeImport.id));
};
</script>

<template>
    <AdminLayout title="Opening stock import">
        <StockNav>
            <a
                :href="route('stock.opening.template', 'xlsx')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                Download XLSX template
            </a>
            <a
                :href="route('stock.opening.template', 'csv')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
            >
                CSV template
            </a>
        </StockNav>

        <Card
            title="Upload opening stock sheet"
            description="Upload an XLSX or CSV file per location with columns: sku, quantity, unit_cost, and optional serials. A dry run checks every row before you commit."
            class="mb-6"
        >
            <form class="grid grid-cols-1 items-end gap-4 sm:grid-cols-3" @submit.prevent="submitUpload">
                <div>
                    <InputLabel for="open-location" value="Location" />
                    <SelectInput id="open-location" v-model="uploadForm.location_id" class="mt-1 w-full" required>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            {{ loc.code }} — {{ loc.name }}
                        </option>
                    </SelectInput>
                    <InputError class="mt-1" :message="uploadForm.errors.location_id" />
                </div>
                <div>
                    <InputLabel for="open-file" value="Spreadsheet (.xlsx or .csv)" />
                    <input
                        id="open-file"
                        type="file"
                        accept=".xlsx,.csv"
                        class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800"
                        required
                        @change="uploadForm.file = $event.target.files[0]"
                    />
                    <InputError class="mt-1" :message="uploadForm.errors.file" />
                </div>
                <div>
                    <PrimaryButton :disabled="uploadForm.processing || !uploadForm.file">
                        Upload &amp; dry run
                    </PrimaryButton>
                </div>
            </form>
        </Card>

        <Card v-if="activeImport" class="mb-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-bold text-gray-900">{{ activeImport.original_name }}</h3>
                        <Badge :tone="activeImport.status === 'committed' ? 'green' : 'amber'">
                            {{ activeImport.status }}
                        </Badge>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Location: {{ activeImport.location_code }} — {{ activeImport.location_name }}
                        · Ready: {{ activeImport.counts?.create ?? activeImport.counts?.created ?? 0 }}
                        · Failed: {{ activeImport.counts?.fail ?? activeImport.counts?.failed ?? 0 }}
                        · <a :href="route('stock.opening.report', activeImport.id)" class="font-semibold text-indigo-700 hover:underline">Report of rows to check (CSV)</a>
                    </p>
                </div>
                <PrimaryButton
                    v-if="activeImport.status === 'checked' && (activeImport.counts?.create ?? 0) > 0"
                    type="button"
                    @click="commitImport"
                >
                    Commit {{ activeImport.counts.create }} opening row(s)
                </PrimaryButton>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-2">Row</th>
                            <th class="px-3 py-2">SKU</th>
                            <th class="px-3 py-2">Product</th>
                            <th class="px-3 py-2 text-right">Qty</th>
                            <th v-if="canViewCost" class="px-3 py-2 text-right">Unit Cost</th>
                            <th class="px-3 py-2">Serials</th>
                            <th class="px-3 py-2">Status / Issues</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in activeImport.rows" :key="r.id">
                            <td class="px-3 py-2 tabular-nums text-gray-500">{{ r.row_number }}</td>
                            <td class="px-3 py-2 font-semibold text-gray-900">{{ r.sku || '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ r.product_name || '—' }} {{ r.variant_name }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatQuantity(r.quantity) }}</td>
                            <td v-if="canViewCost" class="px-3 py-2 text-right tabular-nums">
                                {{ r.unit_cost !== null ? formatMoney(r.unit_cost) : '—' }}
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-500">
                                {{ r.serials?.length ? r.serials.join(', ') : '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <Badge :tone="r.action === 'create' ? 'green' : 'rose'">
                                    {{ r.result || r.action }}
                                </Badge>
                                <ul v-if="r.reasons?.length" class="mt-1 list-disc pl-4 text-xs text-rose-600">
                                    <li v-for="(msg, i) in r.reasons" :key="i">{{ msg }}</li>
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>

