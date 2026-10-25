<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    count: Object,
    lines: Array,
    canViewCost: Boolean,
    canPost: Boolean,
});

const scanInput = ref(null);
const scanForm = useForm({
    code: '',
    quantity: '1',
});

const editableLines = ref(
    props.lines.map((l) => ({
        id: l.id,
        counted_qty: l.counted_decimal ?? '',
        note: l.note ?? '',
    })),
);

watch(
    () => props.lines,
    (newLines) => {
        editableLines.value = newLines.map((l) => ({
            id: l.id,
            counted_qty: l.counted_decimal ?? '',
            note: l.note ?? '',
        }));
    },
);

const submitScan = () => {
    scanForm.post(route('stock.counts.scan', props.count.id), {
        preserveScroll: true,
        onSuccess: () => {
            scanForm.code = '';
            scanInput.value?.focus();
        },
    });
};

const saveCounts = () => {
    router.post(
        route('stock.counts.record', props.count.id),
        { lines: editableLines.value },
        { preserveScroll: true },
    );
};

const submitForReview = (zeroUncounted = false) => {
    router.post(
        route('stock.counts.review', props.count.id),
        { zero_uncounted: zeroUncounted },
        { preserveScroll: true },
    );
};

const postCount = () => {
    router.post(route('stock.counts.post', props.count.id), {}, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout :title="`Stock count ${count.number}`">
        <StockNav>
            <Link
                :href="route('stock.counts.index')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                All counts
            </Link>
        </StockNav>

        <Card class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-gray-900">{{ count.number }}</h2>
                        <Badge :tone="count.status === 'posted' ? 'green' : count.status === 'review' ? 'amber' : 'indigo'">
                            {{ count.status_label }}
                        </Badge>
                        <Badge v-if="count.blind" tone="slate">Blind count</Badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ count.location_code }} — {{ count.location_name }} · {{ count.scope_label }}
                        <span v-if="count.category_name">({{ count.category_name }})</span>
                        · Counted {{ count.counted_lines }} / {{ count.total_lines }} items
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="!count.hide_system_qty && canViewCost && count.total_variance_value !== undefined" class="mr-3 text-right">
                        <span class="block text-xs text-gray-400">Net Variance Value</span>
                        <span
                            class="text-sm font-bold tabular-nums"
                            :class="count.total_variance_value < 0 ? 'text-rose-600' : count.total_variance_value > 0 ? 'text-emerald-700' : 'text-gray-800'"
                        >
                            {{ formatMoney(count.total_variance_value) }}
                        </span>
                    </div>

                    <SecondaryButton
                        v-if="count.status !== 'posted'"
                        type="button"
                        @click="saveCounts"
                    >
                        Save counts
                    </SecondaryButton>

                    <PrimaryButton
                        v-if="count.status === 'open' || count.status === 'counting'"
                        type="button"
                        @click="submitForReview(true)"
                    >
                        Review variances (zero uncounted)
                    </PrimaryButton>

                    <PrimaryButton
                        v-if="count.status === 'review' && canPost"
                        type="button"
                        @click="postCount"
                    >
                        Post count adjustments
                    </PrimaryButton>
                </div>
            </div>
        </Card>

        <Card
            v-if="count.status === 'open' || count.status === 'counting'"
            title="Barcode scanner entry"
            description="Scan a barcode or enter a SKU and press Enter to add +1 (or change the step quantity)."
            class="mb-6"
        >
            <form class="flex flex-wrap items-start gap-3" @submit.prevent="submitScan">
                <div class="min-w-64 flex-1">
                    <TextInput
                        ref="scanInput"
                        v-model="scanForm.code"
                        class="w-full"
                        placeholder="Scan barcode or type SKU…"
                        autofocus
                    />
                    <InputError class="mt-1" :message="scanForm.errors.code" />
                </div>
                <div class="w-28">
                    <TextInput v-model="scanForm.quantity" class="w-full" placeholder="1" title="Increment step" />
                </div>
                <PrimaryButton :disabled="scanForm.processing">Scan +{{ scanForm.quantity || 1 }}</PrimaryButton>
            </form>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">SKU / Barcode</th>
                            <th class="px-4 py-3">Product</th>
                            <th v-if="!count.hide_system_qty" class="px-4 py-3 text-right">System Qty</th>
                            <th class="px-4 py-3 text-right">Counted Qty</th>
                            <th v-if="!count.hide_system_qty" class="px-4 py-3 text-right">Variance</th>
                            <th v-if="!count.hide_system_qty && canViewCost" class="px-4 py-3 text-right">Variance Value</th>
                            <th class="px-4 py-3">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="(line, idx) in lines" :key="line.id">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900">{{ line.sku }}</div>
                                <div class="font-mono text-xs text-gray-400">{{ line.barcode }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-gray-900">{{ line.product_name }}</div>
                                <div v-if="line.variant_name" class="text-xs text-gray-500">{{ line.variant_name }}</div>
                            </td>
                            <td v-if="!count.hide_system_qty" class="px-4 py-3 text-right tabular-nums text-gray-700">
                                {{ formatQuantity(line.system_qty) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <TextInput
                                    v-if="count.status !== 'posted'"
                                    v-model="editableLines[idx].counted_qty"
                                    class="w-28 text-right"
                                    placeholder="Uncounted"
                                />
                                <span v-else class="font-semibold tabular-nums">
                                    {{ formatQuantity(line.counted_qty) }}
                                </span>
                            </td>
                            <td
                                v-if="!count.hide_system_qty"
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="line.variance_qty < 0 ? 'text-rose-600' : line.variance_qty > 0 ? 'text-emerald-700' : 'text-gray-400'"
                            >
                                <span v-if="line.counted_qty !== null">
                                    {{ line.variance_qty > 0 ? '+' : '' }}{{ formatQuantity(line.variance_qty) }}
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td
                                v-if="!count.hide_system_qty && canViewCost"
                                class="px-4 py-3 text-right tabular-nums"
                                :class="line.variance_value < 0 ? 'text-rose-600' : line.variance_value > 0 ? 'text-emerald-700' : 'text-gray-400'"
                            >
                                <span v-if="line.counted_qty !== null">{{ formatMoney(line.variance_value) }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3">
                                <TextInput
                                    v-if="count.status !== 'posted'"
                                    v-model="editableLines[idx].note"
                                    class="w-full text-xs"
                                    placeholder="Optional note"
                                />
                                <span v-else class="text-xs text-gray-500">{{ line.note || '—' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>

