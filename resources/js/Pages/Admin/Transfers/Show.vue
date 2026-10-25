<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatQuantity } from '@/lib/quantity';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    transfer: Object,
    canViewCost: Boolean,
});

const scanCode = ref('');
const scanNotice = ref('');

const dispatchForm = useForm({
    vehicle_note: props.transfer.vehicle_note ?? '',
    lines: props.transfer.lines.map((l) => ({
        id: l.id,
        sent_qty: l.sent_decimal,
    })),
});

const receiveForm = useForm({
    lines: props.transfer.lines.map((l) => ({
        id: l.id,
        received_qty: l.received_decimal,
        discrepancy_reason: l.discrepancy_reason ?? '',
    })),
});

const resolveForm = useForm({
    resolution: 'return_to_source',
    note: '',
});

const handleScan = () => {
    const code = scanCode.value.trim();
    if (!code) return;

    const idx = props.transfer.lines.findIndex(
        (l) => l.barcode === code || l.sku?.toUpperCase() === code.toUpperCase(),
    );

    if (idx === -1) {
        scanNotice.value = `No line on this transfer matches "${code}".`;
        return;
    }

    const line = props.transfer.lines[idx];
    if (props.transfer.can_dispatch) {
        const current = Number(dispatchForm.lines[idx].sent_qty || 0);
        const max = Number(line.requested_decimal || 0);
        dispatchForm.lines[idx].sent_qty = String(Math.min(max, current + 1));
        scanNotice.value = `Confirmed ${line.sku} (${dispatchForm.lines[idx].sent_qty} / ${line.requested_decimal})`;
    } else if (props.transfer.can_receive) {
        const current = Number(receiveForm.lines[idx].received_qty || 0);
        const max = Number(line.sent_decimal || 0);
        receiveForm.lines[idx].received_qty = String(Math.min(max, current + 1));
        scanNotice.value = `Received ${line.sku} (${receiveForm.lines[idx].received_qty} / ${line.sent_decimal})`;
    }

    scanCode.value = '';
};

const submitDispatch = () => {
    dispatchForm.post(route('transfers.dispatch', props.transfer.id), { preserveScroll: true });
};

const submitReceive = () => {
    receiveForm.post(route('transfers.receive', props.transfer.id), { preserveScroll: true });
};

const submitResolve = () => {
    resolveForm.post(route('transfers.resolve', props.transfer.id), { preserveScroll: true });
};

