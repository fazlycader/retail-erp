<script setup>
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CashNav from '@/Pages/Admin/Cash/Partials/CashNav.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    registers: Array,
    categories: Array,
    settings: Object, // null for managers: company-wide settings are admin-only
});

const page = usePage();
const location = computed(() => page.props.location);
const appliesTo = { any: 'Any', pay_in: 'Pay-in', pay_out: 'Pay-out', cash_drop: 'Cash drop' };

const settingsForm = props.settings ? useForm({ ...props.settings }) : null;
const saveSettings = () => settingsForm.put(route('cash.setup.settings'), { preserveScroll: true });

const registerForm = useForm({ name: '', is_active: true });
const addRegister = () => registerForm.post(route('cash.setup.registers.store'), { preserveScroll: true, onSuccess: () => registerForm.reset() });
const saveRegister = (r) => useForm({ name: r.name, is_active: r.is_active }).put(route('cash.setup.registers.update', r.id), { preserveScroll: true });

const categoryForm = useForm({ name: '', applies_to: 'pay_out', is_active: true });
const addCategory = () => categoryForm.post(route('cash.setup.categories.store'), { preserveScroll: true, onSuccess: () => categoryForm.reset() });
const saveCategory = (c) => useForm({ name: c.name, applies_to: c.applies_to, is_active: c.is_active }).put(route('cash.setup.categories.update', c.id), { preserveScroll: true });
</script>

<template>
    <AdminLayout title="Registers & settings">
        <div class="mx-auto max-w-4xl space-y-5">
            <CashNav />

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Registers at {{ location?.name }}</h2>
                <p class="text-sm text-gray-500">Each till has its own shift and drawer. A device remembers its register when a shift is opened on it.</p>
                <ul class="mt-3 divide-y divide-gray-100">
                    <li v-for="r in registers" :key="r.id" class="flex items-center gap-3 py-2">
                        <input v-model="r.name" class="flex-1 rounded-lg border-gray-300 text-sm" maxlength="60" aria-label="Register name" />
                        <label class="flex items-center gap-2 text-sm text-gray-600"><Toggle v-model="r.is_active" /> Active</label>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-300" @click="saveRegister(r)">Save</button>
                    </li>
                </ul>
                <form class="mt-3 flex gap-2" @submit.prevent="addRegister">
                    <input v-model="registerForm.name" required maxlength="60" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="New register, e.g. Second Counter" />
                    <button class="rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white" :disabled="registerForm.processing">Add</button>
                </form>
                <p v-if="registerForm.errors.name" class="mt-1 text-xs text-red-600">{{ registerForm.errors.name }}</p>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Cash movement categories</h2>
                <p class="text-sm text-gray-500">Shared by every location. Pick one when recording a pay-in, pay-out or drop.</p>
                <ul class="mt-3 divide-y divide-gray-100">
                    <li v-for="c in categories" :key="c.id" class="flex flex-wrap items-center gap-3 py-2">
                        <input v-model="c.name" class="min-w-40 flex-1 rounded-lg border-gray-300 text-sm" maxlength="60" aria-label="Category name" />
                        <select v-model="c.applies_to" class="rounded-lg border-gray-300 text-sm" aria-label="Used for">
                            <option v-for="(label, value) in appliesTo" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm text-gray-600"><Toggle v-model="c.is_active" /> Active</label>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-300" @click="saveCategory(c)">Save</button>
                    </li>
                </ul>
                <form class="mt-3 flex flex-wrap gap-2" @submit.prevent="addCategory">
                    <input v-model="categoryForm.name" required maxlength="60" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="New category" />
                    <select v-model="categoryForm.applies_to" class="rounded-lg border-gray-300 text-sm">
                        <option v-for="(label, value) in appliesTo" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <button class="rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white" :disabled="categoryForm.processing">Add</button>
                </form>
                <p v-if="categoryForm.errors.name" class="mt-1 text-xs text-red-600">{{ categoryForm.errors.name }}</p>
            </section>

            <section v-if="settingsForm" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-semibold text-gray-900">Shift settings (all locations)</h2>
                <form class="mt-3 space-y-4" @submit.prevent="saveSettings">
                    <label class="flex items-center justify-between gap-4">
                        <span><span class="block text-sm font-medium text-gray-900">Payments need an open shift</span><span class="text-sm text-gray-500">Payments and cash refunds are refused without one.</span></span>
                        <Toggle v-model="settingsForm.require_shift_for_payments" />
                    </label>
                    <label class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
                        <span><span class="block text-sm font-medium text-gray-900">Blind close</span><span class="text-sm text-gray-500">Cashiers count the drawer before they see what it should hold.</span></span>
                        <Toggle v-model="settingsForm.blind_close" />
                    </label>
                    <div class="grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-gray-900">Differences above this need a manager (LKR)
                            <input v-model="settingsForm.variance_approval_threshold" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                            <span v-if="settingsForm.errors.variance_approval_threshold" class="text-xs text-red-600">{{ settingsForm.errors.variance_approval_threshold }}</span>
                        </label>
                        <label class="text-sm font-medium text-gray-900">Pay-outs above this need a manager's PIN (LKR)
                            <input v-model="settingsForm.payout_approval_threshold" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300" />
                            <span v-if="settingsForm.errors.payout_approval_threshold" class="text-xs text-red-600">{{ settingsForm.errors.payout_approval_threshold }}</span>
                        </label>
                    </div>
                    <div class="flex justify-end"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="settingsForm.processing">Save settings</button></div>
                </form>
            </section>
        </div>
    </AdminLayout>
</template>
