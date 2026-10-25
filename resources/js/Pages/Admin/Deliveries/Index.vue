<script setup>
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    date: String, location: Object, locations: Array, deliveries: Array, runs: Array,
    orders: Array, zones: Array, vehicles: Array, drivers: Array, heldCollections: Array, shifts: Array,
    installations: Array, allZones: Array, canManage: Boolean,
});

const schedule = useForm({ sales_order_id: '', delivery_zone_id: '', scheduled_date: props.date, time_window: '09:00–12:00', address: '', contact_name: '', contact_phone: '', requires_installation: false });
const runForm = useForm({ location_id: props.location?.id ?? props.locations?.[0]?.id ?? '', loading_location_id: '', vehicle_id: '', driver_id: '', helper_names: '', run_date: props.date, delivery_ids: [], override_capacity: false });
const handover = useForm({ collection_ids: [], shift_id: '', counted_cash: '' });
const installationChecks = ref({});
const installationPhotos = ref({});
const draggingDeliveryId = ref(null);
let refreshTimer;
const zoneForm = useForm({ name: '', areas: '', fee_rule: 'flat', fee_cents: 0, free_above_cents: '', lead_days: 1 });
const vehicleForm = useForm({ location_id: props.location?.id ?? '', name: '', registration: '', capacity_items: '', capacity_cubic_mm: '', odometer: 0 });
const selectedOrder = computed(() => props.orders.find((o) => Number(o.id) === Number(schedule.sales_order_id)));
const selectedZone = computed(() => props.zones.find((z) => Number(z.id) === Number(schedule.delivery_zone_id)));
const zoneFee = computed(() => {
    if (!selectedZone.value) return 0;
    const zone = selectedZone.value;
    if (zone.fee_rule === 'free_above' && zone.free_above_cents !== null && selectedOrder.value?.grand_total >= zone.free_above_cents) return 0;
    return zone.fee_rule === 'order_value' ? Math.round((selectedOrder.value?.grand_total || 0) * zone.fee_cents / 10000) : zone.fee_cents;
});
const scheduleDelivery = () => schedule.post(route('deliveries.store'), { preserveScroll: true, onSuccess: () => schedule.reset('sales_order_id', 'delivery_zone_id', 'address', 'contact_name', 'contact_phone', 'requires_installation') });
const planRun = () => runForm.post(route('delivery-runs.store'), { preserveScroll: true, onSuccess: () => runForm.reset('delivery_ids', 'vehicle_id', 'driver_id', 'override_capacity') });
const dropOnVehicle = (vehicle, event) => {
    event.preventDefault();
    const id = Number(event.dataTransfer.getData('text/plain') || draggingDeliveryId.value);
    if (!id) return;
    runForm.vehicle_id = vehicle.id;
    runForm.delivery_ids = [...new Set([...runForm.delivery_ids.map(Number), id])];
    draggingDeliveryId.value = null;
};
const addZone = () => zoneForm.post(route('deliveries.zones.store'), { preserveScroll: true, onSuccess: () => zoneForm.reset('name', 'areas', 'fee_cents', 'free_above_cents', 'lead_days') });
const addVehicle = () => vehicleForm.post(route('deliveries.vehicles.store'), { preserveScroll: true, onSuccess: () => vehicleForm.reset('name', 'registration', 'capacity_items', 'capacity_cubic_mm', 'odometer') });
const loadRun = (run) => { const reading = prompt(`Starting odometer for ${run.vehicle}:`, String(run.start_odometer ?? '')); if (reading !== null) router.post(route('delivery-runs.load', run.id), { start_odometer: Number(reading) }, { preserveScroll: true }); };
const closeRun = (run) => { const reading = prompt(`Ending odometer for ${run.vehicle}:`); if (reading !== null) router.post(route('delivery-runs.close', run.id), { end_odometer: Number(reading) }, { preserveScroll: true }); };
const moveStop = (run, index, delta) => {
    const stops = [...run.stops];
    const next = index + delta;
    if (next < 0 || next >= stops.length) return;
    [stops[index], stops[next]] = [stops[next], stops[index]];
    router.post(route('delivery-runs.reorder', run.id), { stop_ids: stops.map((stop) => stop.id) }, { preserveScroll: true });
};
const saveHandover = () => handover.post(route('deliveries.handover'), { preserveScroll: true, onSuccess: () => handover.reset('collection_ids', 'counted_cash') });
const completeInstallation = (job) => {
    const checklist = String(installationChecks.value[job.id] || '').split(',').map((item) => item.trim()).filter(Boolean);
    const form = useForm({ checklist, photos: installationPhotos.value[job.id] || [] });
    form.post(route('installations.complete', job.id), { forceFormData: true, preserveScroll: true });
};
const setInstallationPhotos = (job, event) => { installationPhotos.value[job.id] = [...(event.target.files || [])].slice(0, 5); };
const shiftCash = computed(() => props.heldCollections.filter((c) => handover.collection_ids.includes(c.id) && c.method === 'cash').reduce((sum, c) => sum + c.amount, 0));
const changeDate = (delta) => { const d = new Date(`${props.date}T12:00:00`); d.setDate(d.getDate() + delta); router.get(route('deliveries.index'), { date: d.toISOString().slice(0, 10) }, { preserveState: true }); };
onMounted(() => { refreshTimer = window.setInterval(() => { if (document.visibilityState === 'visible') router.reload({ only: ['deliveries', 'runs', 'heldCollections', 'shifts', 'installations'], preserveState: true, preserveScroll: true }); }, 30000); });
onUnmounted(() => window.clearInterval(refreshTimer));
</script>

