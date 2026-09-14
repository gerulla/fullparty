import assert from 'node:assert/strict'
import test from 'node:test'
import { existsSync, readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import * as preview from '../../resources/js/utils/resourceEmbedPreview.ts'
import { workspaceEmbed } from '../../resources/js/utils/resourceWorkspaceData.ts'

function editor() {
    const file = new URL('../../resources/js/components/Groups/Resources/ResourceDiscordEditor.vue', import.meta.url)
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const source = compileScript(descriptor, { id: 'embed-editor' }).content
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key, locale: vue.ref('en') }) },
        '@/utils/resourceEmbedPreview': preview,
        './ResourceEmbedPreview.vue': { default: {} }, './ResourceImagePicker.vue': { default: {} },
        './ResourceDiscordButtonsEditor.vue': { default: {} },
    }
    const exports = {}
    new Function('require', 'exports', compiled)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports)
    const embed = vue.reactive(workspaceEmbed())
    const props = vue.reactive({ embed, previewEmbed: embed, document: { access: 'everyone' }, commandError: undefined })
    const vm = exports.default.setup(props, { expose() {} })
    return { vm, embed, props }
}

test('command errors bind to the form field without stealing focus during autosave', async () => {
    const { vm, props } = editor()
    props.commandError = 'Command name is required.'
    await vue.nextTick(); await vue.nextTick()
    assert.equal(vm.commandInput, undefined)
    props.commandError = undefined
    await vue.nextTick(); await vue.nextTick()
    const file = new URL('../../resources/js/components/Groups/Resources/ResourceDiscordEditor.vue', import.meta.url)
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const script = compileScript(descriptor, { id: 'command-error' })
    const template = compileTemplate({ source: descriptor.template.content, filename: file.pathname, id: 'command-error', compilerOptions: { bindingMetadata: script.bindings } })
    assert.deepEqual(template.errors, [])
    assert.match(template.code, /error: \$props.commandError/)
    assert.match(template.code, /name: "command"/)
    assert.match(template.code, /data-resource-field/)
})

test('the bot avatar stays a public URL rather than becoming a Vite asset import', () => {
    const { vm } = editor()
    assert.equal(vm.botAvatarUrl, '/logos/compact.png')
    assert.ok(existsSync(new URL(`../../public${vm.botAvatarUrl}`, import.meta.url)))
    const file = new URL('../../resources/js/components/Groups/Resources/ResourceDiscordEditor.vue', import.meta.url)
    const filename = file.pathname
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const script = compileScript(descriptor, { id: 'embed-avatar' })
    const template = compileTemplate({
        source: descriptor.template.content, filename, id: 'embed-avatar',
        transformAssetUrls: { includeAbsolute: true },
        compilerOptions: { bindingMetadata: script.bindings },
    })
    assert.deepEqual(template.errors, [])
    assert.doesNotMatch(template.code, /import\s+.*?from\s+["']\/logos\//)
    assert.match(template.code, /botAvatarUrl/)
})

test('embed fields can be added, reordered, and focused without exceeding twenty-five', async () => {
    const { vm, embed } = editor()
    const calls = []
    vm.fieldEditor.value = { lastElementChild: { querySelector: () => ({ focus: () => calls.push('focus'), scrollIntoView: () => calls.push('scroll') }) } }
    for (let index = 0; index < 25; index++) {
        await vm.addField()
        embed.fields.at(-1).name = `Field ${index}`
    }
    await vm.addField()
    assert.equal(embed.fields.length, 25)
    assert.equal(calls.length, 50)
    assert.deepEqual(embed.fields[0], { name: 'Field 0', value: '', inline: false })
    vm.moveField(0, 1)
    assert.deepEqual(embed.fields.slice(0, 2).map(field => field.name), ['Field 1', 'Field 0'])
    vm.moveField(1, -1)
    vm.moveField(0, -1)
    vm.moveField(24, 1)
    assert.equal(embed.fields[0].name, 'Field 0')
    assert.equal(embed.fields[24].name, 'Field 24')
})

test('embed timestamps are read-only and character totals react to all content', () => {
    const { vm, embed } = editor()
    assert.equal(vue.isReadonly(vm.timestamp), true)
    assert.equal(vm.timestamp.value, 'groups.resources.workspace.set_on_save')
    embed.timestamp = '2026-09-11T13:30:00Z'
    assert.equal(vm.timestamp.value, new Date(embed.timestamp).toLocaleString('en', { dateStyle: 'medium', timeStyle: 'short' }))
    assert.equal(vm.characterCount.value, 9)
    embed.title = 'Plan'
    embed.fields.push({ name: 'Side', value: 'West', inline: true })
    assert.equal(vm.characterCount.value, 21)
})
