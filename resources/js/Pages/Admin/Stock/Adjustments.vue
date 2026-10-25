<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    adjustments: Object,
    reasons: Array,
    locations: Array,
    defaultLocationId: Number,
    approvalThresholdCents: Number,
    approversByLocation: Object,
    canViewCost: Boolean,
});

const showCreate = ref(false);
const searchTerm = ref('');
const searchResults = ref([]);

const form = useForm({
    location_id: props.defaultLocationId ?? props.locations[0]?.id ?? '',
    reason: 'damaged',
    note: '',
    approver_id: '',
    pin: '',
    lines: [],
});

const selectedReason = computed(() => props.reasons.find((r) => r.value === form.reason));
const availableApprovers = computed(() => props.approversByLocation[form.location_id] ?? []);

const searchVariants = async () => {
    const q = searchTerm.value.trim();
    if (!q) {
        searchResults.value = [];
        return;
    }
    const { ok, data } = await getJson(
        route('stock.lookup', { q, location_id: form.location_id }),
    );
    if (ok) {
        searchResults.value = data.variants ?? [];
        if (searchResults.value.length === 1 && (searchResults.value[0].barcode === q || searchResults.value[0].sku === q)) {
            addVariant(searchResults.value[0]);
            searchTerm.value = '';
            searchResults.value = [];
        }
    }
};

const addVariant = (v) => {
    const dir = selectedReason.value?.direction ?? -1;
    form.lines.push({
        product_variant_id: v.id,
        sku: v.sku,
        product_name: v.product_name,
        variant_name: v.name,
        is_serialized: v.is_serialized,
        quantity: dir < 0 ? '-1' : '1',
        unit_cost: '',
        serial_number: '',
        note: '',
    });
};

const removeLine = (idx) => {
    form.lines.splice(idx, 1);
};

const submit = () => {
    form.post(route('stock.adjustments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            form.reset('note', 'pin', 'lines');
        },
    });
};
</script>

