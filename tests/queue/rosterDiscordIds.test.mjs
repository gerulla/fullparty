import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { effectScope, nextTick, ref, watch } from 'vue'
import * as discordIds from '../../resources/js/utils/rosterDiscordIds.ts'

const { assignedSlotDiscordId, rosterDiscordUserIds } = discordIds
const discordId = '123456789012345678'
const assigned = (userId = 2, characterId = 7) => ({ assigned_character_id: characterId, assigned_character: { id: characterId, user_id: userId } })

test('Discord IDs retain all snowflake digits as strings', () => {
    assert.equal(assignedSlotDiscordId(assigned(), { 2: discordId }), discordId)
})

test('empty slots, guests, missing links and stale character data have no copy action', () => {
    for (const slot of [
        { assigned_character_id: null, assigned_character: null },
        { ...assigned(), assigned_character_id: null },
        { ...assigned(), assigned_character_id: 8 },
        { ...assigned(), assigned_character: null },
        assigned(null),
        assigned(3),
    ]) assert.equal(assignedSlotDiscordId(slot, { 2: discordId }), null)
    for (const id of [null, '', 'username', 123456789, '1'.repeat(33)]) {
        assert.equal(assignedSlotDiscordId(assigned(), { 2: id }), null)
    }
})

test('assignment fingerprints deduplicate users and ignore slot order and guests', () => {
    const slots = [assigned(5), assigned(2, 8), assigned(5, 9), assigned(null, 10)]
    assert.deepEqual(rosterDiscordUserIds(slots), [2, 5])
    assert.deepEqual(rosterDiscordUserIds(slots.reverse()), [2, 5])
    assert.deepEqual(rosterDiscordUserIds([{ ...assigned(), assigned_character_id: null }]), [])
})

const source = readFileSync(new URL('../../resources/js/composables/useRosterDiscordCopy.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText

function copyMenuHarness({ get = async () => ({ data: { discord_user_ids: { 2: discordId } } }), writeText = async () => {}, initialSlots = [assigned()] } = {}) {
    const scope = effectScope()
    const slots = ref(initialSlots)
    const toasts = []
    let menuItems
    const modules = {
        axios: { default: { get } },
        vue: { ref, watch, provide: (_, value) => { menuItems = value }, inject: () => menuItems },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@nuxt/ui/composables': { useToast: () => ({ add: value => toasts.push(value) }) },
        'ziggy-js': { route: name => name },
        '@/utils/rosterDiscordIds': discordIds,
    }
    const exports = {}
    new Function('require', 'exports', 'navigator', compiled)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports, { clipboard: { writeText } })
    scope.run(() => exports.provideRosterDiscordCopy(() => 'group', () => 10, () => slots.value))
    return { slots, toasts, menu: slot => menuItems(slot), stop: () => scope.stop() }
}

const flush = () => new Promise(resolve => setImmediate(resolve))

test('copy menu writes the raw ID and confirms success', async t => {
    const copied = []
    const api = copyMenuHarness({ writeText: async text => copied.push(text) })
    t.after(api.stop)
    await flush()

    const items = api.menu(assigned())
    assert.equal(items.length, 1)
    assert.equal(items[0].label, 'groups.activities.management.roster.copy_discord_id_action')
    await items[0].onSelect()
    assert.deepEqual(copied, [discordId])
    assert.equal(api.toasts[0].color, 'success')
    assert.deepEqual(api.menu(assigned(3)), [])
})

test('clipboard failures display an error rather than a false success', async t => {
    const api = copyMenuHarness({ writeText: async () => { throw new Error('Permission denied') } })
    t.after(api.stop)
    await flush()
    await api.menu(assigned())[0].onSelect()
    assert.equal(api.toasts.length, 1)
    assert.equal(api.toasts[0].color, 'error')
})

test('unavailable contact data leaves the copy action hidden', async t => {
    const api = copyMenuHarness({ get: async () => { throw new Error('Forbidden') } })
    t.after(api.stop)
    await flush()
    assert.deepEqual(api.menu(assigned()), [])
})

test('roster changes fetch new users and ignore canceled stale responses', async t => {
    const requests = []
    const api = copyMenuHarness({ get: (url, { signal }) => new Promise(resolve => requests.push({ signal, resolve })) })
    t.after(api.stop)
    assert.equal(requests.length, 1)

    api.slots.value = [assigned(3)]
    await nextTick()
    assert.equal(requests.length, 2)
    assert.equal(requests[0].signal.aborted, true)
    requests[1].resolve({ data: { discord_user_ids: { 3: '987654321098765432' } } })
    await flush()
    assert.equal(api.menu(assigned(3)).length, 1)

    requests[0].resolve({ data: { discord_user_ids: { 2: discordId } } })
    await flush()
    assert.equal(api.menu(assigned(3)).length, 1)
    assert.deepEqual(api.menu(assigned(2)), [])

    api.slots.value = [assigned(3, 12)]
    await nextTick()
    assert.equal(requests.length, 2, 'Moving an existing user does not refetch')

    api.slots.value = []
    await nextTick()
    assert.equal(requests.length, 2, 'Empty rosters do not fetch')
    assert.deepEqual(api.menu(assigned(3)), [])
})

test('unmount cancels the pending contact request', () => {
    let signal
    const api = copyMenuHarness({ get: (_, options) => { signal = options.signal; return new Promise(() => {}) } })
    api.stop()
    assert.equal(signal.aborted, true)
})
