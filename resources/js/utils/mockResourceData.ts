import type { WorkspaceCollection, WorkspaceDocument, WorkspaceResource } from '../Types/ResourceWorkspace'
import { cloneDocument } from './resourceWorkspace'
import { emptyRichTextDocument } from './richText'
import { workspaceEmbed } from './resourceWorkspaceData'

export const workspaceActivities = ['DRS', 'BA', 'Forked Tower']
export const workspaceAuthors = ['Faust Gottes', 'Yenpress', 'Kaede Sato']
export const workspaceImages = [
    '/resource-sample-bridges.png',
    '/BozjaInfo/Essence of the Guardian/icon.png',
    '/reference-icons/character-classes/icons/whm.webp',
    '/prereqimages/forked.jpg',
]

export function newWorkspaceDocument(collectionId: string | null = null): WorkspaceDocument {
    return {
        title: '', description: '', body: emptyRichTextDocument(), collectionId, access: 'everyone', activities: [],
        tags: [], author: workspaceAuthors[0], cover: '',
        embeds: [],
    }
}

// Deliberately independent of Inertia props and the resource API. Reloading resets this library.
export function createResourceWorkspaceFixtures(): { collections: WorkspaceCollection[]; resources: WorkspaceResource[] } {
    const collections: WorkspaceCollection[] = [
        { id: 'drs', name: 'DRS', parentId: null, icon: 'i-lucide-folder' },
        { id: 'encounters', name: 'Encounters', parentId: 'drs', icon: 'i-lucide-folder' },
        { id: 'loadouts', name: 'Builds & Loadouts', parentId: 'drs', icon: 'i-lucide-folder' },
        { id: 'ba', name: 'The Baldesion Arsenal', parentId: null, icon: 'i-lucide-folder' },
        { id: 'tower', name: 'The Forked Tower', parentId: null, icon: 'i-lucide-folder' },
        { id: 'essentials', name: 'Group Essentials', parentId: null, icon: 'i-lucide-folder' },
    ]
    const seeds = [
        ['bridges', 'DRS Bridges', 'Bridge positions, callouts, and timing for all phases. Includes diagrams and role-specific notes.', 'encounters', 'published', 'DRS', 'everyone', 'bridges', workspaceImages[0]],
        ['avowed', 'Trinity Avowed', 'Positioning notes for our next progression run.', 'encounters', 'draft', 'DRS', 'moderators', 'avowed', workspaceImages[0]],
        ['queen', "The Queen's Guard", 'Callouts and responsibilities for encounter leads.', 'encounters', 'draft', 'DRS', 'moderators', '', workspaceImages[0]],
        ['tank', 'Tank Loadouts', 'Essences and lost actions for the tank team.', 'loadouts', 'published', 'DRS', 'everyone', 'tanks', workspaceImages[1]],
        ['healer', 'Healer Loadouts', 'A shared reference for healers and support.', 'loadouts', 'draft', 'DRS', 'everyone', '', workspaceImages[2]],
        ['trapper', 'BA Trapper Guide', 'Preparation and responsibilities for our trapper team.', 'ba', 'published', 'BA', 'everyone', 'trapper', workspaceImages[0]],
        ['tower-guide', 'Forked Tower Preparation', 'Equipment, unlocks and the final readiness check.', 'tower', 'published', 'Forked Tower', 'everyone', 'tower', workspaceImages[3]],
        ['welcome', 'New Member Checklist', 'Everything to have ready before your first group run.', 'essentials', 'published', '', 'everyone', 'welcome', ''],
        ['leads', 'Run Lead Handbook', 'Internal run planning and handover notes.', 'essentials', 'draft', '', 'admins', '', ''],
    ]
    const resources = seeds.map((seed, index): WorkspaceResource => {
        const [id, title, description, collectionId, status, activity, access, command, cover] = seed
        const document = newWorkspaceDocument(collectionId)
        Object.assign(document, { title, description, cover, access, activities: activity ? [activity] : [], tags: index < 3 ? ['Strategy', 'Progression'] : ['Preparation'] })
        document.body = { type: 'doc', content: [{ type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: title }] }, { type: 'paragraph', content: [{ type: 'text', text: description }] }] }
        if (command) document.embeds.push({ ...workspaceEmbed(), command, title, description, thumbnail: cover,
            fields: [{ name: 'Before the run', value: 'Check your assignment and bring the agreed actions.', inline: false }] })
        if (id === 'bridges') {
            document.tags = ['strategy', 'positioning']
            document.activities = ['DRS', 'BA']
            Object.assign(document.embeds[0], { title: 'DRS Bridge Positions', description: 'Visual guide to bridge positions, callouts, and timing for all phases.',
                fields: [{ name: 'West Party A', value: 'MT, H1, D1, D2', inline: true }, { name: 'East Party B', value: 'ST, H2, D3, D4', inline: true }] })
        }
        const updatedAt = `2026-09-${String(10 - index % 4).padStart(2, '0')}T14:30:00Z`
        return {
            ...document, id, slug: id, version: 1, status: status as WorkspaceResource['status'], order: index, updatedAt,
            published: status === 'draft' ? null : cloneDocument(document),
            history: [
                { id: `${id}-2`, author: 'Yenpress', authorAvatar: '/characters/char1.png', summary: 'Updated the preparation notes and party responsibilities.', at: updatedAt },
                { id: `${id}-1`, author: 'Faust Gottes', authorAvatar: '/characters/char1.png', summary: 'Created the initial group reference.', at: '2026-09-05T10:15:00Z' },
            ],
        }
    })
    return { collections, resources }
}
