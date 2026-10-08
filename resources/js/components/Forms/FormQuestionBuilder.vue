<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { FormLocale, FormQuestion, FormQuestionType } from '@/Types/Forms'

const props = defineProps<{ modelValue: FormQuestion[]; language: FormLocale }>()
const emit = defineEmits<{ 'update:modelValue': [value: FormQuestion[]] }>()
const { t } = useI18n()
const types: FormQuestionType[] = ['short_text', 'long_text', 'single_choice', 'multiple_choice', 'dropdown', 'rating', 'number', 'date']
const choices = ['single_choice', 'multiple_choice', 'dropdown']
const items = computed(() => types.map(value => ({ value, label: t(`forms.types.${value}`) })))
const option = () => ({ id: crypto.randomUUID(), label: { en: '' } })
function update(index: number, patch: Partial<FormQuestion>) { emit('update:modelValue', props.modelValue.map((question, i) => i === index ? { ...question, ...patch } : question)) }
function add() { emit('update:modelValue', [...props.modelValue, { id: crypto.randomUUID(), type: 'short_text', label: { en: '' }, description: {}, required: false, options: [] }]) }
function changeType(index: number, type: FormQuestionType) { update(index, { type, options: choices.includes(type) ? [option(), option()] : [], min: null, max: null }) }
function move(index: number, direction: number) { const questions = [...props.modelValue]; const [question] = questions.splice(index, 1); questions.splice(index + direction, 0, question); emit('update:modelValue', questions) }
</script>

<template>
    <div class="space-y-4">
        <section v-for="(question, index) in modelValue" :key="question.id" class="space-y-4 border border-default bg-default p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold">{{ t('forms.question_number', { number: index + 1 }) }}</h3>
                <div class="flex gap-1">
                    <UButton icon="i-lucide-arrow-up" color="neutral" variant="ghost" :aria-label="t('forms.move_up')" :disabled="index === 0" @click="move(index, -1)" />
                    <UButton icon="i-lucide-arrow-down" color="neutral" variant="ghost" :aria-label="t('forms.move_down')" :disabled="index === modelValue.length - 1" @click="move(index, 1)" />
                    <UButton icon="i-lucide-trash-2" color="error" variant="ghost" :aria-label="t('forms.remove_question')" @click="emit('update:modelValue', modelValue.filter((_, i) => i !== index))" />
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-[1fr_14rem]">
                <UFormField :label="t('forms.question_label')" :required="language === 'en'"><UInput :model-value="question.label[language] ?? ''" class="w-full" :maxlength="300" @update:model-value="update(index, { label: { ...question.label, [language]: $event } })" /></UFormField>
                <UFormField :label="t('forms.question_type')"><USelect :model-value="question.type" :items="items" class="w-full" @update:model-value="changeType(index, $event as FormQuestionType)" /></UFormField>
            </div>
            <UFormField :label="t('forms.question_help')"><UInput :model-value="question.description[language] ?? ''" class="w-full" :maxlength="1000" @update:model-value="update(index, { description: { ...question.description, [language]: $event } })" /></UFormField>
            <div v-if="choices.includes(question.type)" class="space-y-2 border-l-2 border-primary/40 pl-4">
                <div v-for="(entry, optionIndex) in question.options" :key="entry.id" class="flex items-center gap-2">
                    <UInput :model-value="entry.label[language] ?? ''" :aria-label="t('forms.option_number', { number: optionIndex + 1 })" :placeholder="t('forms.option_number', { number: optionIndex + 1 })" class="flex-1" :maxlength="200" @update:model-value="update(index, { options: question.options.map((opt, j) => j === optionIndex ? { ...opt, label: { ...opt.label, [language]: $event } } : opt) })" />
                    <UButton icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="t('forms.remove_option')" :disabled="question.options.length <= 2" @click="update(index, { options: question.options.filter((_, j) => j !== optionIndex) })" />
                </div>
                <UButton icon="i-lucide-plus" color="neutral" variant="outline" :label="t('forms.add_option')" :disabled="question.options.length >= 25" @click="update(index, { options: [...question.options, option()] })" />
            </div>
            <div v-if="question.type === 'number'" class="grid grid-cols-2 gap-4">
                <UFormField :label="t('forms.minimum')"><UInput :model-value="question.min ?? ''" type="number" step="any" class="w-full" @update:model-value="update(index, { min: $event === '' ? null : Number($event) })" /></UFormField>
                <UFormField :label="t('forms.maximum')"><UInput :model-value="question.max ?? ''" type="number" step="any" class="w-full" @update:model-value="update(index, { max: $event === '' ? null : Number($event) })" /></UFormField>
            </div>
            <USwitch :model-value="question.required" :label="t('forms.required')" @update:model-value="update(index, { required: $event })" />
        </section>
        <UButton icon="i-lucide-plus" :label="t('forms.add_question')" :disabled="modelValue.length >= 50" @click="add" />
        <span class="ml-3 text-sm text-muted">{{ modelValue.length }} / 50</span>
    </div>
</template>
