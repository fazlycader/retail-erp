<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { postJson } from '@/lib/http';

const props = defineProps({ unlocked: Boolean, hasPin: Boolean, run: Object, collections: Array });
const pin = ref('');
const pinError = ref('');
const pending = ref([]);
const online = ref(navigator.onLine);
const message = ref('');
const actionBusy = ref(false);
const proofStop = ref(null);
const receiver = ref('');
const signatureCanvas = ref(null);
const drawing = ref(false);
const hasSignature = ref(false);
const photos = ref([]);
const collectStop = ref(null);
const collectAmount = ref('');
const collectMethod = ref('cash');
const collectReference = ref('');
const activeStops = computed(() => props.run?.stops ?? []);
const queueKey = 'retail-erp-driver-actions-v1';

const readQueue = () => {
    try { pending.value = JSON.parse(localStorage.getItem(queueKey) || '[]'); } catch { pending.value = []; }
};
const saveQueue = () => localStorage.setItem(queueKey, JSON.stringify(pending.value));
const queueAction = (deliveryId, action, data = {}) => {
    pending.value.push({ action_id: crypto.randomUUID(), delivery_id: deliveryId, action, ...data });
    saveQueue();
};
const flushQueue = async () => {
    if (!navigator.onLine || actionBusy || !pending.value.length) return;
    actionBusy.value = true;
    online.value = true;
    try {
        while (pending.value.length && navigator.onLine) {
            const item = pending.value[0];
            const { ok, status, data } = await postJson(route('driver.actions'), item);
            if (!ok) {
                message.value = data?.message ?? `Queued action needs attention (${status}).`;
                if (status !== 0) break;
                online.value = false;
                break;
            }
            pending.value.shift();
            saveQueue();
            message.value = `Synced ${item.action} for delivery ${item.delivery_id}.`;
        }
        if (!pending.value.length) router.reload({ only: ['run', 'collections'], preserveScroll: true });
    } finally { actionBusy.value = false; }
};
const setOnline = () => { online.value = navigator.onLine; if (online.value) flushQueue(); };
onMounted(() => { readQueue(); window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline); flushQueue(); });
onUnmounted(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });

const unlock = () => router.post(route('driver.unlock'), { pin: pin.value }, { onError: (e) => { pinError.value = e.pin; }, onSuccess: () => { pinError.value = ''; pin.value = ''; } });
const startRun = () => router.post(route('driver.runs.start', props.run.id));
const sendSimple = async (stop, action, data = {}) => {
    message.value = '';
    if (!navigator.onLine) { queueAction(stop.id, action, data); message.value = 'Saved on this device. It will sync in order when you are back online.'; return; }
    actionBusy.value = true;
    const item = { action_id: crypto.randomUUID(), delivery_id: stop.id, action, ...data };
    const { ok, status, data: result } = await postJson(route('driver.actions'), item);
    actionBusy.value = false;
    if (!ok) {
        if (status === 0) { pending.value.push(item); saveQueue(); online.value = false; message.value = 'Connection lost. Saved on this device and will sync in order.'; }
        else message.value = result?.message || 'Could not save this action.';
        return;
    }
    message.value = action === 'arrived' ? 'Arrival recorded.' : action === 'failed' ? 'Failure recorded; stock returned to the loading location.' : 'Payment recorded.';
    router.reload({ only: ['run', 'collections'], preserveScroll: true });
};
const markFailed = (stop) => { const reason = prompt('Why could this delivery not be completed?'); if (reason?.trim()) sendSimple(stop, 'failed', { reason: reason.trim() }); };
const openProof = (stop) => { proofStop.value = stop; receiver.value = stop.customer || ''; hasSignature.value = false; photos.value = []; nextTickCanvas(); };
const nextTickCanvas = () => requestAnimationFrame(() => { const canvas = signatureCanvas.value; if (!canvas) return; const rect = canvas.getBoundingClientRect(); const scale = window.devicePixelRatio || 1; canvas.width = rect.width * scale; canvas.height = rect.height * scale; const ctx = canvas.getContext('2d'); ctx.scale(scale, scale); ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827'; });
const point = (event) => { const r = signatureCanvas.value.getBoundingClientRect(); return { x: event.clientX - r.left, y: event.clientY - r.top }; };
const startDraw = (event) => { drawing.value = true; signatureCanvas.value.setPointerCapture(event.pointerId); const p = point(event); const c = signatureCanvas.value.getContext('2d'); c.beginPath(); c.moveTo(p.x, p.y); };
const draw = (event) => { if (!drawing.value) return; const p = point(event); const c = signatureCanvas.value.getContext('2d'); c.lineTo(p.x, p.y); c.stroke(); hasSignature.value = true; };
const stopDraw = () => { drawing.value = false; };
const clearSignature = () => { const c = signatureCanvas.value; c?.getContext('2d').clearRect(0, 0, c.width, c.height); hasSignature.value = false; };
const fileData = (file) => new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); });
const addPhotos = async (event) => { const chosen = [...(event.target.files || [])].slice(0, 5 - photos.value.length); photos.value.push(...await Promise.all(chosen.map(fileData))); event.target.value = ''; };
const submitProof = () => {
    if (!receiver.value.trim() || !hasSignature.value) { message.value = 'Enter the receiver name and capture their signature.'; return; }
    const data = { receiver_name: receiver.value.trim(), signature: signatureCanvas.value.toDataURL('image/png'), photos: photos.value };
    sendSimple(proofStop.value, 'delivered', data);
    proofStop.value = null;
};
const submitCollection = () => {
    const cents = Math.round(Number(collectAmount.value) * 100);
    if (!Number.isFinite(cents) || cents <= 0) { message.value = 'Enter a valid collection amount.'; return; }
    sendSimple(collectStop.value, 'collect', { amount: cents, method: collectMethod.value, reference: collectReference.value });
    collectStop.value = null;
};
const maps = (address) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
</script>

