<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import CustomSpecModal from '@/Components/Sales/CustomSpecModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { getJson } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import WorkshopNav from '@/Pages/Admin/Workshop/Partials/WorkshopNav.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    job: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    supplierStatuses: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    destinations: { type: Array, default: () => [] },
    defaultDestinationId: { type: Number, default: null },
    suppliers: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canViewCosts: { type: Boolean, default: false },
});

const isOpen = computed(() => ['pending', 'in_production'].includes(props.job.status));
const isWorkshop = computed(() => props.job.source === 'workshop');

// Job details form (priority, due date, assigned staff, notes)
const jobForm = useForm({
    priority: props.job.priority,
    due_date: props.job.due_date ?? '',
    assigned_to: props.job.assigned_to ?? '',
    notes: props.job.notes ?? '',
});

const saveJobDetails = () => {
    jobForm
        .transform((d) => ({
            ...d,
            assigned_to: d.assigned_to === '' ? null : Number(d.assigned_to),
            due_date: d.due_date || null,
        }))
        .put(route('workshop.jobs.update', props.job.id), { preserveScroll: true });
};

// Stage move & photo/note log
const stageForm = useForm({
    stage_id: props.job.stage_id ?? '',
    note: '',
    photos: [],
});

watch(
    () => props.job.stage_id,
    (next) => {
        stageForm.stage_id = next ?? '';
    },
);

const submitStageMove = (targetStageId) => {
    stageForm.stage_id = targetStageId ?? '';
    stageForm.post(route('workshop.jobs.stage', props.job.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            stageForm.note = '';
            stageForm.photos = [];
        },
    });
};

const noteForm = useForm({
    note: '',
    photos: [],
});

const submitNote = () => {
    noteForm.post(route('workshop.jobs.notes', props.job.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            noteForm.reset();
        },
    });
};

// Material issue / return
const materialMode = ref('issue'); // 'issue' | 'return'
const materialSearch = ref('');
const materialResults = ref([]);
const selectedMaterial = ref(null);

const materialForm = useForm({
    product_variant_id: null,
    code: '',
    quantity: '1',
    note: '',
});

const searchMaterials = async () => {
    const q = materialSearch.value.trim();
    if (!q) {
        materialResults.value = [];
        return;
    }
    const { ok, data } = await getJson(
        route('workshop.materials.lookup', {
            q,
            location_id: props.job.location?.id,
        }),
    );
    if (ok) {
        materialResults.value = data.materials ?? [];
    }
};

const pickMaterial = (m) => {
    selectedMaterial.value = m;
    materialForm.product_variant_id = m.id;
    materialForm.code = m.sku;
    materialSearch.value = '';
    materialResults.value = [];
};

const submitMaterial = () => {
    if (!materialForm.product_variant_id && materialSearch.value.trim()) {
        materialForm.code = materialSearch.value.trim();
    }
    const url =
        materialMode.value === 'return'
            ? route('workshop.jobs.materials.return', props.job.id)
            : route('workshop.jobs.materials.issue', props.job.id);

    materialForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            selectedMaterial.value = null;
            materialForm.reset();
            materialSearch.value = '';
        },
    });
};

// Labour form
const labourForm = useForm({
    worker_name: '',
    user_id: '',
    work_date: new Date().toISOString().slice(0, 10),
    hours: '',
    hourly_rate: '',
    amount: '',
    note: '',
});

const onPickWorkerUser = (userId) => {
    const u = props.staff.find((s) => s.id === Number(userId));
    if (u && !labourForm.worker_name) {
        labourForm.worker_name = u.name;
    }
};

const submitLabour = () => {
    labourForm
        .transform((d) => ({
            ...d,
            user_id: d.user_id === '' ? null : Number(d.user_id),
        }))
        .post(route('workshop.jobs.labour.store', props.job.id), {
            preserveScroll: true,
            onSuccess: () => {
                labourForm.reset('worker_name', 'user_id', 'hours', 'hourly_rate', 'amount', 'note');
            },
        });
};

