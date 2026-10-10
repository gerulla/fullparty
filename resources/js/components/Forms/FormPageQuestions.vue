<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import FormQuestions from './FormQuestions.vue'
import { localizedValue } from '@/utils/localizedValue'
import { firstPageWithErrors, missingRequiredQuestions, questionsByPage } from '@/utils/surveyPages'
import type { FormAnswers, FormDefinition, FormPageNavigation } from '@/Types/Forms'

const props = defineProps<{ definition: FormDefinition; modelValue: FormAnswers; disabled?: boolean; busy?: boolean; preview?: boolean; errors?: Record<string, string>; language?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: FormAnswers] }>()
const { t, locale } = useI18n()
const displayLocale = computed(() => props.language ?? locale.value)
const pages = computed(() => questionsByPage(props.definition.pages, props.definition.questions))
const activeIndex = ref(0)
const index = computed(() => Math.min(activeIndex.value, pages.value.length - 1))
const current = computed(() => pages.value[index.value])
const lastPage = computed(() => index.value === pages.value.length - 1)
const container = ref<HTMLElement | null>(null)
const requiredErrors = ref<Record<string, string>>({})
const displayedErrors = computed(() => ({ ...props.errors, ...requiredErrors.value }))

async function navigate(destination: number) {
    activeIndex.value = destination
    requiredErrors.value = {}
    await nextTick()
    container.value?.focus({ preventScroll: true })
    container.value?.scrollIntoView({ block: 'start' })
}
function validatePage() {
    if (props.preview || props.disabled) return true
    requiredErrors.value = Object.fromEntries(missingRequiredQuestions(current.value.questions, props.modelValue).map(question => [`answers.${question.id}`, t('forms.errors.required')]))
    return Object.keys(requiredErrors.value).length === 0
}
function next() {
    if (!props.busy && !lastPage.value && validatePage()) void navigate(index.value + 1)
}
function updateAnswers(answers: FormAnswers) {
    requiredErrors.value = {}
    emit('update:modelValue', answers)
}
function prepareSubmit() {
    if (!lastPage.value) { next(); return false }
    return validatePage()
}
function revealErrors(errors: Record<string, string>) {
    const destination = firstPageWithErrors(pages.value, errors)
    if (destination >= 0) void navigate(destination)
}
function reset() { activeIndex.value = 0; requiredErrors.value = {} }
defineExpose<FormPageNavigation>({ prepareSubmit, revealErrors, reset })
</script>

<template>
    <section ref="container" tabindex="-1" class="scroll-mt-6 space-y-5 outline-none" :aria-label="t('forms.page_of', { page: index + 1, total: pages.length })">
        <div v-if="pages.length > 1" class="space-y-2" aria-live="polite">
            <p class="text-sm font-medium text-muted">{{ t('forms.page_of', { page: index + 1, total: pages.length }) }}</p>
            <UProgress :model-value="index + 1" :max="pages.length" :aria-label="t('forms.pages')" />
        </div>
        <header v-if="pages.length > 1 || localizedValue(current.title, displayLocale) || localizedValue(current.description, displayLocale)" class="space-y-2 border-l-4 border-primary bg-default p-5">
            <h2 class="text-xl font-semibold">{{ localizedValue(current.title, displayLocale) || t('forms.page_number', { number: index + 1 }) }}</h2>
            <p v-if="localizedValue(current.description, displayLocale)" class="whitespace-pre-wrap text-sm text-muted">{{ localizedValue(current.description, displayLocale) }}</p>
        </header>
        <FormQuestions :key="current.id" :model-value="modelValue" :questions="current.questions" :start-index="current.offset" :disabled="disabled || busy" :errors="displayedErrors" :language="displayLocale" @update:model-value="updateAnswers" />
        <p v-if="!disabled" class="text-xs text-muted">{{ t('forms.required_notice') }}</p>
        <nav class="flex flex-wrap items-center justify-between gap-3" :aria-label="t('forms.pages')">
            <div><UButton v-if="index > 0" type="button" color="neutral" variant="outline" icon="i-lucide-arrow-left" :label="t('forms.previous_page')" :disabled="busy" @click="navigate(index - 1)" /></div>
            <UButton v-if="!lastPage" type="button" trailing-icon="i-lucide-arrow-right" :label="t('forms.next_page')" :disabled="busy" @click="next" />
            <slot v-else name="actions" />
        </nav>
    </section>
</template>
