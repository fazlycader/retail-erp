<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DragHandle from '@/Components/DragHandle.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import SortableList from '@/Components/SortableList.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import WorkshopNav from '@/Pages/Admin/Workshop/Partials/WorkshopNav.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    workshops: { type: Array, default: () => [] },
    selectedWorkshopId: { type: Number, default: null },
    stages: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    defaultTemplates: { type: Object, default: () => ({}) },
    recentSms: { type: Array, default: () => [] },
});
const templateLabels = {
    custom_confirmed: 'Custom order confirmed', custom_in_production: 'Custom order in production', custom_ready: 'Custom piece ready',
    delivery_scheduled: 'Delivery scheduled', delivery_out_for_delivery: 'Out for delivery', delivery_delivered: 'Delivery completed',
    installment_due: 'Installment due reminder', installment_overdue: 'Installment overdue reminder',
};
const templateEntries = computed(() => Object.entries(props.defaultTemplates).map(([key]) => [key, templateLabels[key] ?? key]));

const cloneData = (value) => JSON.parse(JSON.stringify(value ?? []));
const localStages = ref(cloneData(props.stages));
watch(
    () => props.stages,
    (next) => {
        localStages.value = cloneData(next);
    },
);

const switchWorkshop = (locationId) => {
    router.get(route('workshop.setup.index'), { location_id: locationId }, { preserveState: true });
};

const newStageForm = useForm({
    location_id: props.selectedWorkshopId ?? '',
    name: '',
});

const addStage = () => {
    newStageForm.location_id = props.selectedWorkshopId;
    newStageForm.post(route('workshop.setup.stages.store'), {
        preserveScroll: true,
        onSuccess: () => newStageForm.reset('name'),
    });
};

const toggleStage = (stage) => {
    router.put(
        route('workshop.setup.stages.update', stage.id),
        {
            name: stage.name,
            is_active: !stage.is_active,
        },
        { preserveScroll: true },
    );
};

const renameStage = (stage, newName) => {
    const clean = newName.trim();
    if (!clean || clean === stage.name) return;
    router.put(
        route('workshop.setup.stages.update', stage.id),
        {
            name: clean,
            is_active: stage.is_active,
        },
        { preserveScroll: true },
    );
};

const onReorderStages = (reordered) => {
    localStages.value = reordered;
    router.post(
        route('workshop.setup.stages.reorder'),
        {
            location_id: props.selectedWorkshopId,
            stage_ids: reordered.map((s) => s.id),
        },
        { preserveScroll: true },
    );
};

// Settings & SMS Templates form
const settingsForm = useForm({
    workshop_location_id: props.settings.workshop_location_id ?? '',
    overhead_percent: props.settings.overhead_percent ?? '0',
    workshop_lead_days: props.settings.workshop_lead_days ?? 14,
    sms_enabled: Boolean(props.settings.sms_enabled),
    sms_templates: {
        custom_confirmed: props.settings.sms_templates?.custom_confirmed ?? '',
        custom_in_production: props.settings.sms_templates?.custom_in_production ?? '',
        custom_ready: props.settings.sms_templates?.custom_ready ?? '',
        delivery_scheduled: props.settings.sms_templates?.delivery_scheduled ?? '',
        delivery_out_for_delivery: props.settings.sms_templates?.delivery_out_for_delivery ?? '',
        delivery_delivered: props.settings.sms_templates?.delivery_delivered ?? '',
        installment_due: props.settings.sms_templates?.installment_due ?? '',
        installment_overdue: props.settings.sms_templates?.installment_overdue ?? '',
    },
});

const saveSettings = () => {
    settingsForm
        .transform((d) => ({
            ...d,
            workshop_location_id: d.workshop_location_id === '' ? null : Number(d.workshop_location_id),
        }))
        .put(route('workshop.setup.settings'), { preserveScroll: true });
};

const resetTemplate = (key) => {
    if (props.defaultTemplates[key]) {
        settingsForm.sms_templates[key] = props.defaultTemplates[key];
    }
};
</script>

