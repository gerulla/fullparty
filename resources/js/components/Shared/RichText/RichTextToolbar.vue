<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Editor } from '@tiptap/core'
import type { EditorToolbarItem } from '@nuxt/ui'
import { safeEditorUrl } from '@/utils/richText'

const props = defineProps<{ editor: Editor }>()
const emit = defineEmits<{ image: [] }>()
const { t } = useI18n()
const l = (key: string) => t(`rich_text.${key}`)
const linkOpen = ref(false)
const href = ref('')
const columns = ref(3)
const rows = ref(3)
const tableOpen = ref(false)
const colorControls = [
    { mark: 'textStyle', label: 'text_color', icon: 'i-lucide-baseline', defaultColor: '#ffffff' },
    { mark: 'highlight', label: 'highlight', icon: 'i-lucide-highlighter', defaultColor: '#facc15' },
] as const
const sizes = ['12px', '14px', '16px', '18px', '20px', '24px', '28px', '32px', '40px', '48px']
const tools = computed<EditorToolbarItem[][]>(() => [
    [{ icon: 'i-lucide-pilcrow', label: props.editor.isActive('heading') ? l('heading') + ' ' + props.editor.getAttributes('heading').level : l('paragraph'),
        items: [{ kind: 'paragraph', label: l('paragraph'), icon: 'i-lucide-pilcrow' }, ...([1, 2, 3, 4, 5, 6] as const).map(level => ({ kind: 'heading' as const, level, label: l('heading') + ' ' + level, icon: `i-lucide-heading-${level}` }))] }],
    (['bold', 'italic', 'underline', 'strike', 'code'] as const).map(mark => ({ kind: 'mark' as const, mark, icon: `i-lucide-${mark === 'strike' ? 'strikethrough' : mark}`, tooltip: { text: l(mark) } })),
    (['subscript', 'superscript'] as const).map(mark => ({ icon: `i-lucide-${mark}`, tooltip: { text: l(mark) }, active: props.editor.isActive(mark), onClick: () => props.editor.chain().focus().toggleMark(mark).run() })),
    [{ kind: 'bulletList', icon: 'i-lucide-list', tooltip: { text: l('bullet_list') } }, { kind: 'orderedList', icon: 'i-lucide-list-ordered', tooltip: { text: l('ordered_list') } }, { kind: 'taskList', icon: 'i-lucide-list-checks', tooltip: { text: l('task_list') } }],
    (['left', 'center', 'right', 'justify'] as const).map(align => ({ kind: 'textAlign' as const, align, icon: `i-lucide-align-${align}`, tooltip: { text: l(align) } })),
    [{ icon: 'i-lucide-link', tooltip: { text: l('link') }, onClick: () => { href.value = props.editor.getAttributes('link').href ?? ''; linkOpen.value = true } },
        { icon: 'i-lucide-image-plus', tooltip: { text: l('image') }, onClick: () => emit('image') },
        { icon: 'i-lucide-table', tooltip: { text: l('table') }, onClick: () => { tableOpen.value = true } },
        { kind: 'blockquote', icon: 'i-lucide-quote', tooltip: { text: l('quote') } }, { kind: 'codeBlock', icon: 'i-lucide-square-code', tooltip: { text: l('code_block') } },
        { kind: 'horizontalRule', icon: 'i-lucide-separator-horizontal', tooltip: { text: l('divider') } }],
    [{ kind: 'clearFormatting', icon: 'i-lucide-remove-formatting', tooltip: { text: l('clear_formatting') } }, { kind: 'undo', icon: 'i-lucide-undo-2', tooltip: { text: l('undo') } }, { kind: 'redo', icon: 'i-lucide-redo-2', tooltip: { text: l('redo') } }],
])
const tableActions = computed(() => [
    { icon: 'i-lucide-rows-3', label: l('add_row'), onSelect: () => props.editor.chain().focus().addRowAfter().run() },
    { icon: 'i-lucide-columns-3', label: l('add_column'), onSelect: () => props.editor.chain().focus().addColumnAfter().run() },
    { icon: 'i-lucide-table-cells-merge', label: l('merge_cells'), disabled: !props.editor.can().mergeCells(), onSelect: () => props.editor.chain().focus().mergeCells().run() },
    { icon: 'i-lucide-table-cells-split', label: l('split_cell'), disabled: !props.editor.can().splitCell(), onSelect: () => props.editor.chain().focus().splitCell().run() },
    { icon: 'i-lucide-panel-top', label: l('header_row'), onSelect: () => props.editor.chain().focus().toggleHeaderRow().run() },
    { icon: 'i-lucide-rows-3', label: l('delete_row'), color: 'error' as const, onSelect: () => props.editor.chain().focus().deleteRow().run() },
    { icon: 'i-lucide-columns-3', label: l('delete_column'), color: 'error' as const, onSelect: () => props.editor.chain().focus().deleteColumn().run() },
    { icon: 'i-lucide-trash-2', label: l('delete_table'), color: 'error' as const, onSelect: () => props.editor.chain().focus().deleteTable().run() },
])
function setColor(mark: 'textStyle' | 'highlight', value: string | undefined) {
    if (!props.editor.isEditable || !value || !/^#[0-9a-f]{6}$/i.test(value)) return
    // Keep focus in the picker while applying to the editor's retained selection.
    if (mark === 'highlight') props.editor.chain().setHighlight({ color: value }).run()
    else props.editor.chain().setColor(value).run()
}
function saveLink() {
    if (!safeEditorUrl(href.value)) return
    const chain = props.editor.chain().focus().extendMarkRange('link')
    if (props.editor.state.selection.empty && !props.editor.isActive('link')) chain.insertContent({ type: 'text', text: href.value, marks: [{ type: 'link', attrs: { href: href.value } }] }).run()
    else chain.setLink({ href: href.value }).run()
    linkOpen.value = false
}
</script>

<template>
    <div class="rich-text-toolbar flex flex-wrap items-center gap-1 border-b border-default bg-elevated p-2">
        <UEditorToolbar :editor="editor" :items="tools" :ui="{ base: 'flex-wrap bg-transparent p-0', group: 'flex-wrap' }" />
        <USelect :model-value="editor.getAttributes('textStyle').fontFamily ?? 'default'" :items="[{ label: l('default_font'), value: 'default' }, 'Arial', 'Georgia', 'Verdana', 'monospace']" :aria-label="l('font')" size="xs" class="w-28" @update:model-value="value => value === 'default' ? editor.chain().focus().unsetFontFamily().run() : editor.chain().focus().setFontFamily(value).run()" />
        <USelect :model-value="editor.getAttributes('textStyle').fontSize ?? 'default'" :items="[{ label: l('default_size'), value: 'default' }, ...sizes.map(value => ({ label: value.slice(0, -2), value }))]" :aria-label="l('font_size')" size="xs" class="w-20" @update:model-value="value => value === 'default' ? editor.chain().focus().unsetFontSize().run() : editor.chain().focus().setFontSize(value).run()" />
        <USelect :model-value="editor.getAttributes('textStyle').lineHeight ?? 'default'" :items="[{ label: l('spacing'), value: 'default' }, '1', '1.25', '1.5', '1.75', '2']" :aria-label="l('spacing')" size="xs" class="w-24" @update:model-value="value => value === 'default' ? editor.chain().focus().unsetLineHeight().run() : editor.chain().focus().setLineHeight(value).run()" />
        <UPopover v-for="control in colorControls" :key="control.mark" :content="{ align: 'start' }" :ui="{ content: 'rounded-none bg-elevated p-3' }">
            <UTooltip :text="l(control.label)">
                <UButton :icon="control.icon" color="neutral" variant="ghost" size="xs" :aria-label="l(control.label)" :disabled="!editor.isEditable">
                    <template #trailing>
                        <span class="size-3.5 shrink-0 ring ring-default" :style="{ backgroundColor: editor.getAttributes(control.mark).color ?? control.defaultColor }" />
                    </template>
                </UButton>
            </UTooltip>
            <template #content>
                <div class="space-y-3">
                    <UColorPicker :model-value="editor.getAttributes(control.mark).color ?? control.defaultColor" format="hex" :disabled="!editor.isEditable" :aria-label="l(control.label)" @update:model-value="setColor(control.mark, $event)" />
                    <UInput :model-value="editor.getAttributes(control.mark).color ?? control.defaultColor" :aria-label="l(control.label)" :disabled="!editor.isEditable" placeholder="#RRGGBB" class="w-full" :ui="{ base: 'rounded-none font-mono' }" @update:model-value="setColor(control.mark, String($event))" />
                </div>
            </template>
        </UPopover>
        <UDropdownMenu v-if="editor.isActive('table')" :items="tableActions"><UButton icon="i-lucide-table-properties" color="neutral" variant="outline" size="xs" :label="l('table_actions')" /></UDropdownMenu>
        <UTooltip v-if="editor.isActive('image')" :text="l('delete_image')"><UButton icon="i-lucide-trash-2" color="error" variant="soft" size="xs" :aria-label="l('delete_image')" @click="editor.chain().focus().deleteSelection().run()" /></UTooltip>
        <slot />
    </div>
    <UModal v-model:open="linkOpen" :title="l('link')">
        <template #body><form class="space-y-4" @submit.prevent="saveLink"><UFormField :label="l('url')" :error="href && !safeEditorUrl(href) ? l('invalid_url') : undefined"><UInput v-model="href" autofocus class="w-full" /></UFormField><div class="flex justify-end gap-2"><UButton v-if="editor.isActive('link')" color="error" variant="soft" :label="l('remove_link')" @click="editor.chain().focus().unsetLink().run(); linkOpen = false" /><UButton type="submit" :label="l('apply')" :disabled="!safeEditorUrl(href)" /></div></form></template>
    </UModal>
    <UModal v-model:open="tableOpen" :title="l('table')">
        <template #body><form class="space-y-4" @submit.prevent="editor.chain().focus().insertTable({ rows, cols: columns, withHeaderRow: true }).run(); tableOpen = false"><div class="grid grid-cols-2 gap-4"><UFormField :label="l('rows')"><UInputNumber v-model="rows" :min="1" :max="20" /></UFormField><UFormField :label="l('columns')"><UInputNumber v-model="columns" :min="1" :max="12" /></UFormField></div><div class="flex justify-end"><UButton type="submit" :label="l('insert')" /></div></form></template>
    </UModal>
</template>
