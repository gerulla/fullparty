<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CaseDetail, ModerationAction } from '@/Types/Reports'

const props = defineProps<{ detail: CaseDetail; busy: boolean; adminId: number }>()
const emit = defineEmits<{ action: [action: ModerationAction, reason: string]; feedback: [template: string, message: string] }>()
const { t } = useI18n()
const action = ref<ModerationAction>('dismiss')
const reason = ref('')
const template = ref('')
const message = ref('')
const owned = computed(() => props.detail?.case.assigned_to === props.adminId)
const hasRecipients = computed(() => props.detail.case.reports.some(report => report.reporter !== null))
const options = computed(() => {
    const d = props.detail
    if (!d) return []
    const values: ModerationAction[] = d.case.status === 'resolved' ? ['reopen'] : ['dismiss']
    if (d.can_hide && (d.is_hidden || d.case.status !== 'resolved')) values.push(d.is_hidden ? 'restore' : 'hide')
    if (d.subject_user && !d.subject_user.is_admin && d.subject_user.id !== props.adminId && (d.subject_user.banned_at || d.case.status !== 'resolved'))
        values.push(d.subject_user.banned_at ? 'unban' : 'ban')
    return values.map(value => ({ value, label: t('reports.actions.' + value) }))
})
const feedbackOptions = computed(() => {
    const actions = props.detail?.case.actions.map(entry => entry.action) ?? []
    const hidden = props.detail?.is_hidden && actions.filter(entry => ['hide', 'restore'].includes(entry)).at(-1) === 'hide'
    const banned = props.detail?.subject_user?.banned_at && actions.filter(entry => ['ban', 'unban'].includes(entry)).at(-1) === 'ban'
    const values = ['other']
    if (hidden) values.unshift('hidden')
    if (banned) values.unshift('banned')
    if (hidden || banned) values.unshift('action_taken')
    if (actions.at(-1) === 'dismiss') {
        if (!hidden && !banned) values.unshift('no_violation')
        if (!props.detail?.target_exists) values.unshift('already_unavailable')
    }
    return values.map(value => ({ value, label: t('reports.feedback_labels.' + value) }))
})
watch(() => [props.detail.case.id, props.detail.case.version], () => {
    action.value = options.value[0]?.value ?? 'dismiss'; reason.value = ''; template.value = ''; message.value = ''
}, { immediate: true })
</script>

<template>
    <section class="space-y-4">
        <h2 class="font-semibold">{{ t('reports.admin.action') }}</h2>
        <p class="text-sm text-muted">{{ t('reports.admin.assigned') }}: {{ detail.case.assignee?.name ?? t('reports.admin.unassigned') }}</p>
        <UButton v-if="!owned" :disabled="busy" icon="i-lucide-user-check" @click="emit('action', 'claim', '')">{{ t('reports.admin.claim') }}</UButton>
        <div v-if="owned && options.length" class="border border-default p-4 space-y-4">
            <UFormField :label="t('reports.admin.action')"><USelect v-model="action" :items="options" class="w-full" :disabled="busy" /></UFormField>
            <p v-if="['ban', 'unban'].includes(action)" class="font-semibold">{{ t('reports.admin.account') }}: {{ detail.subject_user?.name }} (#{{ detail.subject_user?.id }})</p>
            <UFormField :label="t('reports.admin.internal_reason')" required><UTextarea v-model="reason" :maxlength="2000" class="w-full" :disabled="busy" /></UFormField>
            <UButton color="warning" :disabled="busy || !reason.trim()" @click="emit('action', action, reason)">{{ t('reports.admin.apply') }}</UButton>
        </div>
        <div v-if="owned && detail.case.status === 'awaiting_feedback'" class="border border-default p-4 space-y-4">
            <h3 class="font-semibold">{{ t(hasRecipients ? 'reports.admin.send_feedback' : 'reports.admin.record_outcome') }}</h3>
            <p v-if="detail.case.reports.some(report => report.guest_reference)" class="text-xs text-muted">{{ t('reports.admin.guest_feedback_notice') }}</p>
            <USelect v-model="template" :items="feedbackOptions" :placeholder="t('reports.admin.choose_feedback')" class="w-full" :disabled="busy" />
            <UFormField v-if="template === 'other'" :label="t(hasRecipients ? 'reports.admin.feedback_message' : 'reports.admin.resolution_note')" required><UTextarea v-model="message" :maxlength="3000" :rows="4" class="w-full" :disabled="busy" /></UFormField>
            <p v-if="template" class="whitespace-pre-wrap break-words border-l-2 border-primary pl-3">{{ t('reports.feedback.' + template, { item: detail.case.title, message }) }}</p>
            <UButton :disabled="busy || !template || (template === 'other' && !message.trim())" @click="emit('feedback', template, message)">{{ t(hasRecipients ? 'reports.admin.send_resolve' : 'reports.admin.record_resolve') }}</UButton>
        </div>
    </section>
</template>
