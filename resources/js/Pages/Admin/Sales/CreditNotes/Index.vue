<script setup>
import Pagination from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalesNav from '@/Pages/Admin/Sales/Partials/SalesNav.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    creditNotes: { type: Object, required: true },
    filters: { type: Object, required: true },
    refundMethods: { type: Array, default: () => [] },
    approvers: { type: Array, default: () => [] },
    canOverride: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');
function applyFilters() {
    router.get(route('sales.credit-notes.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}

const refunding = ref(null);
const form = useForm({ payment_method_id: '', amount: '', reason: '', reference: '', approver_id: '', pin: '' });

function openRefund(note) {
    form.reset();
    form.clearErrors();
    form.payment_method_id = props.refundMethods[0]?.id ?? '';
    form.amount = (note.remaining / 100).toFixed(2);
    refunding.value = note;
}

function submit() {
    form.post(route('sales.credit-notes.refund', refunding.value.id), { preserveScroll: true, onSuccess: () => (refunding.value = null) });
}
</script>

<template>
    <Head title="Credit notes" />

    <AdminLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <SalesNav />

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Credit notes</h1>
                    <p class="text-sm text-gray-500">Issued from returns and cancelled orders. Customers pay with them at the till; a manager can refund them.</p>
                </div>
                <form @submit.prevent="applyFilters">
                    <input v-model="search" type="search" placeholder="Number or customer…" class="w-64 rounded-lg border-gray-300 text-sm" />
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Credit note</th>
                            <th class="px-4 py-3">Customer / order</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Remaining / amount</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!creditNotes.data.length"><td colspan="5" class="px-4 py-8 text-center text-gray-500">No credit notes.</td></tr>
                        <tr v-for="n in creditNotes.data" :key="n.id">
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs font-semibold">{{ n.number }}</div>
                                <div class="text-xs text-gray-500">{{ n.location_code }} · {{ n.reason }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div>{{ n.customer || 'Walk-in' }}</div>
                                <Link v-if="n.order_id" :href="route('sales.orders.show', n.order_id)" class="font-mono text-indigo-600 hover:underline">{{ n.order_number }}</Link>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-medium">{{ n.status_label }}</div>
                                <div class="text-gray-500">{{ n.expires_on ? `Until ${n.expires_on}` : 'No expiry' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                <span class="font-semibold">{{ formatMoney(n.remaining) }}</span>
                                <span class="text-xs text-gray-500"> / {{ formatMoney(n.amount) }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-xs whitespace-nowrap">
                                <a :href="n.pdf_url" target="_blank" class="text-indigo-600 hover:underline">PDF ↗</a>
                                <button v-if="canManage && n.usable" type="button" class="ml-3 text-red-600 hover:underline" @click="openRefund(n)">Refund</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4"><Pagination :paginator="creditNotes" /></div>

            <div v-if="refunding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <form class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submit">
                    <h2 class="text-base font-bold text-gray-900">Refund {{ refunding.number }}</h2>
                    <p class="mt-1 text-xs text-gray-500">{{ formatMoney(refunding.remaining) }} remaining. Cash refunds will need an open shift once cash management is live.</p>
                    <div class="mt-4 space-y-3">
                        <select v-model="form.payment_method_id" class="w-full rounded-lg border-gray-300 text-sm">
                            <option v-for="m in refundMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <input v-model="form.amount" inputmode="decimal" class="w-full rounded-lg border-gray-300 font-mono text-sm" />
                        <input v-model="form.reason" placeholder="Reason" required class="w-full rounded-lg border-gray-300 text-sm" />
                        <input v-model="form.reference" placeholder="Reference (optional)" class="w-full rounded-lg border-gray-300 text-sm" />
                        <div v-if="!canOverride" class="rounded border border-amber-200 bg-amber-50 p-3 text-xs">
                            <div class="font-semibold text-amber-900">Manager approval required</div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <select v-model="form.approver_id" class="rounded border-gray-300 text-xs">
                                    <option value="">Select manager…</option>
                                    <option v-for="m in approvers" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                                <input v-model="form.pin" type="password" inputmode="numeric" placeholder="PIN" class="rounded border-gray-300 text-xs" />
                            </div>
                        </div>
                        <p v-if="form.errors.amount || form.errors.pin" class="text-xs text-red-600">{{ form.errors.amount || form.errors.pin }}</p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-xs" @click="refunding = null">Close</button>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-semibold text-white">Record refund</button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
