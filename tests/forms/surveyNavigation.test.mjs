import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import * as pageUtils from '../../resources/js/utils/surveyPages.ts'
import { localizedValue } from '../../resources/js/utils/localizedValue.ts'

const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
dom.window.HTMLElement.prototype.scrollIntoView = function () {}
const vue = await import('vue')
const messages = JSON.parse(readFileSync(new URL('../../lang/en/forms.json', import.meta.url), 'utf8'))
const t = (key, values = {}) => Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, value), key.split('.').slice(1).reduce((entry, name) => entry[name], messages))

function component(name, dependencies = {}) {
    const filename = new URL(`../../resources/js/components/Forms/${name}.vue`, import.meta.url)
    const { descriptor } = parse(readFileSync(filename, 'utf8'))
    const script = compileScript(descriptor, { id: name, inlineTemplate: true })
    const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = { vue, 'vue-i18n': { useI18n: () => ({ t, locale: vue.ref('en') }) }, '@/utils/surveyPages': pageUtils, '@/utils/localizedValue': { localizedValue }, ...dependencies }
    const exports = {}
    new Function('require', 'exports', compiled)(name => { assert.ok(name in modules, `Unexpected dependency ${name}`); return modules[name] }, exports)
    return exports.default
}

const questionInputs = vue.defineComponent({
    props: ['questions', 'modelValue', 'errors', 'disabled', 'startIndex', 'language'], emits: ['update:modelValue'],
    setup: (props, { emit }) => () => props.questions.map(question => vue.h('label', [question.label.en, vue.h('input', {
        'data-question': question.id, value: props.modelValue[question.id] ?? '', disabled: props.disabled,
        onInput: event => emit('update:modelValue', { ...props.modelValue, [question.id]: event.target.value }),
    }), vue.h('span', props.errors?.[`answers.${question.id}`] ?? '')])),
})
const FormPageQuestions = component('FormPageQuestions', { './FormQuestions.vue': { default: questionInputs } })
const page = id => ({ id, title: { en: `Section ${id}` }, description: {} })
const question = (id, pageId, required = true) => ({ id, page_id: pageId, label: { en: id }, required })

function mount(t, component, props, update) {
    const container = document.createElement('div')
    document.body.append(container)
    const reference = vue.ref()
    const app = vue.createApp({ render: () => vue.h(component, { ...props, ...update, ref: reference }, { actions: () => vue.h('button', { type: 'submit' }, 'Submit') }) })
    app.component('UButton', vue.defineComponent({ props: ['label', 'disabled', 'type'], setup: (props, { attrs }) => () => vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled }, props.label) }))
    app.component('UProgress', { render: () => vue.h('div') })
    app.component('UFormField', { render() { return vue.h('label', this.$slots.default?.()) } })
    app.component('UInput', vue.defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], setup: (props, { emit, attrs }) => () => vue.h('input', { ...attrs, value: props.modelValue, onInput: event => emit('update:modelValue', event.target.value) }) }))
    app.component('UTextarea', { render: () => vue.h('textarea') })
    app.component('USelect', vue.defineComponent({ props: ['modelValue', 'items'], emits: ['update:modelValue'], setup: (props, { emit }) => () => vue.h('select', { value: props.modelValue, onChange: event => emit('update:modelValue', event.target.value) }, props.items.map(item => vue.h('option', { value: item.value }, item.label))) }))
    app.mount(container)
    t.after(() => { app.unmount(); container.remove() })
    return { container, reference, button: label => [...container.querySelectorAll('button')].find(button => button.textContent === label) }
}

test('respondents keep answers when navigating and can only submit on the last page', async t => {
    const props = vue.reactive({ definition: { pages: [page('a'), page('b')], questions: [question('first', 'a'), question('second', 'b')] }, modelValue: {} })
    const { container, button, reference } = mount(t, FormPageQuestions, props, { 'onUpdate:modelValue': answers => { props.modelValue = answers } })
    assert.equal(button('Submit'), undefined)
    button('Next').click()
    await vue.nextTick()
    assert.match(container.textContent, /Please answer this question/)
    const first = container.querySelector('input')
    first.value = 'First answer'
    first.dispatchEvent(new dom.window.Event('input', { bubbles: true }))
    await vue.nextTick()
    assert.equal(reference.value.prepareSubmit(), false)
    await vue.nextTick()
    assert.match(container.textContent, /Page 2 of 2/)
    assert.ok(button('Submit'))
    const second = container.querySelector('input')
    second.value = 'Second answer'
    second.dispatchEvent(new dom.window.Event('input', { bubbles: true }))
    await vue.nextTick()
    assert.equal(reference.value.prepareSubmit(), true)
    button('Back').click()
    await vue.nextTick()
    assert.equal(container.querySelector('input').value, 'First answer')
    assert.deepEqual({ ...props.modelValue }, { first: 'First answer', second: 'Second answer' })
    reference.value.revealErrors({ 'answers.second.0': 'Invalid choice' })
    await vue.nextTick()
    assert.match(container.textContent, /Page 2 of 2/)
    reference.value.reset()
    await vue.nextTick()
    assert.match(container.textContent, /Page 1 of 2/)
})

test('preview and read-only responses can navigate past unanswered required questions', async t => {
    for (const mode of [{ preview: true }, { disabled: true }]) {
        const props = vue.reactive({ definition: { pages: [page('a'), page('b')], questions: [question('first', 'a'), question('second', 'b')] }, modelValue: {}, ...mode })
        const { container, button } = mount(t, FormPageQuestions, props)
        button('Next').click()
        await vue.nextTick()
        assert.match(container.textContent, /Page 2 of 2/)
        assert.doesNotMatch(container.textContent, /Please answer this question/)
    }
})

test('removing and reordering pages preserves all questions and their stable identifiers', async t => {
    const builder = component('FormPageBuilder', { './FormQuestionBuilder.vue': { default: { render: () => vue.h('div') } } })
    const props = vue.reactive({ pages: [page('a'), page('b')], questions: [question('first', 'a'), question('second', 'b')], language: 'en' })
    const { container, button } = mount(t, builder, props, {
        'onUpdate:pages': pages => { props.pages = pages },
        'onUpdate:questions': questions => { props.questions = questions },
    })
    container.querySelector('button[aria-label="Move down"]').click()
    await vue.nextTick()
    assert.deepEqual(props.pages.map(page => page.id), ['b', 'a'])
    assert.deepEqual(props.questions.map(question => question.id), ['second', 'first'])
    button('Remove page').click()
    await vue.nextTick()
    assert.deepEqual(props.pages.map(page => page.id), ['b'])
    assert.deepEqual(props.questions.map(question => [question.id, question.page_id]), [['second', 'b'], ['first', 'b']])
    assert.equal(button('Remove page').disabled, true)
    button('Add page').click()
    await vue.nextTick()
    assert.equal(props.pages.length, 2)
    assert.equal(props.questions.length, 2)
    assert.equal(container.querySelector('select').value, props.pages[1].id)
})
