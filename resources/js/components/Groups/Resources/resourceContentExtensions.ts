import { Node } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import ResourceContentNode from '@/components/Groups/Resources/ResourceContentNode.vue'
import ResourceGearsetNode from '@/components/Groups/Resources/ResourceGearsetNode.vue'
import { xivGearExtension } from '@/utils/xivGear'

export const resourceContentExtensions = () => [
    xivGearExtension().extend({
        addNodeView: () => VueNodeViewRenderer(ResourceGearsetNode),
    }),
    Node.create({
        name: 'resourceLink', group: 'block', atom: true, draggable: true,
        addAttributes: () => ({ resourceId: { default: null, parseHTML: element => element.getAttribute('data-resource-link') } }),
        parseHTML: () => [{ tag: 'div[data-resource-link]' }],
        renderHTML: ({ node }) => ['div', { 'data-resource-link': node.attrs.resourceId }],
        addNodeView: () => VueNodeViewRenderer(ResourceContentNode),
    }),
    Node.create({
        name: 'videoEmbed', group: 'block', atom: true, draggable: true,
        addAttributes: () => ({ url: { default: '', parseHTML: element => element.getAttribute('data-video-embed') }, title: { default: '', parseHTML: element => element.getAttribute('data-title') ?? '' } }),
        parseHTML: () => [{ tag: 'div[data-video-embed]' }],
        renderHTML: ({ node }) => ['div', { 'data-video-embed': node.attrs.url, 'data-title': node.attrs.title }],
        addNodeView: () => VueNodeViewRenderer(ResourceContentNode),
    }),
]
