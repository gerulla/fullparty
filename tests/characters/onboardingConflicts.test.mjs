import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, ref } from 'vue'

const character = { id: 12, lodestone_id: '12345678', name: 'Test Character', world: 'Lich', status: 'claimed' }

function setup(outcome, transferred = false, transfer = null) {
    const calls = [], visits = []
    const source = readFileSync(new URL('../../resources/js/composables/useCharacterOnboarding.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const modules = {
        vue: { computed, ref, onMounted: () => {}, onBeforeUnmount: () => {} },
        'vue-i18n': { useI18n: () => ({ t: key => key, te: () => true }) },
        'ziggy-js': { route: (name, id) => `${name}:${id ?? ''}` },
        '@inertiajs/vue3': { router: { visit: url => visits.push(url) } },
        '@/utils/characterOnboardingPreview': {},
        '@/utils/characterTransferPreview': {},
        '@/utils/characterImportStream': { readCharacterImportStream: async () => ({ character, partial: false }) },
        '@/composables/useCharacterImportProgress': { useCharacterImportProgress: () => ({
            reset: () => {}, update: () => {}, finish: async () => {}, progress: ref({ stage: 'profile', percent: 0 }),
        }) },
        axios: { default: { isAxiosError: () => false, post: async url => {
            calls.push(url)
            return { data: { outcome, character, transferred, transfer } }
        } } },
    }
    const exports = {}
    new Function('require', 'exports', code)(id => { assert.ok(id in modules, id); return modules[id] }, exports)
    const api = exports.useCharacterOnboarding({ xivAuthLinked: true, resumeXivAuth: false, worldGroups: [] })
    api.select(character)
    return { api, calls, visits }
}

for (const outcome of ['claim_required', 'already_claimed']) {
    test(`${outcome} navigates to the claim flow without refreshing or showing import success`, async () => {
        const { api, calls, visits } = setup(outcome)
        await api.confirm()
        assert.deepEqual(visits, ['characters.claim:12'])
        assert.deepEqual(calls, ['characters.onboarding.store:'])
        assert.notEqual(api.step.value, 'complete')
        assert.equal(api.added.value, null)
    })
}

test('the transfer notice survives the profile refresh before showing completion', async () => {
    const { api, calls, visits } = setup('added', true)
    await api.confirm()
    assert.equal(api.step.value, 'complete')
    assert.equal(api.transferred.value, true)
    assert.equal(api.added.value.id, 12)
    assert.deepEqual(calls, ['characters.onboarding.store:', 'characters.onboarding.refresh:12'])
    assert.deepEqual(visits, [])
})

test('a transfer stops before success until the mandatory choices are saved', async () => {
    const { api } = setup('added', true, { id: 3, pending: true, runs: [] })
    await api.confirm()
    assert.equal(api.step.value, 'transfer')
    assert.equal(api.transfer.value.id, 3)
    await api.retryRefresh()
    assert.equal(api.step.value, 'transfer')
    api.finishTransfer()
    assert.equal(api.step.value, 'complete')
    assert.equal(api.transfer.value, null)
})