<template>
    <AdminLayout title="Stock adjustments">
        <StockNav>
            <PrimaryButton type="button" @click="showCreate = !showCreate">
                {{ showCreate ? 'Close form' : 'New adjustment' }}
            </PrimaryButton>
        </StockNav>

        <Card
            v-if="showCreate"
            title="New stock adjustment"
            :description="`Adjustments over ${formatMoney(approvalThresholdCents)} require a manager PIN.`"
            class="mb-6"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel for="adj-location" value="Location" />
                        <SelectInput id="adj-location" v-model="form.location_id" class="mt-1 w-full" required>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.code }} — {{ loc.name }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.location_id" />
                    </div>
                    <div>
                        <InputLabel for="adj-reason" value="Reason" />
                        <SelectInput id="adj-reason" v-model="form.reason" class="mt-1 w-full" required>
                            <option v-for="r in reasons" :key="r.value" :value="r.value">
                                {{ r.label }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.reason" />
                    </div>
                    <div>
                        <InputLabel for="adj-note" value="Note (required for Other)" />
                        <TextInput id="adj-note" v-model="form.note" class="mt-1 w-full" placeholder="Reason details…" />
                        <InputError class="mt-1" :message="form.errors.note" />
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <InputLabel value="Scan barcode or search SKU to add item" />
                    <div class="mt-1 flex gap-2">
                        <TextInput
                            v-model="searchTerm"
                            class="w-full"
                            placeholder="Scan barcode or type SKU / product name…"
                            @input="searchVariants"
                            @keydown.enter.prevent="searchVariants"
                        />
                    </div>
                    <div v-if="searchResults.length > 0" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                        <button
                            v-for="v in searchResults"
                            :key="v.id"
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-indigo-50"
                            @click="addVariant(v); searchResults = []; searchTerm = ''"
                        >
                            <span>
                                <strong class="text-gray-900">{{ v.sku }}</strong> — {{ v.product_name }}
                                <span v-if="v.name" class="text-gray-500">({{ v.name }})</span>
                            </span>
                            <span class="text-xs text-gray-500">On hand: {{ formatQuantity(v.on_hand) }}</span>
                        </button>
                    </div>
                </div>

                <InputError :message="form.errors.lines" />

                <div v-if="form.lines.length > 0" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold text-gray-500 uppercase">
                                <th class="pb-2">SKU / Item</th>
                                <th class="pb-2">Qty (+ gain / - loss)</th>
                                <th class="pb-2">Serial (if serialized)</th>
                                <th class="pb-2">Line Note</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(line, idx) in form.lines" :key="idx">
                                <td class="py-2 pr-3">
                                    <div class="font-semibold text-gray-900">{{ line.sku }}</div>
                                    <div class="text-xs text-gray-500">{{ line.product_name }} {{ line.variant_name }}</div>
                                </td>
                                <td class="py-2 pr-3">
                                    <TextInput v-model="line.quantity" class="w-28" required />
                                </td>
                                <td class="py-2 pr-3">
                                    <TextInput
                                        v-model="line.serial_number"
                                        class="w-40"
                                        :placeholder="line.is_serialized ? 'Serial number' : 'Optional'"
                                    />
                                </td>
                                <td class="py-2 pr-3">
                                    <TextInput v-model="line.note" class="w-full" placeholder="Optional note" />
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

                <div class="grid grid-cols-1 gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="adj-approver" value="Approving manager (needed when above threshold)" />
                        <SelectInput id="adj-approver" v-model="form.approver_id" class="mt-1 w-full">
                            <option value="">Select manager (if required)…</option>
                            <option v-for="mgr in availableApprovers" :key="mgr.id" :value="mgr.id">
                                {{ mgr.name }}
                            </option>
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel for="adj-pin" value="Manager PIN" />
                        <TextInput
                            id="adj-pin"
                            v-model="form.pin"
                            type="password"
                            inputmode="numeric"
                            maxlength="6"
                            class="mt-1 w-full"
                            placeholder="4–6 digit PIN"
                        />
                        <InputError class="mt-1" :message="form.errors.pin" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <PrimaryButton :disabled="form.processing || form.lines.length === 0">Post adjustment</PrimaryButton>
                </div>
            </form>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Number</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3">Items</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-right">Value</th>
                            <th class="px-4 py-3">Created / Approved by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="adj in adjustments.data" :key="adj.id">
                            <td class="px-4 py-3 font-semibold text-gray-900">{{ adj.number }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600">{{ adj.business_date }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ adj.location_code }} — {{ adj.location_name }}</td>
                            <td class="px-4 py-3">
                                <Badge tone="slate">{{ adj.reason_label }}</Badge>
                                <p v-if="adj.note" class="mt-1 text-xs text-gray-500">{{ adj.note }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <ul class="space-y-0.5 text-xs">
                                    <li v-for="l in adj.lines" :key="l.id">
                                        <span class="font-medium text-gray-900">{{ l.sku }}</span>:
                                        <span :class="l.quantity > 0 ? 'text-emerald-700' : 'text-rose-600'">
                                            {{ l.quantity > 0 ? '+' : '' }}{{ formatQuantity(l.quantity) }}
                                        </span>
                                    </li>
                                </ul>
                            </td>
                            <td v-if="canViewCost" class="px-4 py-3 text-right font-medium tabular-nums">
                                {{ formatMoney(adj.total_value) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <div>By: {{ adj.created_by_name || '—' }}</div>
                                <div v-if="adj.approved_by_name" class="text-emerald-700">Approved: {{ adj.approved_by_name }}</div>
                            </td>
                        </tr>
                        <tr v-if="adjustments.data.length === 0">
                            <td :colspan="canViewCost ? 7 : 6" class="px-4 py-8 text-center text-gray-500">
                                No stock adjustments recorded yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="adjustments.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

