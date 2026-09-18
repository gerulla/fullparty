<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Editor } from '@tiptap/core'
import { VueRenderer } from '@tiptap/vue-3'
import Suggestion, { exitSuggestion, type SuggestionProps } from '@tiptap/suggestion'
import { PluginKey } from '@tiptap/pm/state'
import { shift, size } from '@floating-ui/dom'
import { useGameIconCatalog } from '@/composables/useGameIconCatalog'
import { findGameIcon, gameIconAttributes, searchGameIcons } from '@/utils/gameIcons'
import type { GameIcon } from '@/Types/GameIcons'
import GameIconSuggestionList from './GameIconSuggestionList.vue'

const props = defineProps<{ editor: Editor }>()
const { t } = useI18n()
const catalog = useGameIconCatalog()
const failed = ref(false)
const pluginKey = new PluginKey('gameIconSuggestions')
let renderer: VueRenderer | undefined
let unmount: (() => void) | undefined
let current: SuggestionProps<GameIcon, GameIcon> | undefined
function destroyMenu() { unmount?.(); unmount = undefined; renderer?.destroy(); renderer = undefined; current = undefined }
function updateMenu(state: SuggestionProps<GameIcon, GameIcon>) {
    current = state
    // Also resolve a quickly typed full shortcode while the initial catalog loads.
    const exact = state.query.endsWith(':') && !state.loading ? findGameIcon(catalog.icons.value, state.query.slice(0, -1)) : undefined
    if (exact) { state.command(exact); return }
    const menuProps = { items: state.items, loading: state.loading, failed: failed.value, command: state.command, retry: () => void retry() }
    if (renderer) renderer.updateProps(menuProps)
    else {
        renderer = new VueRenderer(GameIconSuggestionList, { props: menuProps, editor: props.editor })
        if (renderer.element) {
            const element = renderer.element as HTMLElement
            // Editors can mount before a modal's teleport has settled. Resolve the
            // actual dialog when opening, so the picker stays inside its focus trap.
            const container = props.editor.view.dom.closest<HTMLElement>('[role="dialog"]') ?? document.body
            container.appendChild(element)
            const stop = state.mount(element)
            unmount = () => { stop(); element.remove() }
        }
    }
}
async function retry() {
    failed.value = false
    try {
        await catalog.load()
        if (current) updateMenu({ ...current, loading: false, items: searchGameIcons(catalog.icons.value, current.query) })
    } catch { failed.value = true; if (current) updateMenu({ ...current, loading: false }) }
}
function openPicker() {
    if (failed.value) void retry()
    const { $from } = props.editor.state.selection
    const previous = $from.parent.textBetween(Math.max(0, $from.parentOffset - 1), $from.parentOffset)
    props.editor.chain().focus().insertContent(previous && !/\s/.test(previous) ? ' :' : ':').run()
}
function closePicker() { if (!props.editor.isDestroyed) exitSuggestion(props.editor.view, pluginKey) }
onMounted(() => {
    void retry()
    props.editor.registerPlugin(Suggestion<GameIcon, GameIcon>({
        pluginKey, editor: props.editor, char: ':',
        floatingUi: { strategy: 'fixed', middleware: [shift({ padding: 12, crossAxis: true }), size({
            padding: 12,
            apply: ({ availableWidth, availableHeight, elements }) => {
                elements.floating.style.maxWidth = `${Math.max(0, Math.min(400, availableWidth))}px`
                elements.floating.style.setProperty('--game-icon-list-height', `${Math.max(60, availableHeight - 52)}px`)
            },
        })] },
        allow: ({ editor, state, range }) => editor.isFocused && !state.doc.resolve(range.from).parent.type.spec.code && !state.doc.resolve(range.from).marks().some(mark => ['link', 'code'].includes(mark.type.name)),
        findSuggestionMatch: ({ $position }) => {
            const text = $position.parent.textBetween(0, $position.parentOffset, '\ufffc', '\ufffc')
            const match = /(?<![\p{L}\p{N}_/:]):([\p{L}\p{N}_-]{0,100}:?)$/u.exec(text)
            return match ? { range: { from: $position.pos - match[0].length, to: $position.pos }, query: match[1], text: match[0] } : null
        },
        items: async ({ query }) => {
            try { failed.value = false; return searchGameIcons(await catalog.load(), query) }
            catch { failed.value = true; return [] }
        },
        command: ({ editor, range, props: icon }) => {
            editor.chain().focus().insertContentAt(range, { type: 'gameIcon', attrs: gameIconAttributes(icon) }).run()
        },
        render: () => ({
            onStart: updateMenu, onUpdate: updateMenu,
            onBeforeStart: updateMenu, onBeforeUpdate: updateMenu,
            onExit: destroyMenu,
            onKeyDown: ({ event }) => renderer?.ref?.onKeyDown(event) ?? false,
        }),
    }), (plugin, plugins) => [plugin, ...plugins])
    props.editor.on('blur', closePicker)
})
onBeforeUnmount(() => { props.editor.off('blur', closePicker); if (!props.editor.isDestroyed) props.editor.unregisterPlugin(pluginKey); destroyMenu() })
</script>

<template>
    <UTooltip :text="t('rich_text.game_icons')"><UButton icon="i-lucide-smile" color="neutral" variant="ghost" size="xs" :aria-label="t('rich_text.game_icons')" :disabled="editor.isActive('codeBlock') || editor.isActive('code')" @click="openPicker" /></UTooltip>
</template>