<template>
    <Head><link rel="manifest" href="/driver.webmanifest"/><meta name="theme-color" content="#3730a3"/><meta name="apple-mobile-web-app-capable" content="yes"/></Head>
    <AdminLayout title="My delivery route">
        <div class="mx-auto max-w-3xl">
            <section v-if="!unlocked" class="mx-auto mt-12 max-w-sm rounded-2xl border bg-white p-6 shadow-sm">
                <h1 class="text-xl font-bold text-gray-900">Unlock driver route</h1><p class="mt-1 text-sm text-gray-500">Enter your staff PIN to view today’s stops.</p>
                <form class="mt-5 space-y-3" @submit.prevent="unlock"><input v-model="pin" type="password" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="w-full rounded-xl border-gray-300 text-center text-2xl tracking-[0.4em]" placeholder="••••"/><p v-if="pinError" class="text-sm text-red-600">{{ pinError }}</p><button class="min-h-12 w-full rounded-xl bg-indigo-700 px-4 font-semibold text-white">Unlock</button></form>
                <p v-if="!hasPin" class="mt-3 text-xs text-amber-700">Ask a manager to set a staff PIN before using this device.</p>
            </section>

            <template v-else>
                <div class="mb-4 flex items-center justify-between rounded-xl border bg-white px-4 py-3"><div><p class="font-semibold text-gray-900">{{ run?.number || 'Today’s route' }}</p><p class="text-xs text-gray-500">{{ run?.vehicle || 'No vehicle assigned' }} · {{ online ? 'Online' : 'Offline' }}<span v-if="pending.length"> · {{ pending.length }} queued</span></p></div><button v-if="run?.status === 'loaded'" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white" @click="startRun">Start route</button><button class="text-xs text-gray-500 underline" @click="router.post(route('logout'))">Lock / sign out</button></div>
                <div v-if="message" class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">{{ message }}</div>
                <div v-if="!run" class="rounded-2xl border bg-white p-8 text-center"><h2 class="font-semibold text-gray-900">No run assigned today</h2><p class="mt-1 text-sm text-gray-500">Ask dispatch to add you to a delivery run.</p></div>
                <div v-else class="space-y-4">
                    <article v-for="(stop, i) in activeStops" :key="stop.id" class="rounded-2xl border bg-white p-4 shadow-sm">
                        <div class="flex items-start gap-3"><span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-800">{{ i + 1 }}</span><div class="min-w-0 flex-1"><div class="flex justify-between gap-2"><div><p class="font-mono text-xs font-bold text-indigo-700">{{ stop.number }}</p><h2 class="mt-1 text-lg font-semibold text-gray-900">{{ stop.customer }}</h2></div><span class="h-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold">{{ stop.status.replaceAll('_',' ') }}</span></div><p class="mt-2 text-sm text-gray-700">{{ stop.address }}</p><p class="text-xs text-gray-500">{{ stop.window || 'Any time' }}</p><p class="mt-2 font-semibold text-gray-900">Balance due LKR {{ (stop.balance / 100).toFixed(2) }}</p>
                            <div class="mt-3 flex flex-wrap gap-2"><a :href="`tel:${stop.phone}`" class="rounded-lg border px-3 py-2 text-sm font-semibold">Call customer</a><a :href="maps(stop.address)" target="_blank" rel="noopener" class="rounded-lg border px-3 py-2 text-sm font-semibold">Open Maps</a><button v-if="['loaded','out_for_delivery'].includes(stop.status)" class="rounded-lg border px-3 py-2 text-sm font-semibold" @click="sendSimple(stop, 'arrived')">Arrived</button><button v-if="['loaded','out_for_delivery'].includes(stop.status)" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white" @click="openProof(stop)">Delivered</button><button v-if="['loaded','out_for_delivery'].includes(stop.status)" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-700" @click="markFailed(stop)">Failed</button><button v-if="stop.balance > 0 && ['loaded','out_for_delivery'].includes(stop.status)" class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white" @click="collectStop = stop; collectAmount = (stop.balance / 100).toFixed(2); collectReference = ''">Collect balance</button></div>
                        </div></div>
                    </article>
                    <p v-if="!activeStops.length" class="rounded-xl border bg-white p-6 text-sm text-gray-500">This run has no stops.</p>
                </div>

                <section class="mt-5 rounded-2xl border bg-white p-4"><h2 class="font-semibold text-gray-900">Collections held by you</h2><p v-if="!collections.length" class="mt-2 text-sm text-gray-500">No money to hand over.</p><div v-for="c in collections" :key="c.id" class="flex justify-between border-t py-2 text-sm"><span>{{ c.delivery }} · {{ c.method }} {{ c.reference || '' }}</span><strong>LKR {{ (c.amount / 100).toFixed(2) }}</strong></div><p v-if="collections.length" class="mt-2 text-xs text-gray-500">Hand this money to the cashier before ending your shift.</p></section>
            </template>
        </div>

        <div v-if="proofStop" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-2 sm:items-center"><section class="max-h-[95vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5"><div class="flex justify-between"><h2 class="text-lg font-bold">Proof of delivery</h2><button @click="proofStop = null">✕</button></div><label class="mt-4 block text-sm font-medium">Receiver name<input v-model="receiver" class="mt-1 min-h-11 w-full rounded-lg border-gray-300"/></label><p class="mt-4 text-sm font-medium">Customer signature</p><canvas ref="signatureCanvas" class="mt-1 h-40 w-full touch-none rounded-lg border border-gray-300" @pointerdown="startDraw" @pointermove="draw" @pointerup="stopDraw" @pointercancel="stopDraw"/><button class="mt-1 text-xs text-indigo-700 underline" @click="clearSignature">Clear signature</button><label class="mt-4 block text-sm font-medium">Delivery photos (up to 5)<input class="mt-1 block w-full text-sm" type="file" accept="image/*" capture="environment" multiple @change="addPhotos"/></label><p v-if="photos.length" class="mt-1 text-xs text-gray-500">{{ photos.length }} photo(s) selected. They remain queued on this device while offline.</p><div class="mt-5 flex gap-2"><button class="min-h-11 flex-1 rounded-lg border" @click="proofStop = null">Cancel</button><button class="min-h-11 flex-1 rounded-lg bg-emerald-700 font-semibold text-white" @click="submitProof">Save delivery</button></div></section></div>

        <div v-if="collectStop" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-2 sm:items-center"><section class="w-full max-w-md rounded-2xl bg-white p-5"><h2 class="text-lg font-bold">Collect customer balance</h2><p class="mt-1 text-sm text-gray-500">{{ collectStop.number }} · LKR {{ (collectStop.balance / 100).toFixed(2) }} due</p><label class="mt-4 block text-sm">Amount (LKR)<input v-model="collectAmount" type="number" min="0.01" step="0.01" class="mt-1 min-h-11 w-full rounded-lg border-gray-300"/></label><label class="mt-3 block text-sm">Method<select v-model="collectMethod" class="mt-1 min-h-11 w-full rounded-lg border-gray-300"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option></select></label><label v-if="collectMethod === 'bank_transfer'" class="mt-3 block text-sm">Transfer reference<input v-model="collectReference" class="mt-1 min-h-11 w-full rounded-lg border-gray-300"/></label><div class="mt-5 flex gap-2"><button class="min-h-11 flex-1 rounded-lg border" @click="collectStop = null">Cancel</button><button class="min-h-11 flex-1 rounded-lg bg-indigo-700 font-semibold text-white" @click="submitCollection">Record collection</button></div></section></div>
    </AdminLayout>
</template>
