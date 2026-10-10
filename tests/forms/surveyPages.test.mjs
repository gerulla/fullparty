import assert from 'node:assert/strict'
import test from 'node:test'
import { questionsByPage, orderPageQuestions, missingRequiredQuestions, firstPageWithErrors } from '../../resources/js/utils/surveyPages.ts'

const page = id => ({ id, title: {}, description: {} })
const question = (id, pageId, required = false) => ({ id, page_id: pageId, required })

test('legacy forms show all questions on one page without changing question identifiers', () => {
    const questions = [question('first'), question('second')]
    for (const pages of [undefined, []]) {
        const result = questionsByPage(pages, questions)
        assert.equal(result.length, 1)
        assert.equal(result[0].offset, 0)
        assert.equal(result[0].questions, questions)
    }
})

test('page ordering keeps question numbering continuous and retains empty introductory pages', () => {
    const questions = [question('third', 'b'), question('first', 'a'), question('second', 'a')]
    const pages = [page('intro'), page('a'), page('b')]
    const result = questionsByPage(pages, questions)
    assert.deepEqual(result.map(p => p.questions.map(q => q.id)), [[], ['first', 'second'], ['third']])
    assert.deepEqual(result.map(p => p.offset), [0, 0, 2])
    assert.deepEqual(orderPageQuestions(pages, questions).map(q => q.id), ['first', 'second', 'third'])
    assert.deepEqual(orderPageQuestions([...pages].reverse(), questions).map(q => q.id), ['third', 'first', 'second'])
    assert.deepEqual(questions.map(q => q.id), ['third', 'first', 'second'])
})

test('required answers distinguish zero from empty values and only check the current page', () => {
    const questions = ['zero', 'text', 'spaces', 'choices', 'missing'].map(id => question(id, 'a', true))
    questions.push(question('optional', 'a'), question('later', 'b', true))
    const current = questionsByPage([page('a'), page('b')], questions)[0]
    const missing = missingRequiredQuestions(current.questions, { zero: 0, text: 'Answer', spaces: '  ', choices: [] })
    assert.deepEqual(missing.map(q => q.id), ['spaces', 'choices', 'missing'])
})

test('server errors find the first affected page including nested multiple-choice errors', () => {
    const pages = questionsByPage([page('a'), page('b')], [question('first', 'a'), question('second', 'b')])
    assert.equal(firstPageWithErrors(pages, { 'answers.second.0': 'Invalid option' }), 1)
    assert.equal(firstPageWithErrors(pages, { 'answers.second': 'Required', 'answers.first': 'Required' }), 0)
    assert.equal(firstPageWithErrors(pages, { form: 'Closed', 'answers.first-extra': 'Unknown' }), -1)
})
