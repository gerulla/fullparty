import { Extension, InputRule, Node, PasteRule } from '@tiptap/core'
import { findGameIcon, gameIconAttributes, safeGameIconSource } from './gameIcons.ts'
import type { GameIcon as GameIconEntry } from '../Types/GameIcons'

export const GameIcon = Node.create({
    name: 'gameIcon',
    inline: true,
    group: 'inline',
    atom: true,
    selectable: true,
    addAttributes: () => ({
        key: { default: null, parseHTML: element => element.getAttribute('data-game-icon-key') },
        shortcode: { default: null, parseHTML: element => element.getAttribute('data-game-icon-shortcode') },
        src: { default: null, parseHTML: element => safeGameIconSource(element.getAttribute('src')) },
    }),
    parseHTML: () => [{ tag: 'img[data-game-icon-key]', getAttrs: element => safeGameIconSource(element.getAttribute('src')) ? null : false }],
    renderHTML: ({ node }) => ['img', {
        'data-game-icon-key': node.attrs.key,
        'data-game-icon-shortcode': node.attrs.shortcode,
        src: safeGameIconSource(node.attrs.src) ?? undefined,
        alt: `:${node.attrs.shortcode}:`, title: `:${node.attrs.shortcode}:`,
        draggable: 'false', width: 24, height: 24,
    }],
    renderText: ({ node }) => `:${node.attrs.shortcode}:`,
})

const shortcodePattern = /(?<![\p{L}\p{N}_/]):([\p{L}\p{N}_-]{1,100}):/gu

export const GameIconShortcodes = Extension.create<{ icons: () => GameIconEntry[] }>({
    name: 'gameIconShortcodes',
    addOptions: () => ({ icons: () => [] }),
    addInputRules() {
        return [new InputRule({
            find: new RegExp(`${shortcodePattern.source}$`, 'u'),
            handler: ({ state, range, match }) => {
                if (state.selection.$from.marks().some(mark => ['code', 'link'].includes(mark.type.name))) return null
                const icon = findGameIcon(this.options.icons(), match[1])
                if (!icon) return null
                state.tr.replaceWith(range.from, range.to, state.schema.nodes.gameIcon.create(gameIconAttributes(icon)))
            },
        })]
    },
    addPasteRules() {
        return [new PasteRule({
            find: shortcodePattern,
            handler: ({ state, range, match, chain }) => {
                const icon = findGameIcon(this.options.icons(), match[1])
                if (!icon) return null
                let protectedText = false
                state.doc.nodesBetween(range.from, range.to, node => {
                    if (node.type.spec.code || node.marks.some(mark => ['code', 'link'].includes(mark.type.name))) protectedText = true
                })
                if (protectedText) return null
                chain().insertContentAt(range, { type: 'gameIcon', attrs: gameIconAttributes(icon) }).run()
            },
        })]
    },
})
