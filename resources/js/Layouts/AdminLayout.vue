<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';
import CompanyMark from '@/Components/CompanyMark.vue';
import LocationSwitcher from '@/Components/LocationSwitcher.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const navigation = computed(() => page.props.navigation ?? []);
const company = computed(() => page.props.company);

// Mobile: off-canvas drawer. Tablet (md): icon rail. Desktop (lg): full sidebar, collapsible to rail.
const mobileOpen = ref(false);
const collapsed = ref(false);

onMounted(() => {
    try {
        collapsed.value = localStorage.getItem('sidebar-collapsed') === '1';
    } catch {
        // Storage unavailable (private mode); default to expanded.
    }
});

watch(collapsed, (value) => {
    try {
        localStorage.setItem('sidebar-collapsed', value ? '1' : '0');
    } catch {
        //
    }
});

const removeNavigateListener = router.on('navigate', () => {
    mobileOpen.value = false;
});
onUnmounted(removeNavigateListener);

// Shared terminal: a Lock button, and auto-lock after the configured idle time.
const terminal = computed(() => page.props.terminal ?? { enabled: false });
const lock = () => router.post(route('terminal.lock'));
let idleTimer;
const activityEvents = ['pointerdown', 'keydown', 'touchstart', 'wheel'];

const resetIdle = () => {
    clearTimeout(idleTimer);
    const minutes = terminal.value.enabled ? terminal.value.auto_lock_minutes : 0;

    if (minutes > 0) {
        idleTimer = setTimeout(lock, minutes * 60 * 1000);
    }
};

onMounted(() => {
    activityEvents.forEach((event) => window.addEventListener(event, resetIdle, { passive: true }));
    resetIdle();
});

onUnmounted(() => {
    clearTimeout(idleTimer);
    activityEvents.forEach((event) => window.removeEventListener(event, resetIdle));
});

// Flash messages from the server show as a toast for a few seconds.
const toast = ref(null);
let toastTimer;

watch(
    () => page.props.flash,
    (flash) => {
        const message = flash?.success ?? flash?.error;

        if (!message) {
            return;
        }

        toast.value = { type: flash.success ? 'success' : 'error', message };
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toast.value = null), 4000);
    },
    { immediate: true },
);

const itemClasses = (item) => [
    'group flex items-center rounded-lg font-medium transition-colors',
    'gap-3 px-3 py-2.5 text-sm',
    'md:flex-col md:gap-1 md:px-1 md:py-2 md:text-[11px] md:leading-tight',
    !collapsed.value && 'lg:flex-row lg:gap-3 lg:px-3 lg:py-2.5 lg:text-sm',
    item.active
        ? 'bg-white/10 text-white'
        : 'text-slate-400 hover:bg-white/5 hover:text-white',
];

const fullOnly = computed(() => ['md:hidden', !collapsed.value && 'lg:block']);
</script>