const removeLabour = (labourId) => {
    router.delete(route('workshop.jobs.labour.destroy', [props.job.id, labourId]), {
        preserveScroll: true,
    });
};

// Complete job
const completeForm = useForm({ note: '' });
const submitComplete = () => {
    if (!confirm(`Mark ${props.job.number} finished? This creates the finished piece in stock at the workshop and reserves it for the order.`)) {
        return;
    }
    completeForm.post(route('workshop.jobs.complete', props.job.id), { preserveScroll: true });
};

// Send finished piece to showroom
const sendForm = useForm({
    to_location_id: props.defaultDestinationId ?? props.destinations[0]?.id ?? '',
});
const submitSend = () => {
    sendForm.post(route('workshop.jobs.send', props.job.id), { preserveScroll: true });
};

// Outsource modal
const showOutsourceModal = ref(false);
const outsourceForm = useForm({
    supplier_id: props.suppliers[0]?.id ?? '',
    unit_cost: '0.00',
    deliver_to_location_id: props.defaultDestinationId ?? props.job.location?.id ?? '',
});
const submitOutsource = () => {
    outsourceForm.post(route('workshop.jobs.outsource', props.job.id), {
        preserveScroll: true,
        onSuccess: () => {
            showOutsourceModal.value = false;
        },
    });
};

// Supplier progress
const supplierProgressForm = useForm({
    supplier_status: props.job.supplier?.status ?? 'ordered',
    note: '',
});
const submitSupplierProgress = () => {
    supplierProgressForm.post(route('workshop.suppliers.progress', props.job.id), {
        preserveScroll: true,
    });
};

// Spec modal
const showSpecModal = ref(false);
</script>

