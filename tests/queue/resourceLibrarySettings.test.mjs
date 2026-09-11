import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import ts from 'typescript'
import { computed, ref, watch, nextTick } from 'vue'
import * as settings from '../../resources/js/utils/resourceLibrarySettings.ts'

const library = (customization = {}) => ({ visibility: 'public', customization: {
    title: 'Group Resource Library', introduction: 'Browse Group resources to find the info you need.', ...customization,
} })
function load(source, modules) {
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    return exports
}

test('customization defaults preserve zero focal positions and clone links before editing', () => {
    const saved = library({ title: 'Our library', banner_focal_x: 0, banner_focal_y: 100, links: [{ label: 'Discord', url: 'https://discord.com' }], start_resource_id: 1 })
    const draft = settings.resourceLibraryCustomization(saved)
    assert.equal(draft.banner_focal_x, 0)
    assert.equal(draft.banner_focal_y, 100)
    assert.equal(draft.accent_color, '#8457b0')
    assert.equal(draft.appearance, 'system')
    assert.equal(draft.logo_image_id, null)
    draft.links[0].label = 'Edited'
    assert.equal(saved.customization.links[0].label, 'Discord')
    assert.equal(draft.start_resource_id, undefined)
})

test('public settings send all fields and private settings omit customization without destroying it', () => {
    const draft = settings.resourceLibraryCustomization(library({ title: 'Guide library', links: [{ label: ' Website ', url: ' https://example.com ' }] }))
    draft.banner_image_id = ''; draft.logo_image_id = ''; draft.sharing_image_id = ''
    assert.deepEqual(settings.resourceLibrarySettingsPayload('private', draft), { visibility: 'private' })
    const payload = settings.resourceLibrarySettingsPayload('public', draft)
    assert.equal(payload.customization.title, 'Guide library')
    assert.equal(payload.customization.banner_image_id, null)
    assert.equal(payload.customization.logo_image_id, null)
    assert.equal(payload.customization.sharing_image_id, null)
    assert.deepEqual(payload.customization.links, [{ label: 'Website', url: 'https://example.com' }])
    assert.equal(draft.links[0].label, ' Website ')
})

test('server-provided default text becomes editable field values without resetting deliberately cleared input', () => {
    const draft = settings.resourceLibraryCustomization(library())
    assert.equal(draft.title, 'Group Resource Library')
    assert.equal(draft.introduction, 'Browse Group resources to find the info you need.')
    draft.title = ''; draft.introduction = ''
    const payload = settings.resourceLibrarySettingsPayload('public', draft)
    assert.equal(payload.customization.title, '', 'manual validation must reject an empty title rather than silently replacing it')
    assert.equal(payload.customization.introduction, '')
})

function managementHarness(fail = false) {
    const patches = [], calls = [], toasts = [], reloads = []
    let resolve, events, creates = 0
    const modal = {
        patch: data => patches.push(data), close: value => resolve(value),
        open: data => { events = data; return { result: new Promise(done => { resolve = done }) } },
    }
    const api = load(readFileSync(new URL('../../resources/js/composables/useResourceLibraryManagement.ts', import.meta.url), 'utf8'), {
        '@nuxt/ui/composables': { useOverlay: () => ({ create: () => { creates++; return modal } }), useToast: () => ({ add: toast => toasts.push(toast) }) },
        '@inertiajs/vue3': { router: { reload: data => reloads.push(data) } },
        axios: { default: { isAxiosError: error => !!error.response, put: async (...args) => {
            calls.push(args)
            if (fail) throw { response: { status: 422, data: { errors: { 'customization.links.0.url': ['Enter a valid URL.'], 'customization.accent_color': ['Enter a hex color.'] } } } }
        } } },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        'ziggy-js': { route: (name, params) => `${name}/${params.group}` },
        '@/components/Groups/Resources/ResourceLibraryManageModal.vue': { default: {} },
        '@/composables/useConfirmationModal': { useConfirmationModal: () => ({ open: assert.fail }) },
    }).useResourceLibraryManagement(() => 'our-group', () => library({ title: 'Saved title' }))
    return { api, patches, calls, toasts, reloads, modal, get events() { return events }, get creates() { return creates } }
}

test('settings overlay opens with current branding and saves the entire payload once', async () => {
    const h = managementHarness()
    const opened = h.api.open()
    await h.api.open()
    assert.equal(h.creates, 1)
    assert.equal(h.events.groupSlug, 'our-group')
    assert.equal(h.events.library.customization.title, 'Saved title')
    h.events.onUploadsChanged()
    assert.deepEqual(h.reloads[0], { only: ['library'] })
    const payload = settings.resourceLibrarySettingsPayload('public', settings.resourceLibraryCustomization(library({ title: 'New title' })))
    const saving = h.events.onSave(payload)
    await h.events.onSave(payload)
    await saving; await opened
    assert.equal(h.calls.length, 1)
    assert.deepEqual(h.calls[0][1], payload)
    assert.equal(h.toasts[0].color, 'success')
    assert.equal(h.reloads.length, 2)
})

