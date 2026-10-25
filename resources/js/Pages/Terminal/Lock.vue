<script setup>
import PoweredBy from '@/Components/PoweredBy.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    company: Object,
    location: String,
    staff: Array,
    current: Object,
});

const chosen = ref(null);
const form = useForm({ user_id: null, pin: '' });

const choose = (person) => {
    chosen.value = person;
    form.user_id = person.id;
    form.pin = '';
    form.clearErrors();
};

const press = (digit) => {
    if (form.pin.length < 6) {
        form.pin += digit;
    }
};

const back = () => (form.pin = form.pin.slice(0, -1));

const submit = () => {
    if (form.pin.length >= 4) {
        form.post(route('terminal.unlock'), { onError: () => (form.pin = '') });
    }
};

const onKey = (event) => {
    if (!chosen.value) return;
    if (/^\d$/.test(event.key)) press(event.key);
    if (event.key === 'Backspace') back();
    if (event.key === 'Enter') submit();
    if (event.key === 'Escape') chosen.value = null;
};

const initials = (name) => name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
const dots = computed(() => Array.from({ length: Math.max(4, form.pin.length) }, (_, i) => i < form.pin.length));
</script>

<template>
    <Head title="Locked" />

    <div class="flex min-h-dvh flex-col bg-slate-900 text-white" tabindex="-1" @keydown="onKey">
        <header class="flex items-center gap-3 px-6 py-5">
            <img v-if="company.logo_url" :src="company.logo_url" alt="" class="size-10 rounded-lg bg-white object-contain p-0.5" />
            <p class="text-lg font-semibold">{{ company.name }}</p>
            <p class="text-sm text-slate-400">· {{ location }}</p>
            <Link :href="route('login')" class="ml-auto text-sm text-slate-400 hover:text-white">Sign in with email</Link>
        </header>

        <main class="flex flex-1 flex-col items-center justify-center px-6 pb-10">
            <template v-if="!chosen">
                <h1 class="text-2xl font-bold">Who's using this terminal?</h1>
                <p class="mt-1 text-slate-400">Tap your name, then enter your PIN.</p>
                <div class="mt-8 grid w-full max-w-3xl grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <button
                        v-for="person in staff"
                        :key="person.id"
                        type="button"
                        class="flex min-h-28 flex-col items-center justify-center gap-2 rounded-2xl bg-slate-800 p-4 transition hover:bg-slate-700 active:scale-95"
                        @click="choose(person)"
                    >
                        <span class="flex size-12 items-center justify-center rounded-full bg-brand-600 text-lg font-bold">{{ initials(person.name) }}</span>
                        <span class="text-center font-semibold leading-tight">{{ person.name }}</span>
                        <span class="text-xs text-slate-400">{{ person.role }}</span>
                    </button>
                </div>
                <p v-if="staff.length === 0" class="mt-8 text-slate-400">No staff have a PIN yet. A manager can set PINs on the Staff page.</p>
            </template>

            <form v-else class="flex w-full max-w-xs flex-col items-center" @submit.prevent="submit">
                <button type="button" class="self-start text-sm text-slate-400 hover:text-white" @click="chosen = null">← Not {{ chosen.name.split(' ')[0] }}?</button>
                <span class="mt-4 flex size-16 items-center justify-center rounded-full bg-brand-600 text-2xl font-bold">{{ initials(chosen.name) }}</span>
                <h1 class="mt-2 text-xl font-bold">{{ chosen.name }}</h1>
                <div class="mt-6 flex gap-3" aria-live="polite" :aria-label="`${form.pin.length} digits entered`">
                    <span v-for="(filled, i) in dots" :key="i" class="size-4 rounded-full border-2 border-white" :class="filled && 'bg-white'" />
                </div>
                <p v-if="form.errors.pin || form.errors.user_id" class="mt-3 text-center text-sm font-medium text-red-400" role="alert">{{ form.errors.pin ?? form.errors.user_id }}</p>
                <div class="mt-6 grid w-full grid-cols-3 gap-3">
                    <button v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="digit" type="button" class="min-h-16 rounded-2xl bg-slate-800 text-2xl font-semibold active:bg-slate-600" @click="press(digit)">{{ digit }}</button>
                    <button type="button" class="min-h-16 rounded-2xl text-sm font-semibold text-slate-300 active:bg-slate-700" aria-label="Delete" @click="back">⌫</button>
                    <button type="button" class="min-h-16 rounded-2xl bg-slate-800 text-2xl font-semibold active:bg-slate-600" @click="press('0')">0</button>
                    <button type="submit" class="min-h-16 rounded-2xl bg-brand-600 text-lg font-bold disabled:opacity-40" :disabled="form.pin.length < 4 || form.processing">OK</button>
                </div>
            </form>
        </main>
        <PoweredBy dark />
    </div>
</template>
