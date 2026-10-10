<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'
import { localizedValue } from '@/utils/localizedValue'
import type { FormAnswers, FormQuestion, FormAnswer } from '@/Types/Forms'

const props = defineProps<{ questions: FormQuestion[]; modelValue: FormAnswers; disabled?: boolean; errors?: Record<string, string>; language?: string; startIndex?: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: FormAnswers] }>()
const { t, locale } = useI18n()
const displayLocale = computed(() => props.language ?? locale.value)
const update = (id: string, value: FormAnswer | undefined) => emit('update:modelValue', { ...props.modelValue, [id]: value ?? null })
const options = (question: FormQuestion) => question.options.map(option => ({ value: option.id, label: localizedValue(option.label, displayLocale.value) }))
const errorFor = (id: string) => Object.entries(props.errors ?? {}).find(([key]) => key === `answers.${id}` || key.startsWith(`answers.${id}.`))?.[1]
</script>

<template>
    <div class="space-y-5">
        <section v-for="(question, index) in questions" :key="question.id" class="border border-default bg-default p-5">
            <UFormField :label="`${(startIndex ?? 0) + index + 1}. ${localizedValue(question.label, displayLocale)}`" :description="localizedValue(question.description, displayLocale)" :required="question.required" :error="errorFor(question.id)">
                <UInput v-if="question.type === 'short_text'" :model-value="modelValue[question.id] as string ?? ''" :disabled="disabled" :maxlength="500" class="w-full" @update:model-value="update(question.id, $event)" />
                <UTextarea v-else-if="question.type === 'long_text'" :model-value="modelValue[question.id] as string ?? ''" :disabled="disabled" :rows="5" :maxlength="5000" class="w-full" @update:model-value="update(question.id, $event)" />
                <URadioGroup v-else-if="question.type === 'single_choice'" :model-value="modelValue[question.id] as string" :items="options(question)" :disabled="disabled" @update:model-value="update(question.id, $event as string)" />
                <UCheckboxGroup v-else-if="question.type === 'multiple_choice'" :model-value="(modelValue[question.id] as string[]) ?? []" :items="options(question)" :disabled="disabled" @update:model-value="update(question.id, $event as string[])" />
                <USelect v-else-if="question.type === 'dropdown'" :model-value="modelValue[question.id] as string" :items="options(question)" :placeholder="t('forms.choose_option')" :disabled="disabled" class="w-full" @update:model-value="update(question.id, $event as string)" />
                <URadioGroup v-else-if="question.type === 'rating'" :model-value="modelValue[question.id] as number" :items="[1, 2, 3, 4, 5].map(value => ({ value, label: String(value) }))" orientation="horizontal" :disabled="disabled" @update:model-value="update(question.id, Number($event))" />
                <UInput v-else-if="question.type === 'number'" :model-value="modelValue[question.id] as number ?? ''" type="number" step="any" :min="question.min ?? undefined" :max="question.max ?? undefined" :disabled="disabled" class="w-full" @update:model-value="update(question.id, $event === '' ? null : $event)" />
                <UInput v-else-if="question.type === 'date'" :model-value="modelValue[question.id] as string ?? ''" type="date" :disabled="disabled" class="w-full" @update:model-value="update(question.id, $event)" />
            </UFormField>
        </section>
    </div>
</template>
