import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import * as vue from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const source = readFileSync(new URL('../../resources/js/composables/useSurveyFormEditor.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
const emptyDocument = () => ({ type: 'doc', content: [{ type: 'paragraph' }] })
const modules = {
    vue,
    '@inertiajs/vue3': { router, useForm },
    'ziggy-js': { route: name => name },
    'vue-i18n': { useI18n: () => ({ t: key => key }) },
    '@/composables/useConfirmationModal': { useConfirmationModal: () => ({ open: async () => true }) },
    '@/utils/richText': { emptyRichTextDocument: emptyDocument },
}
const exports = {}
new Function('require', 'exports', compiled)(name => { assert.ok(name in modules, name); return modules[name] }, exports)

function editor(t, props) {
    const scope = vue.effectScope()
    t.after(() => scope.stop())
    return scope.run(() => exports.useSurveyFormEditor(props))
}

for (const existing of [false, true]) {
    test(`completion messages survive saving and reloading ${existing ? 'an existing' : 'a new'} form with empty PHP translation arrays`, async t => {
        const definition = { title: { en: 'Feedback' }, intro: [], thank_you: [], questions: [], pages: [], access: 'account', response_policy: 'single_editable' }
        const props = vue.reactive({ emptyDefinition: definition, formRecord: existing ? { slug: 'feedback', revision: 1, draft: definition } : null })
        const api = editor(t, props)
        const messages = { en: 'Thanks for your feedback!', de: 'Danke für dein Feedback!', fr: 'Merci pour vos commentaires !', ja: 'ご回答ありがとうございます！' }
        for (const [language, message] of Object.entries(messages)) {
            api.language.value = language
            api.form.definition.thank_you[language] = message
        }
        let request
        t.mock.method(router, existing ? 'put' : 'post', (_url, data, options) => { request = { data: JSON.parse(JSON.stringify(data)), options } })
        api.save()
        assert.deepEqual(request.data.definition.thank_you, messages)
        assert.deepEqual(request.data.definition.intro.en, emptyDocument())

        props.formRecord = { slug: 'feedback', revision: existing ? 2 : 1, draft: request.data.definition }
        await vue.nextTick()
        await request.options.onSuccess({ props })
        assert.deepEqual({ ...api.form.definition.thank_you }, messages)
        assert.deepEqual({ ...editor(t, props).form.definition.thank_you }, messages)
        assert.equal(api.form.isDirty, false)
    })
}
