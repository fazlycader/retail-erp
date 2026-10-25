<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { postJson } from '@/lib/http';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Admins. Credentials are write-only: secrets show masked, an edit starts from an empty box, every
 * change can be undone one step.
 */
defineProps({
    groups: Array,
    webhookUrl: String,
});

const editing = ref(null);
const form = useForm({ value: '' });
const open = ref({});
const test = ref(null);

const edit = (key) => {
    editing.value = key;
    form.reset();
    form.clearErrors();
};
const save = () => form.put(route('integrations.update', editing.value), { preserveScroll: true, onSuccess: () => ((editing.value = null), form.reset()) });
const clear = (key) => confirm('Remove this value? "Undo" can put it back.') && router.delete(route('integrations.clear', key), { preserveScroll: true });
const undo = (key) => router.post(route('integrations.undo', key), {}, { preserveScroll: true });
const testWoo = async () => {
    test.value = { busy: true };
    const { ok, data } = await postJson(route('integrations.test'));
    test.value = { ok, message: data.message ?? 'Something went wrong.' };
};
const stateLabel = { configured: 'Configured', partial: 'Partly set up', not_configured: 'Not set up' };
const stateClass = { configured: 'bg-emerald-100 text-emerald-800', partial: 'bg-amber-100 text-amber-800', not_configured: 'bg-gray-100 text-gray-700' };
</script>

<template>
    <AdminLayout title="Integrations">
        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-600">Keys and tokens this system uses to talk to other services. Values are never shown back in full: to change one, type the new value. Every change can be undone one step.</p>
                <Link :href="route('settings.edit')" class="text-sm font-semibold text-indigo-700">← Settings</Link>
            </div>

            <section v-for="group in groups" :key="group.key" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">{{ group.label }}</h2>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="stateClass[group.state]">{{ stateLabel[group.state] }}</span>
                    <button v-if="group.key === 'woocommerce'" type="button" class="ml-auto rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-300 disabled:opacity-50" :disabled="test?.busy" @click="testWoo">Test connection</button>
                </div>
                <p class="mt-1 text-sm text-gray-500">{{ group.description }}</p>
                <p v-if="test && !test.busy && group.key === 'woocommerce'" class="mt-2 rounded-lg px-3 py-2 text-sm" :class="test.ok ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-800'">{{ test.message }}</p>
                <p v-if="group.key === 'woocommerce'" class="mt-2 text-xs text-gray-500">Webhook delivery URL for WooCommerce: <code class="select-all">{{ webhookUrl }}</code></p>

                <ul class="mt-4 divide-y divide-gray-100">
                    <li v-for="c in group.credentials" :key="c.key" class="py-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900">{{ c.description }} <code class="ml-1 text-xs text-gray-400">{{ c.key }}</code></p>
                                <p class="text-xs text-gray-500">
                                    <span v-if="c.set" class="font-mono">{{ c.masked }}</span><span v-else class="text-amber-700">Not set</span>
                                    <template v-if="c.updated_by"> · {{ c.updated_by }}</template>
                                </p>
                            </div>
                            <button type="button" class="text-sm font-semibold text-indigo-700" @click="open[c.key] = !open[c.key]">How do I get this?</button>
                            <button type="button" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white" @click="edit(c.key)">{{ c.set ? 'Change' : 'Set' }}</button>
                            <button v-if="c.set" type="button" class="text-sm text-red-700" @click="clear(c.key)">Clear</button>
                            <button v-if="c.can_undo" type="button" class="text-sm text-gray-600" @click="undo(c.key)">Undo</button>
                        </div>
                        <!-- eslint-disable-next-line vue/no-v-html -- server renders markdown with HTML escaped -->
                        <div v-if="open[c.key]" class="prose prose-sm mt-2 max-w-none rounded-lg bg-gray-50 p-3 text-gray-700" v-html="c.guide" />
                        <form v-if="editing === c.key" class="mt-3 flex flex-wrap gap-2" @submit.prevent="save">
                            <input v-model="form.value" :type="c.secret ? 'password' : 'text'" autocomplete="off" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm" :placeholder="`New ${c.description.toLowerCase()}`" autofocus />
                            <button class="rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white" :disabled="form.processing">Save</button>
                            <button type="button" class="px-2 text-sm text-gray-600" @click="editing = null">Cancel</button>
                            <p v-if="form.errors.value" class="w-full text-xs text-red-600">{{ form.errors.value }}</p>
                        </form>
                    </li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