<template>
    <AdminLayout title="Workshop & SMS Setup">
        <WorkshopNav />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Stages per Workshop -->
            <Card title="Workshop Stages" description="Columns on the workshop jobs board. Drag to reorder or toggle active stages.">
                <div v-if="workshops.length > 1" class="mb-4">
                    <InputLabel value="Workshop location" />
                    <SelectInput :model-value="selectedWorkshopId" class="mt-1 block w-full text-sm" @change="(e) => switchWorkshop(e.target.value)">
                        <option v-for="w in workshops" :key="w.id" :value="w.id">{{ w.name }} ({{ w.code }})</option>
                    </SelectInput>
                </div>

                <SortableList v-if="localStages.length" :items="localStages" class="space-y-2" @reorder="onReorderStages">
                    <template #default="{ item: stage }">
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2">
                            <div class="flex flex-1 items-center gap-2">
                                <DragHandle />
                                <input
                                    :value="stage.name"
                                    type="text"
                                    class="w-full rounded border-transparent px-2 py-1 text-sm font-medium text-gray-900 hover:border-gray-200 focus:border-brand-500"
                                    @blur="(e) => renameStage(stage, e.target.value)"
                                />
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge :tone="stage.is_active ? 'green' : 'gray'">
                                    {{ stage.is_active ? 'Active' : 'Hidden' }}
                                </Badge>
                                <button type="button" class="text-xs font-semibold text-brand-600 hover:underline" @click="toggleStage(stage)">
                                    {{ stage.is_active ? 'Hide' : 'Enable' }}
                                </button>
                            </div>
                        </div>
                    </template>
                </SortableList>

                <form v-if="selectedWorkshopId" class="mt-4 flex gap-2 border-t border-gray-100 pt-4" @submit.prevent="addStage">
                    <TextInput v-model="newStageForm.name" class="flex-1 text-sm" placeholder="Add stage e.g. Carving, Sanding…" required />
                    <SecondaryButton type="submit" :disabled="newStageForm.processing">+ Add Stage</SecondaryButton>
                </form>
            </Card>

            <!-- Workshop Costing & Customer SMS Settings -->
            <Card title="Workshop Costing & Customer SMS">
                <form class="space-y-4" @submit.prevent="saveSettings">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel value="Default workshop" />
                            <SelectInput v-model="settingsForm.workshop_location_id" class="mt-1 block w-full text-sm">
                                <option value="">First active workshop</option>
                                <option v-for="w in workshops" :key="w.id" :value="w.id">{{ w.name }} ({{ w.code }})</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Overhead %" />
                            <TextInput v-model="settingsForm.overhead_percent" class="mt-1 block w-full font-mono text-sm" required />
                            <InputError class="mt-1" :message="settingsForm.errors.overhead_percent" />
                        </div>
                        <div>
                            <InputLabel value="Default lead time (days)" />
                            <TextInput v-model="settingsForm.workshop_lead_days" type="number" min="1" max="365" class="mt-1 block w-full font-mono text-sm" required />
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Send Customer SMS Updates</p>
                            <p class="text-xs text-gray-500">
                                Texts customers when their custom order is confirmed, enters production, and is ready. (Configure Notify.lk under Settings → Integrations.)
                            </p>
                        </div>
                        <Toggle v-model="settingsForm.sms_enabled" />
                    </div>

                    <div class="space-y-3 border-t border-gray-100 pt-4">
                        <p class="text-xs text-gray-500">
                            Available placeholders: <code class="font-mono">{company}</code>, <code class="font-mono">{customer}</code>, <code class="font-mono">{order}</code>, <code class="font-mono">{date}</code>, <code class="font-mono">{window}</code>, <code class="font-mono">{item}</code>, <code class="font-mono">{due}</code>, <code class="font-mono">{fulfilment}</code>, <code class="font-mono">{job}</code>
                        </p>
                        <div v-for="([key, label], index) in templateEntries" :key="key">
                            <div class="flex items-center justify-between"><InputLabel :value="`${index + 1}. ${label} SMS`"/><button type="button" class="text-[11px] text-gray-500 hover:underline" @click="resetTemplate(key)">Reset default</button></div>
                            <textarea v-model="settingsForm.sms_templates[key]" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-xs" required />
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <PrimaryButton :disabled="settingsForm.processing">Save Settings &amp; Templates</PrimaryButton>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Recent SMS Messages Log -->
        <Card v-if="recentSms.length" title="Recent Customer SMS Log" class="mt-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50 text-left font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-2">Template</th>
                            <th class="px-3 py-2">Customer / Phone</th>
                            <th class="px-3 py-2">Message</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="m in recentSms" :key="m.id">
                            <td class="px-3 py-2 font-mono text-gray-600">{{ m.template }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-900">{{ m.customer_name || '—' }}</div>
                                <div class="font-mono text-gray-500">{{ m.phone }}</div>
                            </td>
                            <td class="px-3 py-2 text-gray-700">{{ m.body }}</td>
                            <td class="px-3 py-2">
                                <Badge :tone="m.status === 'sent' ? 'green' : m.status === 'failed' ? 'red' : 'amber'">
                                    {{ m.status }}
                                </Badge>
                                <span v-if="m.error" class="ml-1 text-red-600">{{ m.error }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AdminLayout>
</template>
