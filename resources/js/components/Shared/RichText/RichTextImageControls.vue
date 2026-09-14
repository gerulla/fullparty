<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NodeSelection } from '@tiptap/pm/state'
import type { Editor } from '@tiptap/core'
import type { RichTextImageAlign, RichTextImageLayout } from '@/Types/RichText'

const props = defineProps<{ editor: Editor }>()
const { t } = useI18n()
const l = (key: string) => t(`rich_text.${key}`)
const selected = ref(false)
const width = ref<number | string | undefined>()
const layout = ref<RichTextImageLayout>('block')
const align = ref<RichTextImageAlign>('left')
const layouts = computed(() => (['block', 'wrap-left', 'wrap-right', 'inline'] as const).map(value => ({ value, label: l(`image_${value.replace('-', '_')}`) })))

function sync() {
    const selection = props.editor.state.selection
    selected.value = selection instanceof NodeSelection && ['image', 'inlineImage'].includes(selection.node.type.name)
    if (!selected.value || !(selection instanceof NodeSelection)) return
    width.value = selection.node.attrs.width ?? undefined
    layout.value = selection.node.type.name === 'inlineImage' ? 'inline' : selection.node.attrs.layout ?? 'block'
    align.value = selection.node.attrs.align ?? 'left'
}
props.editor.on('transaction', sync)
onBeforeUnmount(() => props.editor.off('transaction', sync))
sync()

function applyWidth() {
    const value = width.value == null || width.value === '' ? null : Number(width.value)
    props.editor.commands.setRichTextImageWidth(value)
    sync()
}

function preset(percent: number) {
    const element = props.editor.view.dom
    const style = getComputedStyle(element)
    const available = element.clientWidth - parseFloat(style.paddingLeft || '0') - parseFloat(style.paddingRight || '0')
    props.editor.chain().focus().setRichTextImageWidth(Math.max(16, Math.min(4096, Math.round(available * percent / 100)))).run()
}
</script>

<template>
    <div v-if="selected" class="flex basis-full flex-wrap items-center gap-2 border-t border-default pt-2" role="group" :aria-label="l('image_options')">
        <span class="flex items-center gap-1 text-xs font-semibold"><UIcon name="i-lucide-image" />{{ l('image_options') }}</span>
        <USelect :model-value="layout" :items="layouts" :aria-label="l('image_layout')" size="xs" class="w-44" @update:model-value="value => editor.chain().focus().setRichTextImageLayout(value).run()" />
        <div v-if="layout === 'block'" class="flex gap-1" role="group" :aria-label="l('image_alignment')">
            <UTooltip v-for="value in (['left', 'center', 'right'] as const)" :key="value" :text="l(value)">
                <UButton :icon="`i-lucide-align-${value}`" :color="align === value ? 'primary' : 'neutral'" :variant="align === value ? 'soft' : 'ghost'" size="xs" :aria-label="l(value)" :aria-pressed="align === value" @click="editor.chain().focus().setRichTextImageAlign(value).run()" />
            </UTooltip>
        </div>
        <div class="flex items-center gap-1">
            <UInput v-model="width" type="number" :min="16" :max="4096" :step="1" :placeholder="l('image_auto')" :aria-label="l('image_width')" size="xs" class="w-24" @blur="applyWidth" @keydown.enter.prevent="applyWidth" />
            <span class="text-xs text-muted">px</span>
            <UButton icon="i-lucide-check" :aria-label="l('apply')" color="neutral" variant="ghost" size="xs" @click="applyWidth" />
        </div>
        <div class="flex gap-1" role="group" :aria-label="l('image_size_presets')">
            <UButton v-for="percent in [25, 50, 75, 100]" :key="percent" :label="`${percent}%`" color="neutral" variant="outline" size="xs" @click="preset(percent)" />
        </div>
        <UButton icon="i-lucide-rotate-ccw" :label="l('image_reset_size')" color="neutral" variant="ghost" size="xs" @click="editor.chain().focus().setRichTextImageWidth(null).run()" />
        <UTooltip :text="l('delete_image')"><UButton icon="i-lucide-trash-2" color="error" variant="soft" size="xs" :aria-label="l('delete_image')" @click="editor.chain().focus().deleteSelection().run()" /></UTooltip>
        <span class="basis-full text-xs text-muted">{{ l(layout === 'inline' ? 'image_inline_hint' : 'image_resize_hint') }}</span>
    </div>
</template>