<template>
    <Head :title="props.title" />

    <div class="min-h-screen bg-gray-50">
        <!-- Mobile backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileOpen"
                class="fixed inset-0 z-30 bg-slate-900/50 md:hidden"
                @click="mobileOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 transition-[width,transform] duration-200 md:w-20 md:translate-x-0"
            :class="[!collapsed && 'lg:w-64', mobileOpen ? 'translate-x-0' : '-translate-x-full']"
            aria-label="Main navigation"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 px-4 md:justify-center" :class="!collapsed && 'lg:justify-start'">
                <Link :href="route('dashboard')" class="flex min-w-0 items-center gap-3">
                    <CompanyMark />
                    <span class="truncate text-sm font-semibold text-white" :class="fullOnly">
                        {{ company?.name }}
                    </span>
                </Link>
                <button
                    type="button"
                    class="ml-auto rounded-md p-1 text-slate-400 hover:text-white md:hidden"
                    @click="mobileOpen = false"
                >
                    <span class="sr-only">Close menu</span>
                    <Icon name="close" class="size-6" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <Link
                    v-for="item in navigation"
                    :key="item.key"
                    :href="item.href"
                    :class="itemClasses(item)"
                    :title="item.label"
                    :aria-current="item.active ? 'page' : undefined"
                >
                    <Icon
                        :name="item.icon"
                        class="size-6 shrink-0"
                        :class="item.active ? 'text-brand-400' : 'text-slate-500 group-hover:text-slate-300'"
                    />
                    <span class="text-center md:w-full md:truncate" :class="!collapsed && 'lg:w-auto lg:text-left'">
                        {{ item.label }}
                    </span>
                </Link>
            </nav>

            <div class="hidden border-t border-white/10 p-3 lg:block">
                <!-- System maker: MVRK+ support -->
                <a
                    v-if="page.props.brand?.enabled"
                    :href="page.props.brand.whatsapp_url"
                    target="_blank"
                    rel="noopener"
                    class="mb-1 block rounded-lg px-3 py-2 text-xs leading-snug text-slate-500 hover:bg-white/5 hover:text-slate-300"
                    :class="fullOnly"
                    :title="`WhatsApp ${page.props.brand.whatsapp}`"
                >
                    <span class="block font-semibold text-slate-400">{{ page.props.brand.product }}</span>
                    Support on WhatsApp {{ page.props.brand.whatsapp }}
                </a>
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400 hover:bg-white/5 hover:text-white"
                    :class="collapsed && 'justify-center px-1'"
                    @click="collapsed = !collapsed"
                >
                    <Icon :name="collapsed ? 'expand' : 'collapse'" class="size-5 shrink-0" />
                    <span v-if="!collapsed">Collapse</span>
                    <span v-else class="sr-only">Expand sidebar</span>
                </button>
            </div>
        </aside>

        <!-- Main column -->
        <div class="flex min-h-screen flex-col transition-[padding] duration-200 md:pl-20" :class="!collapsed && 'lg:pl-64'">
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
                <button
                    type="button"
                    class="-ml-1 rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 md:hidden"
                    @click="mobileOpen = true"
                >
                    <span class="sr-only">Open menu</span>
                    <Icon name="bars" class="size-6" />
                </button>

                <LocationSwitcher />

                <div class="min-w-0 flex-1">
                    <slot name="header">
                        <h1 class="hidden truncate text-lg font-semibold text-gray-900 md:block">{{ props.title }}</h1>
                    </slot>
                </div>

                <button
                    v-if="terminal.enabled"
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                    title="Lock the screen so the next person signs in with their PIN"
                    @click="lock"
                >
                    <Icon name="lock" class="size-4" />
                    <span class="hidden sm:inline">Lock</span>
                </button>

                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-gray-100"
                        >
                            <span class="flex size-8 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                                {{ user.name.charAt(0).toUpperCase() }}
                            </span>
                            <span class="hidden sm:block">
                                <span class="block text-sm font-medium leading-tight text-gray-900">{{ user.name }}</span>
                                <span class="block text-xs leading-tight text-gray-500">{{ user.role_label }}</span>
                            </span>
                            <Icon name="chevronDown" class="size-4 text-gray-400" />
                        </button>
                    </template>

                    <template #content>
                        <div class="border-b border-gray-100 px-4 py-2 sm:hidden">
                            <p class="text-sm font-medium text-gray-900">{{ user.name }}</p>
                            <p class="text-xs text-gray-500">{{ user.role_label }}</p>
                        </div>
                        <DropdownLink :href="route('profile.edit')">My profile</DropdownLink>
                        <template v-if="page.props.auth.can.override">
                            <DropdownLink v-if="!terminal.enabled" :href="route('terminal.enable')" method="post" as="button">Use as shared terminal</DropdownLink>
                            <DropdownLink v-else :href="route('terminal.disable')" method="post" as="button">Stop using as terminal</DropdownLink>
                        </template>
                        <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <!-- Phones: the top bar holds the location switcher, so the title moves down here. -->
                <p v-if="props.title && !$slots.header" class="mb-4 text-lg font-semibold text-gray-900 md:hidden">{{ props.title }}</p>
                <slot />
            </main>
        </div>

        <!-- Toast -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="toast"
                role="status"
                class="fixed bottom-4 right-4 z-50 flex max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium shadow-lg"
                :class="toast.type === 'success' ? 'bg-slate-900 text-white' : 'bg-red-600 text-white'"
            >
                <Icon :name="toast.type === 'success' ? 'check' : 'alert'" class="size-5 shrink-0" :class="toast.type === 'success' && 'text-emerald-400'" />
                <span>{{ toast.message }}</span>
            </div>
        </Transition>
    </div>
</template>
