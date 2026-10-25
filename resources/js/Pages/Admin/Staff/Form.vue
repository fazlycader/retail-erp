<script setup>
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    staff: {
        type: Object,
        default: null,
    },
    roles: Array,
    locations: Array,
    defaultLocationId: { type: Number, default: null },
});

const editing = computed(() => props.staff !== null);
const isSelf = computed(() => props.staff?.is_self ?? false);

const form = useForm({
    name: props.staff?.name ?? '',
    email: props.staff?.email ?? '',
    phone: props.staff?.phone ?? '',
    role: props.staff?.role ?? (props.roles.find((role) => role.value === 'cashier') ?? props.roles[0]).value,
    is_active: props.staff?.is_active ?? true,
    password: '',
    password_confirmation: '',
    pin: '',
    remove_pin: false,
    home_location_id: props.staff ? props.staff.home_location_id : (props.defaultLocationId ?? props.locations[0]?.id ?? null),
    location_ids: [...(props.staff?.location_ids ?? [])],
});

// Extra locations: any of the ones you manage except the home location.
const otherLocations = computed(() => props.locations.filter((location) => location.id !== form.home_location_id));

watch(
    () => form.home_location_id,
    (home) => (form.location_ids = form.location_ids.filter((id) => id !== home)),
);

const toggleLocation = (id) => {
    const list = form.location_ids;
    const i = list.indexOf(id);
    i === -1 ? list.push(id) : list.splice(i, 1);
};

const submit = () => {
    const options = { onFinish: () => form.reset('password', 'password_confirmation', 'pin') };

    editing.value ? form.put(route('staff.update', props.staff.id), options) : form.post(route('staff.store'), options);
};
</script>

<template>
    <AdminLayout :title="editing ? `Edit ${staff.name}` : 'Add staff'">
        <form class="mx-auto max-w-3xl space-y-6" @submit.prevent="submit">
            <Card title="Details" description="Staff sign in with their email and password.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel for="name" value="Full name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus autocomplete="off" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required autocomplete="off" />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Phone (optional)" />
                        <TextInput id="phone" v-model="form.phone" type="tel" class="mt-1 block w-full" placeholder="077 123 4567" />
                        <InputError class="mt-2" :message="form.errors.phone" />
                    </div>
                    <div>
                        <InputLabel for="role" value="Role" />
                        <SelectInput id="role" v-model="form.role" class="mt-1 block w-full" :disabled="isSelf">
                            <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                        </SelectInput>
                        <p v-if="isSelf" class="mt-1 text-xs text-gray-500">You can't change your own role.</p>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>
                    <div>
                        <InputLabel value="Account status" />
                        <div class="mt-2 flex items-center gap-3">
                            <Toggle v-model="form.is_active" :disabled="isSelf" />
                            <span class="text-sm text-gray-700">{{ form.is_active ? 'Active: can sign in' : 'Inactive: blocked from signing in' }}</span>
                        </div>
                        <InputError class="mt-2" :message="form.errors.is_active" />
                    </div>
                </div>
            </Card>

            <Card title="Locations" description="Where this person works. They can switch between these in the top bar.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <InputLabel for="home_location_id" value="Home location" />
                        <SelectInput id="home_location_id" v-model="form.home_location_id" class="mt-1 block w-full">
                            <option v-if="form.role === 'admin'" :value="null">None (admins work everywhere)</option>
                            <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                        </SelectInput>
                        <p class="mt-1 text-xs text-gray-500">Where they start after signing in.</p>
                        <InputError class="mt-2" :message="form.errors.home_location_id" />
                    </div>
                    <div v-if="form.role !== 'admin' && otherLocations.length" class="sm:col-span-2">
                        <InputLabel value="Also works at" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="location in otherLocations"
                                :key="location.id"
                                type="button"
                                class="min-h-10 rounded-full px-4 text-sm font-semibold ring-1 ring-inset"
                                :class="form.location_ids.includes(location.id) ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-700 ring-gray-300'"
                                :aria-pressed="form.location_ids.includes(location.id)"
                                @click="toggleLocation(location.id)"
                            >
                                {{ location.name }}
                            </button>
                        </div>
                        <InputError class="mt-2" :message="form.errors.location_ids || Object.entries(form.errors).find(([key]) => key.startsWith('location_ids.'))?.[1]" />
                    </div>
                    <p v-else-if="form.role === 'admin'" class="text-sm text-gray-500 sm:col-span-2 sm:self-end">Admins can open every location.</p>
                </div>
            </Card>

            <Card
                title="Password"
                :description="editing ? 'Leave blank to keep the current password.' : 'At least 8 characters.'"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <InputLabel for="password" value="Password" />
                        <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" :required="!editing" autocomplete="new-password" />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>
                    <div>
                        <InputLabel for="password_confirmation" value="Confirm password" />
                        <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1 block w-full" :required="!editing" autocomplete="new-password" />
                    </div>
                </div>
            </Card>

            <Card title="PIN" description="Optional 4–6 digit PIN to switch staff on a shared terminal and for manager approvals. It's stored hashed.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <InputLabel for="pin" :value="editing && staff.has_pin ? 'New PIN' : 'PIN'" />
                        <TextInput
                            id="pin"
                            v-model="form.pin"
                            type="password"
                            inputmode="numeric"
                            pattern="[0-9]{4,6}"
                            maxlength="6"
                            class="mt-1 block w-full tracking-widest"
                            :disabled="form.remove_pin"
                            autocomplete="off"
                            :placeholder="editing && staff.has_pin ? 'Leave blank to keep' : '••••'"
                        />
                        <InputError class="mt-2" :message="form.errors.pin" />
                    </div>
                    <div v-if="editing && staff.has_pin" class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="form.remove_pin" type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                            Remove PIN
                        </label>
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('staff.index')" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">
                    Cancel
                </Link>
                <PrimaryButton :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create staff' }}
                </PrimaryButton>
            </div>
        </form>
    </AdminLayout>
</template>
