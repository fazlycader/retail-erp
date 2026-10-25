<script setup>
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson } from '@/lib/http';
import { formatQuantity } from '@/lib/quantity';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    locations: Array,
    defaultFromId: Number,
});

const otherLocationId = props.locations.find((l) => l.id !== props.defaultFromId)?.id ?? '';

const form = useForm({
    from_location_id: props.defaultFromId ?? props.locations[0]?.id ?? '',
    to_location_id: otherLocationId,
    status: 'requested',
    vehicle_note: '',
    note: '',
    lines: [],
});

const searchTerm = ref('');
const searchResults = ref([]);

const searchVariants = async () => {
    const q = searchTerm.value.trim();
    if (!q) {
        searchResults.value = [];
        return;
    }
    const { ok, data } = await getJson(
        route('stock.lookup', { q, location_id: form.from_location_id }),
    );
    if (ok) {
        searchResults.value = data.variants ?? [];
        if (searchResults.value.length === 1 && (searchResults.value[0].barcode === q || searchResults.value[0].sku === q)) {
            addOrIncrementLine(searchResults.value[0]);
            searchTerm.value = '';
            searchResults.value = [];
        }
    }
};

const addOrIncrementLine = (v) => {
    const existing = form.lines.find((l) => l.product_variant_id === v.id);
    if (existing) {
        existing.requested_qty = String(Number(existing.requested_qty || 0) + 1);
        return;
    }
    form.lines.push({
        product_variant_id: v.id,
        sku: v.sku,
        barcode: v.barcode,
        product_name: v.product_name,
        variant_name: v.name,
        available: v.available,
        requested_qty: '1',
        note: '',
    });
};

const removeLine = (idx) => {
    form.lines.splice(idx, 1);
};

const submit = () => {
    form.post(route('transfers.store'));
};
</script>

<template>
    <AdminLayout title="New stock transfer">
        <div class="mb-6 flex items-center justify-between">
            <Link :href="route('transfers.index')" class="text-sm font-medium text-gray-600 hover:text-gray-900">
                ← Back to transfers
            </Link>
        </div>

        <form class="mx-auto max-w-4xl space-y-6" @submit.prevent="submit">
            <Card title="Transfer details">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="trf-from" value="From location (source)" />
                        <SelectInput id="trf-from" v-model="form.from_location_id" class="mt-1 w-full" required>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.code }} — {{ loc.name }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.from_location_id" />
                    </div>
                    <div>
                        <InputLabel for="trf-to" value="To location (destination)" />
                        <SelectInput id="trf-to" v-model="form.to_location_id" class="mt-1 w-full" required>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.code }} — {{ loc.name }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.to_location_id" />
                    </div>
                    <div>
                        <InputLabel for="trf-vehicle" value="Vehicle / driver note" />
                        <TextInput id="trf-vehicle" v-model="form.vehicle_note" class="mt-1 w-full" placeholder="e.g. Lorry WP LB-4521 / Kamal" />
                    </div>
                    <div>
                        <InputLabel for="trf-note" value="Note" />
                        <TextInput id="trf-note" v-model="form.note" class="mt-1 w-full" placeholder="Showroom replenishment…" />
                    </div>
                </div>
            </Card>

            <Card title="Items to transfer" description="Scan a barcode or search by SKU to add items.">
                <div class="mb-4">
                    <TextInput
                        v-model="searchTerm"
                        class="w-full"
                        placeholder="Scan barcode or type SKU / product name…"
                        @input="searchVariants"
                        @keydown.enter.prevent="searchVariants"
                    />
                    <div v-if="searchResults.length > 0" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                        <button
                            v-for="v in searchResults"
                            :key="v.id"
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-indigo-50"
                            @click="addOrIncrementLine(v); searchResults = []; searchTerm = ''"
                        >
                            <span>
                                <strong class="text-gray-900">{{ v.sku }}</strong> — {{ v.product_name }}
                                <span v-if="v.name" class="text-gray-500">({{ v.name }})</span>
                            </span>
                            <span class="text-xs text-gray-500">Available at source: {{ formatQuantity(v.available) }}</span>
                        </button>
                    </div>
                </div>

                <InputError class="mb-3" :message="form.errors.lines" />

                <div v-if="form.lines.length > 0" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold text-gray-500 uppercase">
                                <th class="pb-2">SKU / Item</th>
                                <th class="pb-2 text-right">Available</th>
                                <th class="pb-2">Requested Qty</th>
                                <th class="pb-2">Note</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(line, idx) in form.lines" :key="line.product_variant_id">
                                <td class="py-2 pr-3">
                                    <div class="font-semibold text-gray-900">{{ line.sku }}</div>
                                    <div class="text-xs text-gray-500">{{ line.product_name }} {{ line.variant_name }}</div>
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums text-gray-600">
                                    {{ formatQuantity(line.available) }}
                                </td>
                                <td class="py-2 pr-3">
                                    <TextInput v-model="line.requested_qty" class="w-28" required />
                                </td>
                                <td class="py-2 pr-3">
                                    <TextInput v-model="line.note" class="w-full" placeholder="Optional" />
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" class="text-xs font-semibold text-rose-600 hover:underline" @click="removeLine(idx)">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="py-6 text-center text-sm text-gray-500">
                    Scan or search above to add lines to this transfer.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <PrimaryButton :disabled="form.processing || form.lines.length === 0">
                        Create transfer request
                    </PrimaryButton>
                </div>
            </Card>
        </form>
    </AdminLayout>
</template>

