<script setup>
import Sortable from 'sortablejs';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Drag-and-drop list (mouse and touch). Drag by any element marked data-drag-handle
 * inside the slot. Emits `reorder` with the items in their new order; the parent
 * updates its array and Vue re-renders, so Sortable never owns the DOM.
 */
const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
    itemKey: {
        type: [String, Function],
        default: 'id',
    },
    tag: {
        type: String,
        default: 'ul',
    },
    itemTag: {
        type: String,
        default: 'li',
    },
    disabled: Boolean,
});

const emit = defineEmits(['reorder']);
const el = ref(null);
let sortable;

const keyOf = (item, index) => (typeof props.itemKey === 'function' ? props.itemKey(item, index) : item[props.itemKey]);

onMounted(() => {
    sortable = Sortable.create(el.value, {
        handle: '[data-drag-handle]',
        draggable: '[data-sortable-item]',
        animation: 150,
        ghostClass: 'opacity-40',
        disabled: props.disabled,
        onEnd: ({ item, from, oldIndex, newIndex }) => {
            if (oldIndex === newIndex) {
                return;
            }

            // Put the node back where Vue expects it, then reorder the data instead.
            from.removeChild(item);
            from.insertBefore(item, from.children[oldIndex] ?? null);

            const reordered = [...props.items];
            reordered.splice(newIndex, 0, reordered.splice(oldIndex, 1)[0]);
            emit('reorder', reordered);
        },
    });
});

watch(
    () => props.disabled,
    (disabled) => sortable?.option('disabled', disabled),
);

onBeforeUnmount(() => sortable?.destroy());
</script>

<template>
    <component :is="tag" ref="el">
        <component :is="itemTag" v-for="(item, index) in items" :key="keyOf(item, index)" data-sortable-item>
            <slot :item="item" :index="index" />
        </component>
    </component>
</template>
