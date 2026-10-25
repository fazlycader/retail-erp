<script setup>
/**
 * "Manager approval": pick a manager, enter their PIN. v-model:approverId / v-model:pin.
 */
defineProps({
    approvers: {
        type: Array,
        default: () => [],
    },
    hint: String,
});

const approverId = defineModel('approverId', { type: [Number, String], default: '' });
const pin = defineModel('pin', { type: String, default: '' });
</script>

<template>
    <div class="rounded-xl bg-amber-50 p-3">
        <p class="text-sm font-semibold text-amber-950">Manager approval</p>
        <p v-if="hint" class="text-xs text-amber-900">{{ hint }}</p>
        <p v-if="approvers.length === 0" class="mt-1 text-sm text-amber-900">No manager has a PIN set. Ask a manager to do this, or set a PIN on the Staff page.</p>
        <div v-else class="mt-2 grid grid-cols-2 gap-2">
            <select v-model="approverId" class="rounded-lg border-gray-300 text-sm" aria-label="Manager">
                <option value="" disabled>Manager…</option>
                <option v-for="approver in approvers" :key="approver.id" :value="approver.id">{{ approver.name }}</option>
            </select>
            <input
                v-model="pin"
                type="password"
                inputmode="numeric"
                autocomplete="off"
                maxlength="6"
                class="rounded-lg border-gray-300 text-center text-lg tracking-[0.4em]"
                placeholder="PIN"
                aria-label="Manager PIN"
            />
        </div>
    </div>
</template>