test('settings validation errors keep the modal open with exact field paths for correction', async () => {
    const h = managementHarness(true)
    const opened = h.api.open()
    await h.events.onSave({ visibility: 'public', customization: {} })
    const errors = h.patches.find(patch => patch.errors?.['customization.links.0.url'])
    assert.equal(errors.errors['customization.links.0.url'], 'Enter a valid URL.')
    assert.equal(errors.errors['customization.accent_color'], 'Enter a hex color.')
    assert.equal(errors.error, 'groups.resources.library.fix_fields')
    assert.equal(h.patches.at(-1).busy, false)
    assert.equal(h.toasts.length, 0)
    assert.equal(h.reloads.length, 0)
    h.events.onClearErrors()
    assert.deepEqual(h.patches.at(-1), { error: '', errors: {} })
    h.modal.close(false); await opened
})

test('settings modal preserves edits when visibility changes and supplies its own library upload context', async () => {
    const filename = '../../resources/js/components/Groups/Resources/ResourceLibraryManageModal.vue'
    const { descriptor } = parse(readFileSync(new URL(filename, import.meta.url), 'utf8'))
    const source = compileScript(descriptor, { id: filename }).content
    const provided = new Map(), emitted = []
    const libraryKey = Symbol('library'), uploadKey = Symbol('upload'), upload = async () => '/resource-assets/new'
    let context
    const component = load(source, {
        vue: { defineComponent: value => value, computed, ref, watch, nextTick, provide: (key, value) => provided.set(key, value) },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@/utils/resourceLibrarySettings': settings,
        '@/composables/useResourceImages': { resourceImageLibraryKey: libraryKey, useResourceImages: value => { context = value; return { state: { busy: false }, upload } } },
        '@/composables/useResourceImageUpload': { resourceImageUploadKey: uploadKey },
        './ResourceLibraryCustomizationForm.vue': { default: {} },
    }).default
    const state = component.setup({ groupSlug: 'our-group', library: library({ title: 'Original' }), errors: {}, busy: false }, { expose() {}, emit: (...args) => emitted.push(args) })
    state.customization.value.title = 'Keep my edits'
    state.visibility.value = 'private'; await nextTick()
    assert.equal(state.customization.value.title, 'Keep my edits')
    state.visibility.value = 'public'; await nextTick()
    assert.equal(state.customization.value.title, 'Keep my edits')
    assert.equal(context.libraryOnly, true)
    assert.equal(context.resourceId, undefined)
    assert.equal(provided.get(libraryKey), context)
    assert.equal(provided.get(uploadKey), upload)
    assert.equal(emitted.some(event => event[0] === 'clearErrors'), true)
    assert.match(descriptor.template.content, /v-if="visibility === 'public'"[\s\S]*ResourceLibraryCustomizationForm/)
})

for (const component of ['ResourceLibraryManageModal', 'ResourceLibraryCustomizationForm', 'ResourceImagePicker']) {
    test(`${component} compiles with complete field wiring`, () => {
        const filename = `../../resources/js/components/Groups/Resources/${component}.vue`
        const { descriptor, errors } = parse(readFileSync(new URL(filename, import.meta.url), 'utf8'))
        assert.deepEqual(errors, [])
        const script = compileScript(descriptor, { id: component })
        const template = compileTemplate({ source: descriptor.template.content, filename, id: component, compilerOptions: { bindingMetadata: script.bindings } })
        assert.deepEqual(template.errors, [])
        if (component === 'ResourceLibraryCustomizationForm') {
            for (const field of ['title', 'introduction']) assert.match(descriptor.template.content, new RegExp(`<UFormField name="customization\\.${field}"[^>]* required>`))
            assert.match(descriptor.scriptSetup.content, /\['light', 'dark', 'system'\]/)
            const labels = JSON.parse(readFileSync(new URL('../../lang/en/groups/resources.json', import.meta.url), 'utf8')).library.customization
            assert.deepEqual(['light', 'dark', 'system'].map(key => labels[key]), ['Light', 'Dark', 'User Preference'])
            for (const field of ['title', 'introduction', 'banner_image_id', 'banner_focal_x', 'banner_focal_y', 'logo_image_id', 'sharing_image_id', 'accent_color', 'appearance']) assert.ok(descriptor.template.content.includes(`customization.${field}`), field)
            assert.match(descriptor.template.content, /UColorPicker/)
            assert.doesNotMatch(descriptor.template.content, /type="color"/)
            assert.match(descriptor.template.content, /model.links.length >= 8/)
        }
    })
}
