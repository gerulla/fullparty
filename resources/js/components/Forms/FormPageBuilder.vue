<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import FormQuestionBuilder from './FormQuestionBuilder.vue'
import { localizedValue } from '@/utils/localizedValue'
import { orderPageQuestions, questionsByPage } from '@/utils/surveyPages'
import type { FormLocale, FormPage, FormQuestion } from '@/Types/Forms'

const props = defineProps<{ pages: FormPage[]; questions: FormQuestion[]; language: FormLocale }>()
const emit = defineEmits<{ 'update:pages': [value: FormPage[]]; 'update:questions': [value: FormQuestion[]] }>()
const { t } = useI18n()
const selectedId = ref(props.pages[0]?.id)
const pageIndex = computed(() => Math.max(0, props.pages.findIndex(page => page.id === selectedId.value)))
const current = computed(() => questionsByPage(props.pages, props.questions)[pageIndex.value])
const items = computed(() => props.pages.map((page, index) => ({ value: page.id, label: `${index + 1}. ${localizedValue(page.title, props.language) || t('forms.page_number', { number: index + 1 })}` })))

function updatePage(patch: Partial<FormPage>) {
    emit('update:pages', props.pages.map(page => page.id === current.value.id ? { ...page, ...patch } : page))
}
function addPage() {
    if (props.pages.length >= 20) return
    const page: FormPage = { id: crypto.randomUUID(), title: {}, description: {} }
    emit('update:pages', [...props.pages, page])
    selectedId.value = page.id
}
function movePage(direction: number) {
    const pages = [...props.pages]
    const [page] = pages.splice(pageIndex.value, 1)
    pages.splice(pageIndex.value + direction, 0, page)
    selectedId.value = page.id
    emit('update:pages', pages)
    emit('update:questions', orderPageQuestions(pages, props.questions))
}
function removePage() {
    if (props.pages.length <= 1) return
    const removedId = current.value.id
    const destination = props.pages[pageIndex.value === 0 ? 1 : pageIndex.value - 1]
    const pages = props.pages.filter(page => page.id !== removedId)
    const questions = props.questions.map(question => question.page_id === removedId ? { ...question, page_id: destination.id } : question)
    emit('update:pages', pages)
    emit('update:questions', orderPageQuestions(pages, questions))
    selectedId.value = destination.id
}
function updateQuestions(questions: FormQuestion[]) {
    const currentIds = new Set(current.value.questions.map(question => question.id))
    const others = props.questions.filter(question => !currentIds.has(question.id))
    emit('update:questions', orderPageQuestions(props.pages, [...others, ...questions]))
}
</script>

<template>
    <div class="space-y-5">
        <section class="space-y-4 border border-default bg-elevated/50 p-4">
            <div class="flex flex-wrap items-end gap-3">
                <UFormField :label="t('forms.pages')" class="min-w-48 flex-1">
                    <USelect :model-value="current.id" :items="items" class="w-full" @update:model-value="selectedId = $event as string" />
                </UFormField>
                <UButton type="button" icon="i-lucide-plus" :label="t('forms.add_page')" :disabled="pages.length >= 20" @click="addPage" />
                <div class="flex gap-1">
                    <UButton type="button" icon="i-lucide-arrow-up" color="neutral" variant="outline" :aria-label="t('forms.move_up')" :disabled="pageIndex === 0" @click="movePage(-1)" />
                    <UButton type="button" icon="i-lucide-arrow-down" color="neutral" variant="outline" :aria-label="t('forms.move_down')" :disabled="pageIndex === pages.length - 1" @click="movePage(1)" />
                    <UButton type="button" icon="i-lucide-trash-2" color="error" variant="outline" :label="t('forms.remove_page')" :disabled="pages.length <= 1" @click="removePage" />
                </div>
            </div>
            <p class="text-sm text-muted">{{ t('forms.pages_help') }}</p>
            <UFormField :label="t('forms.page_title')"><UInput :model-value="current.title[language] ?? ''" class="w-full" :maxlength="160" :placeholder="t('forms.page_number', { number: pageIndex + 1 })" @update:model-value="updatePage({ title: { ...current.title, [language]: $event } })" /></UFormField>
            <UFormField :label="t('forms.page_description')"><UTextarea :model-value="current.description[language] ?? ''" class="w-full" :maxlength="1000" @update:model-value="updatePage({ description: { ...current.description, [language]: $event } })" /></UFormField>
            <p v-if="pages.length > 1" class="text-xs text-muted">{{ t('forms.remove_page_help') }}</p>
        </section>
        <FormQuestionBuilder :key="current.id" :model-value="current.questions" :pages="pages" :page-id="current.id" :start-index="current.offset" :total-questions="questions.length" :language="language" @update:model-value="updateQuestions" />
    </div>
</template>