<template>
    <AdminLayout title="Delivery & Installation">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-lg font-semibold text-gray-900">Delivery calendar</h2><p class="text-sm text-gray-500">Schedule bulky orders, build runs and reconcile driver cash.</p></div>
            <div class="flex items-center gap-2"><button class="rounded-lg border px-3 py-2" @click="changeDate(-1)">‹</button><input class="rounded-lg border-gray-300 text-sm" type="date" :value="date" @change="(e) => router.get(route('deliveries.index'), { date: e.target.value })"><button class="rounded-lg border px-3 py-2" @click="changeDate(1)">›</button></div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <Card title="Schedule a delivery" description="Only confirmed orders marked for delivery with bulky items are listed.">
                <form class="space-y-3" @submit.prevent="scheduleDelivery">
                    <div><InputLabel value="Sales order"/><select v-model="schedule.sales_order_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm"><option value="">Choose an order…</option><option v-for="o in orders" :key="o.id" :value="o.id">{{ o.number }} · {{ o.customer }} · {{ o.requested_date || 'No requested date' }}</option></select><InputError :message="schedule.errors.sales_order_id"/></div>
                    <div class="grid gap-3 sm:grid-cols-2"><div><InputLabel value="Zone"/><select v-model="schedule.delivery_zone_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm"><option value="">No zone</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }} · {{ z.lead_days }} day lead</option></select></div><div><InputLabel value="Scheduled date"/><input v-model="schedule.scheduled_date" type="date" required class="mt-1 w-full rounded-lg border-gray-300 text-sm"/></div></div>
                    <p v-if="selectedZone" class="text-xs text-gray-600">Zone delivery fee: LKR {{ (zoneFee / 100).toFixed(2) }}. It is added to the sales order when scheduled.</p>
                    <div class="grid gap-3 sm:grid-cols-2"><div><InputLabel value="Time window"/><input v-model="schedule.time_window" class="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="09:00–12:00"/></div><div><InputLabel value="Contact phone"/><input v-model="schedule.contact_phone" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :placeholder="selectedOrder?.customer || 'Customer phone'"/></div></div>
                    <div><InputLabel value="Delivery address"/><textarea v-model="schedule.address" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :placeholder="selectedOrder?.address || 'Address'"/></div>
                    <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="schedule.requires_installation" type="checkbox" class="rounded border-gray-300"/> Installation required</label>
                    <InputError :message="schedule.errors.scheduled_date"/><PrimaryButton :disabled="schedule.processing || !orders.length">Schedule delivery</PrimaryButton>
                </form>
            </Card>

            <Card title="Plan a vehicle run" description="Choose the stops for this date and assign an active driver.">
                <form class="space-y-3" @submit.prevent="planRun">
                    <div><InputLabel value="Vehicle · drag a delivery onto a vehicle"/><div class="mt-1 grid gap-2 sm:grid-cols-2"><button v-for="v in vehicles" :key="v.id" type="button" class="rounded-lg border p-2 text-left text-xs" :class="Number(runForm.vehicle_id) === v.id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200'" @dragover.prevent @drop="dropOnVehicle(v, $event)" @click="runForm.vehicle_id = v.id">{{ v.name }} · {{ v.registration }}<span class="block text-gray-500">{{ v.capacity_items || 'No item limit' }} items · {{ v.capacity_cubic_mm ? `${v.capacity_cubic_mm} mm³` : 'No volume limit' }}</span><span v-if="Number(runForm.vehicle_id) === v.id" class="mt-1 block font-semibold text-indigo-700">Selected vehicle</span></button></div><InputError :message="runForm.errors.vehicle_id"/></div><div><InputLabel value="Driver"/><select v-model="runForm.driver_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm"><option value="">Choose…</option><option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
                    <div><InputLabel value="Helper names (optional)"/><input v-model="runForm.helper_names" class="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="Names of delivery helpers"/></div>
                    <div><InputLabel :value="`Stops scheduled for ${date}`"/><div class="mt-1 max-h-32 space-y-1 overflow-auto rounded-lg border p-2"><label v-for="d in deliveries.filter((x) => ['scheduled','rescheduled'].includes(x.status))" :key="d.id" draggable="true" class="flex cursor-grab items-center gap-2 text-sm" @dragstart="(event) => { draggingDeliveryId = d.id; event.dataTransfer.setData('text/plain', String(d.id)); }"><input v-model="runForm.delivery_ids" type="checkbox" :value="d.id" class="rounded border-gray-300"/>{{ d.number }} · {{ d.customer }} · {{ d.address }}<span v-if="runForm.delivery_ids.map(Number).includes(d.id)" class="ml-auto text-xs text-indigo-700">Added</span></label><p v-if="!deliveries.some((x) => ['scheduled','rescheduled'].includes(x.status))" class="p-2 text-xs text-gray-500">No unassigned deliveries for this date.</p></div></div>
                    <label v-if="canManage" class="flex items-center gap-2 text-sm text-amber-800"><input v-model="runForm.override_capacity" type="checkbox" class="rounded border-gray-300"/> Manager: allow run over vehicle capacity</label>
                    <InputError :message="runForm.errors.delivery_ids"/><PrimaryButton :disabled="runForm.processing || !runForm.delivery_ids.length">Create run</PrimaryButton>
                </form>
            </Card>
        </div>

        <Card class="mt-5" title="Runs" description="Stock is issued from the loading location when the run is loaded. Record odometer readings when loading and closing.">
            <div v-if="runs.length" class="divide-y divide-gray-100">
                <div v-for="run in runs" :key="run.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div><p class="font-mono text-sm font-bold text-gray-900">{{ run.number }} <span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium">{{ run.status }}</span></p><p class="mt-1 text-xs text-gray-600">{{ run.vehicle }} · {{ run.driver }}</p><div class="mt-2 flex flex-wrap gap-1"><span v-for="(stop, i) in run.stops" :key="stop.id" class="inline-flex items-center gap-1 rounded bg-gray-50 px-2 py-1 text-xs">{{ i + 1 }}. {{ stop.number }}<button v-if="canManage && run.status === 'planned'" class="px-1 text-indigo-700" :disabled="i === 0" aria-label="Move stop earlier" @click="moveStop(run, i, -1)">↑</button><button v-if="canManage && run.status === 'planned'" class="px-1 text-indigo-700" :disabled="i === run.stops.length - 1" aria-label="Move stop later" @click="moveStop(run, i, 1)">↓</button></span></div></div>
                    <div class="flex gap-2"><button v-if="run.status === 'planned'" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white" @click="loadRun(run)">Load stock</button><button v-if="['loaded','out_for_delivery'].includes(run.status)" class="rounded-lg border px-3 py-2 text-xs font-semibold" @click="closeRun(run)">Close run</button></div>
                </div>
            </div><p v-else class="py-5 text-sm text-gray-500">No runs planned for this day.</p>
        </Card>

        <Card class="mt-5" title="Scheduled stops" description="Delivery status, customer address, balance to collect and proof of delivery.">
            <div v-if="deliveries.length" class="grid gap-3 lg:grid-cols-2">
                <article v-for="d in deliveries" :key="d.id" class="rounded-xl border border-gray-200 p-4">
                    <div class="flex items-start justify-between gap-2"><div><p class="font-mono text-xs font-bold text-brand-700">{{ d.number }} · {{ d.order }}</p><h3 class="mt-1 font-semibold text-gray-900">{{ d.customer }}</h3></div><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold">{{ d.status.replaceAll('_',' ') }}</span></div>
                    <p class="mt-2 text-sm text-gray-700">{{ d.address }}</p><p class="text-xs text-gray-500">{{ d.contact_phone }} · {{ d.time_window || 'Any time' }} · {{ d.zone || 'No zone' }}</p>
                    <p class="mt-2 text-xs text-gray-600">{{ d.lines.map((l) => `${l.name} × ${l.quantity / 1000}`).join(', ') }}</p>
                    <p class="mt-2 text-sm font-semibold">Balance to collect: LKR {{ (d.balance_to_collect / 100).toFixed(2) }}</p>
                    <p v-if="d.failure_reason" class="mt-1 text-xs text-red-700">Failed: {{ d.failure_reason }}</p><p v-if="d.receiver_name" class="mt-1 text-xs text-emerald-700">Received by {{ d.receiver_name }}</p>
                </article>
            </div><p v-else class="py-5 text-sm text-gray-500">Nothing scheduled for this date.</p>
        </Card>

        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <Card title="Driver cash hand-over" description="Accepted cash is added to the selected cashier shift’s expected drawer cash.">
                <form v-if="heldCollections.length" class="space-y-3" @submit.prevent="saveHandover">
                    <label v-for="c in heldCollections" :key="c.id" class="flex items-center gap-2 border-b py-2 text-sm"><input v-model="handover.collection_ids" type="checkbox" :value="c.id" class="rounded border-gray-300"/><span class="flex-1">{{ c.driver }} · {{ c.delivery }} · {{ c.method }} {{ c.reference || '' }}</span><strong>LKR {{ (c.amount / 100).toFixed(2) }}</strong></label>
                    <div class="grid gap-3 sm:grid-cols-2"><select v-model="handover.shift_id" required class="rounded-lg border-gray-300 text-sm"><option value="">Cashier shift…</option><option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.user }} · {{ s.date }}</option></select><input v-model="handover.counted_cash" type="number" min="0" step="0.01" required class="rounded-lg border-gray-300 text-sm" placeholder="Cash counted (LKR)"/></div>
                    <p class="text-xs text-gray-500">Selected cash on record: LKR {{ (shiftCash / 100).toFixed(2) }}. Differences require manager approval.</p><InputError :message="handover.errors.collection_ids"/><PrimaryButton :disabled="handover.processing || !handover.collection_ids.length">Accept hand-over</PrimaryButton>
                </form><p v-else class="py-3 text-sm text-gray-500">No driver collections are waiting for hand-over.</p>
            </Card>
            <Card title="Installation jobs" description="Installation work scheduled alongside deliveries.">
                <div v-if="installations.length" class="divide-y"><div v-for="job in installations" :key="job.id" class="space-y-2 py-3 text-sm"><div class="flex justify-between"><span>{{ job.order }} · {{ job.customer }}</span><span class="rounded bg-gray-100 px-2 py-1 text-xs">{{ job.status }}</span></div><template v-if="canManage && job.status !== 'completed'"><input v-model="installationChecks[job.id]" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Completed checklist items (comma separated)"/><input type="file" accept="image/*" multiple class="block w-full text-xs" @change="setInstallationPhotos(job, $event)"/><button class="rounded-lg bg-indigo-700 px-3 py-2 text-xs font-semibold text-white" @click="completeInstallation(job)">Complete installation</button></template><div v-if="job.photos.length" class="text-xs text-gray-500">{{ job.photos.length }} completion photo(s) saved</div></div></div><p v-else class="py-3 text-sm text-gray-500">No installations scheduled for this day.</p>
            </Card>
        </div>

        <div v-if="canManage" class="mt-5 grid gap-5 xl:grid-cols-2">
            <Card title="Delivery zones" description="Flat fee, percentage of order value (basis points), free above an order value, and minimum lead time.">
                <div class="mb-3 flex flex-wrap gap-2"><span v-for="z in allZones" :key="z.id" class="rounded-full bg-gray-100 px-3 py-1 text-xs">{{ z.name }} · {{ z.lead_days }} day lead</span></div>
                <form class="grid gap-2 sm:grid-cols-2" @submit.prevent="addZone"><input v-model="zoneForm.name" required class="rounded-lg border-gray-300 text-sm" placeholder="Zone name"/><input v-model="zoneForm.areas" class="rounded-lg border-gray-300 text-sm" placeholder="Areas / districts, comma separated"/><select v-model="zoneForm.fee_rule" class="rounded-lg border-gray-300 text-sm"><option value="flat">Flat fee</option><option value="order_value">Percent of order value</option><option value="free_above">Free above threshold</option></select><input v-model="zoneForm.fee_cents" type="number" min="0" class="rounded-lg border-gray-300 text-sm" :placeholder="zoneForm.fee_rule === 'order_value' ? 'Rate in basis points (e.g. 500 = 5%)' : 'Fee (cents)'"/><input v-if="zoneForm.fee_rule === 'free_above'" v-model="zoneForm.free_above_cents" type="number" min="0" class="rounded-lg border-gray-300 text-sm" placeholder="Free above (cents)"/><input v-model="zoneForm.lead_days" type="number" min="0" max="60" class="rounded-lg border-gray-300 text-sm" placeholder="Lead days"/><button class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-semibold text-white sm:col-span-2">Add delivery zone</button></form>
            </Card>
            <Card title="Delivery vehicles" description="Vehicle capacity uses item count and/or product dimensions. Odometer is updated when each run closes.">
                <form class="grid gap-2 sm:grid-cols-2" @submit.prevent="addVehicle"><input v-model="vehicleForm.name" required class="rounded-lg border-gray-300 text-sm" placeholder="Vehicle name"/><input v-model="vehicleForm.registration" required class="rounded-lg border-gray-300 text-sm" placeholder="Registration"/><input v-model="vehicleForm.capacity_items" type="number" min="1" class="rounded-lg border-gray-300 text-sm" placeholder="Capacity in items"/><input v-model="vehicleForm.capacity_cubic_mm" type="number" min="1" class="rounded-lg border-gray-300 text-sm" placeholder="Capacity in cubic mm"/><input v-model="vehicleForm.odometer" type="number" min="0" class="rounded-lg border-gray-300 text-sm" placeholder="Odometer (km)"/><button class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-semibold text-white">Add vehicle</button></form>
            </Card>
        </div>
    </AdminLayout>
</template>
