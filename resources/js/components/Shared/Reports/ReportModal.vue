<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { ReportTarget } from '@/Types/Reports'

const props = withDefaults(defineProps<{ open?: boolean; target: ReportTarget; guestSubmitUrl?: string }>(), { open: false })
const emit = defineEmits<{ 'update:open': [open: boolean]; close: [submitted: boolean]; submitted: []; 'after:leave': [] }>()
const { t } = useI18n()
const formId = useId()
const reason = ref('')
const details = ref('')
const busy = ref(false)
const error = ref('')
const submitted = ref(false)
const guestClient = axios.create()
const thanks = computed(() => t(props.guestSubmitUrl ? 'reports.guest_thanks' : 'reports.thanks'))
const reasons = computed(() => ['harassment', 'hate', 'sexual_content', 'violence', 'spam', 'privacy', 'scam', 'other'].map(value => ({ value, label: t('reports.reasons.' + value) })))
watch(() => [props.open, props.target.type, props.target.id], () => {
    if (busy.value) return
    reason.value = ''; details.value = ''; error.value = ''; submitted.value = false
})
function close() {
    if (busy.value) return
    emit('update:open', false)
    emit('close', submitted.value)
}
async function submit() {
    if (busy.value || !reason.value || (reason.value === 'other' && !details.value.trim())) return
    busy.value = true; error.value = ''
    try {
        // Guest submissions stay on the public host and do not trigger the app's login redirect interceptor.
        await (props.guestSubmitUrl ? guestClient : axios).post(props.guestSubmitUrl ?? route('reports.store'), {
            target_type: props.target.type, target_id: props.target.id, reason: reason.value,
            details: reason.value === 'other' ? details.value : null,
        })
        submitted.value = true
        emit('submitted')
    } catch (cause) {
        const status = axios.isAxiosError(cause) ? cause.response?.status : undefined
        error.value = t(status === 429 ? 'reports.errors.rate_limit' : status === 419 ? 'reports.errors.session_expired' : 'reports.errors.submit')
    } finally { busy.value = false }
}
</script>

<template>
    <UModal :open="open" :title="submitted ? t('reports.thanks_title') : t('reports.title')"
        :description="submitted ? thanks : t('reports.description')" :dismissible="!busy"
        @update:open="value => { if (!value) close() }" @after:leave="emit('after:leave')">
        <template #body>
            <div v-if="submitted" class="flex items-center gap-3">
                <UIcon name="i-lucide-circle-check" class="size-6 text-success" />
                <p>{{ thanks }}</p>
            </div>
            <form v-else :id="formId" class="space-y-4" @submit.prevent="submit">
                <UBadge color="neutral" variant="subtle" :label="t('reports.types.' + target.type)" />
                <p v-if="target.label" class="font-medium break-words">{{ target.label }}</p>
                <p v-if="guestSubmitUrl" class="text-sm text-muted">{{ t('reports.guest_notice') }}</p>
                <URadioGroup v-model="reason" :legend="t('reports.reason')" :items="reasons" :disabled="busy" />
                <UFormField v-if="reason === 'other'" :label="t('reports.details')" required>
                    <UTextarea v-model="details" :maxlength="3000" :rows="5" class="w-full" :disabled="busy" />
                </UFormField>
                <UAlert v-if="error" color="error" :description="error" />
            </form>
        </template>
        <template #footer>
            <div class="flex justify-end gap-2 w-full">
                <UButton color="neutral" variant="outline" :disabled="busy" @click="close">{{ t('reports.close') }}</UButton>
                <UButton v-if="!submitted" type="submit" :form="formId" :loading="busy"
                    :disabled="!reason || (reason === 'other' && !details.trim())">{{ t('reports.submit') }}</UButton>
            </div>
        </template>
    </UModal>
</template>
