<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StockNav from '@/Pages/Admin/Stock/Partials/StockNav.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    counts: Object,
    locations: Array,
    defaultLocationId: Number,
    categories: Array,
    scopes: Array,
});

const showCreate = ref(false);

const form = useForm({
    location_id: props.defaultLocationId ?? props.locations[0]?.id ?? '',
    scope: 'full',
    category_id: '',
    blind: true,
    note: '',
});

const submit = () => {
    form.post(route('stock.counts.store'));
};

const statusTone = (status) => {
    if (status === 'posted') return 'green';
    if (status === 'review') return 'amber';
    return 'indigo';
};
</script>

<template>
    <AdminLayout title="Stock counts">
        <StockNav>
            <PrimaryButton type="button" @click="showCreate = !showCreate">
                {{ showCreate ? 'Close' : 'Start stock count' }}
            </PrimaryButton>
        </StockNav>

        <Card
            v-if="showCreate"
            title="Start a physical stock count"
            description="System quantities are frozen when the count starts. Turn on blind counting so counters do not see expected quantities."
            class="mb-6"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel for="cnt-location" value="Location" />
                        <SelectInput id="cnt-location" v-model="form.location_id" class="mt-1 w-full" required>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.code }} — {{ loc.name }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.location_id" />
                    </div>
                    <div>
                        <InputLabel for="cnt-scope" value="Scope" />
                        <SelectInput id="cnt-scope" v-model="form.scope" class="mt-1 w-full" required>
                            <option v-for="sc in scopes" :key="sc.value" :value="sc.value">
                                {{ sc.label }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.scope" />
                    </div>
                    <div v-if="form.scope === 'category'">
                        <InputLabel for="cnt-category" value="Category" />
                        <SelectInput id="cnt-category" v-model="form.category_id" class="mt-1 w-full" required>
                            <option value="">Choose category…</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.label }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-1" :message="form.errors.category_id" />
                    </div>
                </div>

                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div>
                        <p class="text-sm font-medium text-gray-900">Blind count</p>
                        <p class="text-xs text-gray-500">Counters enter or scan what they see without seeing the system quantity until review.</p>
                    </div>
                    <Toggle v-model="form.blind" />
                </div>

                <div>
                    <InputLabel for="cnt-note" value="Note (optional)" />
                    <TextInput id="cnt-note" v-model="form.note" class="mt-1 w-full" placeholder="e.g. Monthly showroom stock take" />
                </div>

                <div class="flex justify-end">
                    <PrimaryButton :disabled="form.processing">Start count</PrimaryButton>
                </div>
            </form>
        </Card>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Count #</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Scope</th>
                            <th class="px-4 py-3 text-right">Items</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Started / Posted by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="c in counts.data" :key="c.id" class="hover:bg-gray-50/70">
                            <td class="px-4 py-3">
                                <Link :href="route('stock.counts.show', c.id)" class="font-semibold text-indigo-600 hover:underline">
                                    {{ c.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600">{{ c.business_date }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ c.location_code }} — {{ c.location_name }}</td>
                            <td class="px-4 py-3">
                                {{ c.scope_label }}
                                <span v-if="c.category_name" class="text-xs text-gray-500">({{ c.category_name }})</span>
                                <Badge v-if="c.blind" tone="slate" class="ml-1">Blind</Badge>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ c.lines_count }}</td>
                            <td class="px-4 py-3">
                                <Badge :tone="statusTone(c.status)">{{ c.status_label }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <div>Started: {{ c.started_by_name || '—' }}</div>
                                <div v-if="c.posted_by_name" class="text-emerald-700">Posted: {{ c.posted_by_name }}</div>
                            </td>
                        </tr>
                        <tr v-if="counts.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                No stock counts started yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                <Pagination :links="counts.links" />
            </div>
        </Card>
    </AdminLayout>
</template>