<template>
    <AdminLayout :title="`Job ${job.number}`">
        <WorkshopNav>
            <Link :href="route('workshop.board')" class="text-xs font-semibold text-gray-600 hover:text-gray-900">
                ← Back to Jobs Board
            </Link>
        </WorkshopNav>

        <div class="space-y-6">
            <!-- Top Summary Banner -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-mono text-xl font-bold text-gray-900">{{ job.number }}</h1>
                            <Badge :tone="job.status === 'ready' ? 'green' : job.status === 'in_production' ? 'blue' : 'gray'">
                                {{ job.status_label }}
                            </Badge>
                            <Badge v-if="job.stage" tone="amber">{{ job.stage }}</Badge>
                            <Badge v-if="job.overdue" tone="red">Overdue</Badge>
                            <Badge v-if="job.priority > 1" :tone="job.priority === 3 ? 'red' : 'amber'">
                                {{ job.priority_label }}
                            </Badge>
                        </div>

                        <h2 class="mt-1 text-lg font-semibold text-gray-900">
                            {{ job.title }}
                            <span v-if="job.pieces > 1" class="text-sm font-normal text-gray-500">× {{ job.pieces }} pieces</span>
                        </h2>

                        <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-600">
                            <span>Location: <strong>{{ job.location?.name }} ({{ job.location?.code }})</strong></span>
                            <span v-if="job.customer">Customer: <strong>{{ job.customer }}</strong></span>
                            <span v-if="job.order">
                                Order:
                                <Link
                                    v-if="job.sales_order_id && canManage"
                                    :href="route('sales.orders.show', job.sales_order_id)"
                                    class="font-mono font-semibold text-brand-600 hover:underline"
                                >
                                    {{ job.order }}
                                </Link>
                                <strong v-else class="font-mono">{{ job.order }}</strong>
                            </span>
                            <span v-if="job.due_date" :class="job.overdue ? 'font-semibold text-red-700' : ''">
                                Due date: <strong>{{ job.due_date }}</strong>
                            </span>
                            <span v-if="job.fulfilment">Fulfilment: <strong>{{ job.fulfilment }}</strong></span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <SecondaryButton
                            v-if="isWorkshop && job.status === 'pending' && canManage && suppliers.length"
                            type="button"
                            @click="showOutsourceModal = true"
                        >
                            Outsource to Supplier
                        </SecondaryButton>
                        <PrimaryButton
                            v-if="isWorkshop && isOpen"
                            type="button"
                            :disabled="completeForm.processing"
                            @click="submitComplete"
                        >
                            Mark Finished &amp; Reserve Stock
                        </PrimaryButton>
                    </div>
                </div>

                <!-- Waiting at workshop -> Send to showroom -->
                <div
                    v-if="job.waiting_at_workshop && destinations.length"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 px-4 py-3 text-sm text-emerald-950"
                >
                    <div>
                        <p class="font-semibold">Finished piece is reserved at {{ job.location?.name }}.</p>
                        <p class="text-xs text-emerald-800">
                            Create a stock transfer to move it to the selling showroom (its reservation travels with the transfer).
                        </p>
                    </div>
                    <form class="flex items-center gap-2" @submit.prevent="submitSend">
                        <SelectInput v-model="sendForm.to_location_id" class="text-xs">
                            <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }} ({{ d.code }})</option>
                        </SelectInput>
                        <PrimaryButton :disabled="sendForm.processing">Send to Showroom</PrimaryButton>
                    </form>
                </div>
                <InputError class="mt-2" :message="sendForm.errors.to_location_id || completeForm.errors.status" />
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <!-- Custom Specification Card -->
                    <Card title="Custom Specification & Sketches">
                        <template #actions>
                            <SecondaryButton v-if="job.spec && canManage" type="button" @click="showSpecModal = true">
                                Edit Spec / Approval
                            </SecondaryButton>
                        </template>

                        <div v-if="job.spec" class="space-y-4 text-sm">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <span class="block text-xs text-gray-500">Dimensions (W × D × H)</span>
                                    <span class="font-semibold text-gray-900">
                                        {{ [job.spec.width_mm, job.spec.depth_mm, job.spec.height_mm].filter(Boolean).join(' × ') || '—' }}
                                        <template v-if="job.spec.width_mm || job.spec.depth_mm || job.spec.height_mm">mm</template>
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-500">Material / Timber</span>
                                    <span class="font-semibold text-gray-900">{{ job.spec.material || '—' }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-500">Fabric / Colour</span>
                                    <span class="font-semibold text-gray-900">
                                        {{ [job.spec.fabric_code, job.spec.colour_code].filter(Boolean).join(' · ') || '—' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-500">Finish / Polish</span>
                                    <span class="font-semibold text-gray-900">{{ job.spec.finish || '—' }}</span>
                                </div>
                            </div>

                            <div v-if="job.spec.notes" class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700 whitespace-pre-line">
                                {{ job.spec.notes }}
                            </div>

                            <div v-if="job.spec.approved_on" class="flex flex-wrap items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50/50 px-3 py-2 text-xs text-emerald-900">
                                <span>
                                    Approved by <strong>{{ job.spec.approved_by_name }}</strong> on {{ job.spec.approved_on }}
                                </span>
                                <a v-if="job.spec.signed_quote_url" :href="job.spec.signed_quote_url" target="_blank" class="font-semibold underline">
                                    Signed Quote ↗
                                </a>
                            </div>

                            <div v-if="job.spec.attachments?.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <a
                                    v-for="att in job.spec.attachments"
                                    :key="att.index"
                                    :href="att.url"
                                    target="_blank"
                                    class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 p-1.5 hover:border-brand-400"
                                >
                                    <img v-if="att.thumb" :src="att.thumb" :alt="att.name" class="h-24 w-full rounded object-cover" />
                                    <div v-else class="flex h-24 items-center justify-center bg-white text-xs font-medium text-gray-600">
                                        Open File
                                    </div>
                                    <p class="mt-1 truncate text-[11px] text-gray-600">{{ att.name }}</p>
                                </a>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500">
                            Standard made-to-order item (no extra custom spec sheet attached).
                        </p>
                    </Card>

                    <!-- Workshop Stages OR Supplier Progress -->
                    <Card v-if="isWorkshop" title="Workshop Stage">
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="!job.stage_id && job.status === 'pending' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                :disabled="!isOpen || stageForm.processing"
                                @click="submitStageMove(null)"
                            >
                                Not started
                            </button>
                            <button
                                v-for="s in stages"
                                :key="s.id"
                                type="button"
                                class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
                                :class="job.stage_id === s.id ? 'bg-brand-600 text-white shadow-xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                :disabled="!isOpen || stageForm.processing"
                                @click="submitStageMove(s.id)"
                            >
                                {{ s.name }}
                            </button>
                        </div>
                        <InputError class="mt-2" :message="stageForm.errors.stage_id" />

                        <!-- Add note / stage photo -->
                        <form class="mt-4 border-t border-gray-100 pt-4" @submit.prevent="submitNote">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <InputLabel value="Add progress note or photo" />
                                    <TextInput v-model="noteForm.note" class="mt-1 block w-full text-sm" placeholder="Frame assembled, ready for upholstery..." />
                                </div>
                                <div>
                                    <InputLabel value="Photos (optional)" />
                                    <input
                                        type="file"
                                        multiple
                                        accept="image/png,image/jpeg,image/webp"
                                        class="mt-1.5 block w-full text-xs text-gray-600"
                                        @change="(e) => (noteForm.photos = Array.from(e.target.files ?? []))"
                                    />
                                </div>
                            </div>
                            <InputError class="mt-1" :message="noteForm.errors.note || noteForm.errors['photos.0']" />
                            <div class="mt-3 flex justify-end">
                                <SecondaryButton type="submit" :disabled="noteForm.processing">Post Note / Photo</SecondaryButton>
                            </div>
                        </form>
                    </Card>

                    <Card v-else title="Supplier Progress">
                        <div class="space-y-3 text-sm">
                            <div class="flex flex-wrap gap-6">
                                <div>
                                    <span class="block text-xs text-gray-500">Supplier</span>
                                    <span class="font-semibold text-gray-900">{{ job.supplier?.name || '—' }}</span>
                                </div>
                                <div v-if="job.supplier?.po_number">
                                    <span class="block text-xs text-gray-500">Purchase Order</span>
                                    <Link
                                        v-if="canManage && job.supplier.po_id"
                                        :href="route('purchasing.orders.show', job.supplier.po_id)"
                                        class="font-mono font-semibold text-brand-600 hover:underline"
                                    >
                                        {{ job.supplier.po_number }}
                                    </Link>
                                    <span v-else class="font-mono font-semibold">{{ job.supplier.po_number }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-500">Current status</span>
                                    <Badge tone="blue">{{ job.supplier_status || 'Ordered' }}</Badge>
                                </div>
                            </div>

                            <form v-if="isOpen" class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4" @submit.prevent="submitSupplierProgress">
                                <div>
                                    <InputLabel value="Update supplier status" />
                                    <SelectInput v-model="supplierProgressForm.supplier_status" class="mt-1 text-sm">
                                        <option v-for="st in supplierStatuses" :key="st.value" :value="st.value">{{ st.label }}</option>
                                    </SelectInput>
                                </div>
                                <div class="flex-1">
                                    <InputLabel value="Note" />
                                    <TextInput v-model="supplierProgressForm.note" class="mt-1 block w-full text-sm" placeholder="Expected dispatch Friday..." />
                                </div>
                                <PrimaryButton :disabled="supplierProgressForm.processing">Save Progress</PrimaryButton>
                            </form>
                            <p class="text-xs text-gray-500">
                                Note: Receiving the Purchase Order under Purchasing → Receive Goods automatically marks this job Ready and reserves the piece for the customer's order.
                            </p>
                        </div>
                    </Card>

                    <!-- Raw Materials Issue / Return (Workshop jobs) -->
                    <Card v-if="isWorkshop" title="Raw Materials (Issue & Return)">
                        <form v-if="isOpen" class="mb-5 rounded-xl border border-gray-200 bg-gray-50/70 p-4" @submit.prevent="submitMaterial">
                            <div class="mb-3 flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1 text-xs font-semibold"
                                    :class="materialMode === 'issue' ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300'"
                                    @click="materialMode = 'issue'"
                                >
                                    Issue material to job
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1 text-xs font-semibold"
                                    :class="materialMode === 'return' ? 'bg-amber-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300'"
                                    @click="materialMode = 'return'"
                                >
                                    Return unused material
                                </button>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                                <div class="relative sm:col-span-6">
                                    <InputLabel value="Scan barcode / SKU or search material" />
                                    <div v-if="selectedMaterial" class="mt-1 flex items-center justify-between rounded-lg border border-brand-200 bg-white px-3 py-2 text-xs">
                                        <div>
                                            <span class="font-semibold text-gray-900">{{ selectedMaterial.name }}</span>
                                            <span class="ml-1 font-mono text-gray-500">({{ selectedMaterial.sku }})</span>
                                        </div>
                                        <button type="button" class="text-brand-600 hover:underline" @click="selectedMaterial = null; materialForm.product_variant_id = null">
                                            Change
                                        </button>
                                    </div>
                                    <div v-else>
                                        <TextInput
                                            v-model="materialSearch"
                                            class="mt-1 block w-full text-sm"
                                            placeholder="Scan or type e.g. FAB-VL204, Teak..."
                                            @input="searchMaterials"
                                        />
                                        <div
                                            v-if="materialResults.length"
                                            class="absolute z-20 mt-1 max-h-52 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                                        >
                                            <button
                                                v-for="m in materialResults"
                                                :key="m.id"
                                                type="button"
                                                class="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-brand-50"
                                                @click="pickMaterial(m)"
                                            >
                                                <div>
                                                    <div class="font-semibold text-gray-900">{{ m.name }}</div>
                                                    <div class="font-mono text-gray-500">{{ m.sku }}</div>
                                                </div>
                                                <span class="font-mono text-gray-600">Avail: {{ m.available_label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="sm:col-span-3">
                                    <InputLabel :value="`Quantity (${selectedMaterial?.unit ?? 'units'})`" />
                                    <TextInput v-model="materialForm.quantity" class="mt-1 block w-full font-mono text-sm" placeholder="2.5" required />
                                </div>

                                <div class="flex items-end sm:col-span-3">
                                    <PrimaryButton class="w-full justify-center" :disabled="materialForm.processing">
                                        {{ materialMode === 'return' ? 'Return' : 'Issue' }}
                                    </PrimaryButton>
                                </div>
                            </div>
                            <InputError class="mt-2" :message="materialForm.errors.material || materialForm.errors.quantity" />
                        </form>

                        <table v-if="job.materials?.length" class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                                <tr>
                                    <th class="px-3 py-2">Material</th>
                                    <th class="px-3 py-2 text-right">Net Issued</th>
                                    <th v-if="job.cost !== null" class="px-3 py-2 text-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="m in job.materials" :key="m.variant_id">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900">{{ m.name }}</div>
                                        <div class="font-mono text-xs text-gray-500">{{ m.sku }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-gray-900">{{ m.net_label }}</td>
                                    <td v-if="job.cost !== null" class="px-3 py-2 text-right font-mono text-gray-700">{{ formatMoney(m.cost) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-500">No materials issued to this job yet.</p>
                    </Card>

                    <!-- Labour & Job Cost Card (Strictly hidden when job.cost === null) -->
                    <Card v-if="job.cost !== null" title="Labour & Job Cost">
                        <div class="grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-4">
                            <div>
                                <span class="block text-xs text-gray-500">Materials</span>
                                <span class="font-mono text-base font-bold text-gray-900">{{ formatMoney(job.cost.materials) }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-gray-500">Labour</span>
                                <span class="font-mono text-base font-bold text-gray-900">{{ formatMoney(job.cost.labour) }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-gray-500">Overhead ({{ (job.cost.overhead_bp / 100).toFixed(2) }}%)</span>
                                <span class="font-mono text-base font-bold text-gray-900">{{ formatMoney(job.cost.overhead) }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-gray-500">Total Job Cost</span>
                                <span class="font-mono text-lg font-bold text-brand-700">{{ formatMoney(job.cost.total) }}</span>
                            </div>
                        </div>

                        <div v-if="isWorkshop" class="mt-5">
                            <h4 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Logged Labour</h4>
                            <table v-if="job.labour?.length" class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Worker</th>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2 text-right">Hours × Rate</th>
                                        <th class="px-3 py-2 text-right">Amount</th>
                                        <th v-if="canManage && isOpen" class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="l in job.labour" :key="l.id">
                                        <td class="px-3 py-2 font-medium text-gray-900">
                                            {{ l.worker }}
                                            <span v-if="l.note" class="block text-xs font-normal text-gray-500">{{ l.note }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-xs text-gray-600">{{ l.date }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-xs text-gray-600">
                                            <template v-if="l.hours !== null">{{ l.hours }}h × {{ formatMoney(l.rate) }}</template>
                                            <template v-else>Fixed</template>
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono font-semibold text-gray-900">{{ formatMoney(l.amount) }}</td>
                                        <td v-if="canManage && isOpen" class="px-3 py-2 text-right">
                                            <button type="button" class="text-xs text-red-600 hover:underline" @click="removeLabour(l.id)">Remove</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="mt-2 text-xs text-gray-500">No labour logged yet.</p>

                            <form v-if="canManage && isOpen" class="mt-4 rounded-xl border border-gray-200 bg-gray-50/70 p-4" @submit.prevent="submitLabour">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Staff member (optional)" />
                                        <SelectInput v-model="labourForm.user_id" class="mt-1 block w-full text-xs" @change="onPickWorkerUser(labourForm.user_id)">
                                            <option value="">Custom name…</option>
                                            <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                        </SelectInput>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Worker name *" />
                                        <TextInput v-model="labourForm.worker_name" class="mt-1 block w-full text-xs" required placeholder="Sunil (Carpenter)" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Date *" />
                                        <TextInput v-model="labourForm.work_date" type="date" class="mt-1 block w-full text-xs" required />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Hours" />
                                        <TextInput v-model="labourForm.hours" type="number" step="0.25" min="0" class="mt-1 block w-full text-xs" placeholder="4.5" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Hourly rate (LKR)" />
                                        <TextInput v-model="labourForm.hourly_rate" class="mt-1 block w-full text-xs" placeholder="800.00" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Or fixed amount (LKR)" />
                                        <TextInput v-model="labourForm.amount" class="mt-1 block w-full text-xs" placeholder="3600.00" />
                                    </div>
                                </div>
                                <InputError class="mt-2" :message="labourForm.errors.labour || labourForm.errors.worker_name" />
                                <div class="mt-3 flex justify-end">
                                    <SecondaryButton type="submit" :disabled="labourForm.processing">Add Labour</SecondaryButton>
                                </div>
                            </form>
                        </div>
                    </Card>
                </div>

                <!-- Right Column: Job Assignment & Stage Log -->
                <div class="space-y-6">
                    <Card title="Assignment & Schedule">
                        <form v-if="canManage" class="space-y-4" @submit.prevent="saveJobDetails">
                            <div>
                                <InputLabel value="Priority" />
                                <SelectInput v-model="jobForm.priority" class="mt-1 block w-full text-sm">
                                    <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Due date" />
                                <TextInput v-model="jobForm.due_date" type="date" class="mt-1 block w-full text-sm" />
                            </div>
                            <div>
                                <InputLabel value="Assigned craftsman / lead" />
                                <SelectInput v-model="jobForm.assigned_to" class="mt-1 block w-full text-sm">
                                    <option value="">Unassigned</option>
                                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Workshop notes" />
                                <textarea
                                    v-model="jobForm.notes"
                                    rows="3"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm"
                                    placeholder="Internal workshop instructions..."
                                />
                            </div>
                            <PrimaryButton :disabled="jobForm.processing" class="w-full justify-center">Save Details</PrimaryButton>
                        </form>
                        <dl v-else class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Priority</dt>
                                <dd class="font-semibold text-gray-900">{{ job.priority_label }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Due date</dt>
                                <dd class="font-semibold text-gray-900">{{ job.due_date || '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Assigned to</dt>
                                <dd class="font-semibold text-gray-900">{{ job.assignee || 'Unassigned' }}</dd>
                            </div>
                            <div v-if="job.notes" class="border-t border-gray-100 pt-2 text-xs text-gray-700 whitespace-pre-line">
                                {{ job.notes }}
                            </div>
                        </dl>
                    </Card>

                    <Card title="Stage & Photo History">
                        <ul v-if="job.log?.length" class="space-y-3 text-xs">
                            <li v-for="entry in job.log" :key="entry.id" class="border-b border-gray-100 pb-3 last:border-b-0 last:pb-0">
                                <div class="flex items-center justify-between text-gray-500">
                                    <span class="font-semibold text-gray-800">
                                        <template v-if="entry.event === 'moved'">
                                            {{ entry.from || 'Not started' }} → {{ entry.to || 'Not started' }}
                                        </template>
                                        <template v-else>{{ entry.event }}</template>
                                    </span>
                                    <span>{{ entry.by || 'System' }}</span>
                                </div>
                                <p v-if="entry.note" class="mt-1 text-gray-700">{{ entry.note }}</p>
                                <div v-if="entry.photos?.length" class="mt-2 flex flex-wrap gap-2">
                                    <a v-for="(ph, idx) in entry.photos" :key="idx" :href="ph.url" target="_blank">
                                        <img :src="ph.thumb" class="size-16 rounded border border-gray-200 object-cover" />
                                    </a>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-gray-500">No history yet.</p>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Outsource Modal -->
        <Modal :show="showOutsourceModal" max-width="md" @close="showOutsourceModal = false">
            <form class="p-6" @submit.prevent="submitOutsource">
                <h3 class="text-base font-semibold text-gray-900">Outsource {{ job.number }} to Supplier</h3>
                <p class="mt-1 text-xs text-gray-500">
                    Creates a back-to-back Purchase Order draft and switches this job to supplier progress tracking.
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel value="Supplier *" />
                        <SelectInput v-model="outsourceForm.supplier_id" class="mt-1 block w-full" required>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Agreed unit cost (LKR) *" />
                        <TextInput v-model="outsourceForm.unit_cost" class="mt-1 block w-full font-mono" required />
                    </div>
                    <div>
                        <InputLabel value="Deliver to location *" />
                        <SelectInput v-model="outsourceForm.deliver_to_location_id" class="mt-1 block w-full" required>
                            <option :value="job.location?.id">{{ job.location?.name }} ({{ job.location?.code }})</option>
                            <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }} ({{ d.code }})</option>
                        </SelectInput>
                    </div>
                    <InputError :message="outsourceForm.errors.outsource" />
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" @click="showOutsourceModal = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="outsourceForm.processing">Create PO &amp; Outsource</PrimaryButton>
                </div>
            </form>
        </Modal>

        <CustomSpecModal
            :show="showSpecModal"
            :spec="job.spec"
            :line-name="job.title"
            :read-only="!canManage"
            @close="showSpecModal = false"
            @saved="router.reload({ only: ['job'] })"
        />
    </AdminLayout>
</template>