const cancelTransfer = () => {
    router.post(route('transfers.cancel', props.transfer.id), {}, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout :title="`Transfer ${transfer.number}`">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('transfers.index')" class="text-sm font-medium text-gray-600 hover:text-gray-900">
                ← All transfers
            </Link>
            <SecondaryButton v-if="transfer.can_cancel" type="button" @click="cancelTransfer">
                Cancel transfer
            </SecondaryButton>
        </div>

        <Card class="mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-gray-900">{{ transfer.number }}</h2>
                        <Badge :tone="transfer.status === 'received' || transfer.status === 'closed' ? 'green' : transfer.status === 'partially_received' ? 'rose' : 'indigo'">
                            {{ transfer.status_label }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-700">
                        <strong>{{ transfer.from_location?.code }} — {{ transfer.from_location?.name }}</strong>
                        <span class="mx-2 text-gray-400">→</span>
                        <strong>{{ transfer.to_location?.code }} — {{ transfer.to_location?.name }}</strong>
                    </p>
                    <p v-if="transfer.vehicle_note" class="mt-1 text-xs text-gray-500">
                        Vehicle / Driver: {{ transfer.vehicle_note }}
                    </p>
                    <p v-if="transfer.note" class="mt-1 text-xs text-gray-500">
                        Note: {{ transfer.note }}
                    </p>
                </div>
                <div class="text-right text-xs text-gray-500">
                    <div v-if="transfer.requested_by_name">Requested by: {{ transfer.requested_by_name }}</div>
                    <div v-if="transfer.dispatched_by_name">Dispatched by: {{ transfer.dispatched_by_name }}</div>
                    <div v-if="transfer.received_by_name">Received by: {{ transfer.received_by_name }}</div>
                    <div v-if="transfer.closed_by_name">Resolved by: {{ transfer.closed_by_name }} ({{ transfer.resolution }})</div>
                </div>
            </div>
        </Card>

        <Card
            v-if="transfer.can_dispatch || transfer.can_receive"
            title="Scan barcode to confirm"
            description="Scan each item's barcode or SKU to increment its confirmed quantity."
            class="mb-6"
        >
            <form class="flex gap-3" @submit.prevent="handleScan">
                <TextInput v-model="scanCode" class="w-full" placeholder="Scan barcode or SKU…" />
                <PrimaryButton type="submit">Confirm +1</PrimaryButton>
            </form>
            <p v-if="scanNotice" class="mt-2 text-xs font-medium text-indigo-700">{{ scanNotice }}</p>
        </Card>

        <Card title="Transfer lines" class="mb-6">
            <InputError class="mb-3" :message="dispatchForm.errors.lines || receiveForm.errors.lines" />

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-gray-500 uppercase">
                            <th class="pb-3">SKU / Product</th>
                            <th class="pb-3 text-right">Requested</th>
                            <th class="pb-3 text-right">Sent</th>
                            <th class="pb-3 text-right">Received</th>
                            <th class="pb-3">Discrepancy / Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(line, idx) in transfer.lines" :key="line.id">
                            <td class="py-3 pr-4">
                                <div class="font-semibold text-gray-900">{{ line.sku }}</div>
                                <div class="text-xs text-gray-500">{{ line.product_name }} {{ line.variant_name }}</div>
                                <div v-if="line.barcode" class="font-mono text-[11px] text-gray-400">{{ line.barcode }}</div>
                            </td>
                            <td class="py-3 pr-4 text-right tabular-nums">
                                {{ formatQuantity(line.requested_qty) }}
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <TextInput
                                    v-if="transfer.can_dispatch"
                                    v-model="dispatchForm.lines[idx].sent_qty"
                                    class="w-24 text-right"
                                />
                                <span v-else class="tabular-nums">{{ formatQuantity(line.sent_qty) }}</span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <TextInput
                                    v-if="transfer.can_receive"
                                    v-model="receiveForm.lines[idx].received_qty"
                                    class="w-24 text-right"
                                />
                                <span v-else class="tabular-nums">{{ formatQuantity(line.received_qty) }}</span>
                            </td>
                            <td class="py-3">
                                <TextInput
                                    v-if="transfer.can_receive"
                                    v-model="receiveForm.lines[idx].discrepancy_reason"
                                    class="w-full text-xs"
                                    placeholder="Reason if short received…"
                                />
                                <div v-else>
                                    <Badge v-if="line.discrepancy_qty > 0" tone="rose">
                                        Short {{ formatQuantity(line.discrepancy_qty) }}: {{ line.discrepancy_reason }}
                                    </Badge>
                                    <span v-else class="text-xs text-gray-400">{{ line.note || '—' }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="transfer.can_dispatch" class="mt-6 flex flex-wrap items-end justify-between gap-4 border-t border-gray-100 pt-4">
                <div class="w-full sm:w-80">
                    <InputLabel for="disp-vehicle" value="Vehicle / driver note" />
                    <TextInput id="disp-vehicle" v-model="dispatchForm.vehicle_note" class="mt-1 w-full" placeholder="Vehicle number / driver name" />
                </div>
                <PrimaryButton :disabled="dispatchForm.processing" @click="submitDispatch">
                    Dispatch transfer (move to in-transit)
                </PrimaryButton>
            </div>

            <div v-if="transfer.can_receive" class="mt-6 flex justify-end border-t border-gray-100 pt-4">
                <PrimaryButton :disabled="receiveForm.processing" @click="submitReceive">
                    Confirm receipt at {{ transfer.to_location?.code }}
                </PrimaryButton>
            </div>
        </Card>

        <Card
            v-if="transfer.can_resolve"
            title="Resolve short-receipt discrepancy (Manager)"
            description="Choose whether to write off the missing items as a loss or return them to the source location's stock."
        >
            <form class="grid grid-cols-1 items-end gap-4 sm:grid-cols-3" @submit.prevent="submitResolve">
                <div>
                    <InputLabel for="res-action" value="Resolution" />
                    <SelectInput id="res-action" v-model="resolveForm.resolution" class="mt-1 w-full">
                        <option value="return_to_source">Return missing qty to {{ transfer.from_location?.code }}</option>
                        <option value="write_off">Write off missing qty as loss</option>
                    </SelectInput>
                </div>
                <div>
                    <InputLabel for="res-note" value="Resolution note" />
                    <TextInput id="res-note" v-model="resolveForm.note" class="mt-1 w-full" placeholder="Manager resolution note…" />
                </div>
                <div>
                    <PrimaryButton :disabled="resolveForm.processing">Resolve &amp; close transfer</PrimaryButton>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>

