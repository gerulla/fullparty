<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { FormResponseDetail } from '@/Types/Forms'
defineProps<{ open: boolean; loading: boolean; error: string; detail: FormResponseDetail | null }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
</script>
<template>
    <UModal :open="open" :title="detail ? `${t('forms.response_id')} #${detail.id}` : t('forms.response_detail')" :description="detail?.respondent ?? t('forms.response_detail')" :ui="{ content: 'max-w-2xl' }" @update:open="!$event && emit('close')">
        <template #body>
            <div v-if="loading" class="flex justify-center p-8"><UIcon name="i-lucide-loader-circle" class="animate-spin" :aria-label="t('forms.loading')" /></div>
            <UAlert v-else-if="error" color="error" :title="error" />
            <dl v-else-if="detail" class="space-y-5">
                <div v-for="(answer, index) in detail.answers" :key="index"><dt class="mb-1 font-medium">{{ answer.question }}</dt><dd class="whitespace-pre-wrap break-words text-muted">{{ answer.answer || t('forms.no_answer') }}</dd></div>
            </dl>
        </template>
        <template #footer><UButton color="neutral" variant="outline" :label="t('forms.close_button')" @click="emit('close')" /></template>
    </UModal>
</template>
