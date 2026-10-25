<script setup>
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { onBeforeUnmount, watch } from 'vue';

const model = defineModel({ type: String, default: '' });

const props = defineProps({
    disabled: Boolean,
});

const editor = useEditor({
    content: model.value || '',
    editable: !props.disabled,
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            code: false,
            codeBlock: false,
            horizontalRule: false,
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none min-h-32 px-3 py-2.5 text-sm text-gray-900 focus:outline-none',
        },
    },
    onUpdate: ({ editor: e }) => {
        model.value = e.isEmpty ? '' : e.getHTML();
    },
});

watch(
    () => model.value,
    (value) => {
        if (editor.value && (value || '') !== (editor.value.isEmpty ? '' : editor.value.getHTML())) {
            editor.value.commands.setContent(value || '', false);
        }
    },
);

watch(
    () => props.disabled,
    (disabled) => editor.value?.setEditable(!disabled),
);

onBeforeUnmount(() => editor.value?.destroy());

const btnClass = (active) => [
    'rounded px-2 py-1 text-xs font-semibold transition-colors',
    active ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-200 hover:text-gray-900',
];
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-gray-300 bg-white shadow-xs focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500">
        <div v-if="editor && !disabled" class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
            <button type="button" :class="btnClass(editor.isActive('bold'))" @click="editor.chain().focus().toggleBold().run()">B</button>
            <button type="button" :class="btnClass(editor.isActive('italic'))" class="italic" @click="editor.chain().focus().toggleItalic().run()">I</button>
            <button type="button" :class="btnClass(editor.isActive('strike'))" class="line-through" @click="editor.chain().focus().toggleStrike().run()">S</button>
            <span class="mx-1 h-4 w-px bg-gray-300" />
            <button type="button" :class="btnClass(editor.isActive('heading', { level: 2 }))" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
            <button type="button" :class="btnClass(editor.isActive('heading', { level: 3 }))" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
            <span class="mx-1 h-4 w-px bg-gray-300" />
            <button type="button" :class="btnClass(editor.isActive('bulletList'))" @click="editor.chain().focus().toggleBulletList().run()">• List</button>
            <button type="button" :class="btnClass(editor.isActive('orderedList'))" @click="editor.chain().focus().toggleOrderedList().run()">1. List</button>
            <button type="button" :class="btnClass(editor.isActive('blockquote'))" @click="editor.chain().focus().toggleBlockquote().run()">Quote</button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

