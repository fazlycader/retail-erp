<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import Icon from '@/Components/Icon.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Top-bar location switcher: the user's locations (admins: every active one, plus
 * "All locations" on pages that allow it). The server checks every switch.
 */
const page = usePage();
const current = computed(() => page.props.location);
const locations = computed(() => page.props.locations ?? []);
const scope = computed(() => page.props.locationScope ?? { all: false, can_all: false });
const switchable = computed(() => locations.value.length > 1 || scope.value.can_all);

const label = computed(() => (scope.value.all ? 'All locations' : current.value?.name ?? 'No location'));

const choose = (value) => {
    if (value === (scope.value.all ? 'all' : current.value?.id)) {
        return;
    }

    router.post(route('location.switch'), { location: value }, { preserveScroll: true });
};
</script>

<template>
    <Dropdown v-if="switchable" align="left" width="64">
        <template #trigger>
            <button
                type="button"
                class="flex max-w-56 items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:max-w-72"
                :title="`Working at: ${label}`"
            >
                <Icon :name="scope.all ? 'globe' : 'storefront'" class="size-5 shrink-0 text-brand-600" />
                <span class="min-w-0 truncate font-semibold text-gray-900">{{ label }}</span>
                <Icon name="chevronDown" class="size-4 shrink-0 text-gray-400" />
            </button>
        </template>

        <template #content>
            <p class="px-4 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-gray-400">Working at</p>
            <button
                v-if="scope.can_all"
                type="button"
                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm hover:bg-gray-100"
                @click="choose('all')"
            >
                <Icon name="globe" class="size-4 shrink-0 text-gray-400" />
                <span class="flex-1 font-medium text-gray-900">All locations</span>
                <Icon v-if="scope.all" name="check" class="size-4 text-brand-600" />
            </button>
            <button
                v-for="location in locations"
                :key="location.id"
                type="button"
                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm hover:bg-gray-100"
                @click="choose(location.id)"
            >
                <span class="w-9 shrink-0 text-xs font-semibold text-gray-400">{{ location.code }}</span>
                <span class="min-w-0 flex-1 truncate text-gray-900">{{ location.name }}</span>
                <Icon v-if="!scope.all && current?.id === location.id" name="check" class="size-4 text-brand-600" />
            </button>
        </template>
    </Dropdown>

    <span
        v-else-if="current"
        class="flex max-w-56 items-center gap-2 truncate rounded-lg px-2.5 py-1.5 text-sm font-semibold text-gray-900 sm:max-w-72"
        title="Your location"
    >
        <Icon name="storefront" class="size-5 shrink-0 text-brand-600" />
        <span class="truncate">{{ current.name }}</span>
    </span>
</template>
