<script setup lang="ts">
import type { AuditLogEventRecord, AuditLogRowRecord } from '@/Types/Audit'
import AuditEventSummary from './AuditEventSummary.vue'
import AuditEventValue from './AuditEventValue.vue'
import { auditSeverityStyle } from '@/utils/auditPresentation'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'
import { router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
    row?: AuditLogRowRecord | null
    event?: AuditLogEventRecord | null
    loading?: boolean
    failed?: boolean
    copied?: boolean
}>()
const emit = defineEmits<{ close: []; retry: []; 'copy-link': [] }>()
const { t, te, locale } = useI18n()
const { withDisplayTimeZone, isServerTime } = useTimeDisplayMode()
const display = computed(() => props.event ?? props.row)
const severity = computed(() => auditSeverityStyle(display.value?.severity ?? 'info'))
const severityLabel = computed(() => {
    const value = display.value?.severity ?? 'info'
    return te(`audit_log.severities.${value}`) ? t(`audit_log.severities.${value}`) : value
})
const timestamp = computed(() => display.value ? createDateTimeFormatter(locale.value, withDisplayTimeZone({
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
})).format(new Date(display.value.created_at)) : '')
const resourceLabel = computed(() => t(display.value?.resource?.kind === 'collection' ? 'audit_log.fields.collection_name'
    : display.value?.resource?.kind === 'image' ? 'audit_log.fields.image_name' : 'audit_log.details.resource'))
const resourceActionLabel = computed(() => t(display.value?.resource?.kind === 'collection' ? 'audit_log.details.view_collection'
    : display.value?.resource?.kind === 'library' ? 'audit_log.details.view_library' : 'audit_log.details.view_resource'))
</script>

<template>
    <div class="@container min-w-0 space-y-6 p-4 sm:p-5">
        <div class="flex items-start justify-between gap-3">
            <h3 class="pt-1 text-lg font-semibold text-highlighted">{{ t('audit_log.details.title') }}</h3>
            <UButton type="button" color="neutral" variant="ghost" icon="i-lucide-x" class="shrink-0 rounded-lg"
                :aria-label="t('audit_log.details.close')" @click="emit('close')" />
        </div>

        <template v-if="display">
            <p class="text-base text-toned"><AuditEventSummary :row="display" /></p>
            <dl class="grid min-w-0 grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] gap-x-4 gap-y-3 text-sm">
                <dt class="text-muted">{{ t('audit_log.details.actor') }}</dt>
                <dd class="flex min-w-0 items-start gap-2 font-medium text-highlighted">
                    <UAvatar :src="display.actor.avatar_url ?? undefined" :alt="display.actor.name"
                        :icon="display.actor.is_system ? 'i-lucide-cpu' : 'i-lucide-user-round'" size="2xs" class="shrink-0" />
                    <span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ display.actor.name }}</span>
                </dd>
                <template v-if="display.activity">
                    <dt class="text-muted">{{ t('audit_log.details.run') }}</dt>
                    <dd class="min-w-0 break-words font-medium text-highlighted [overflow-wrap:anywhere]">{{ display.activity.title }}</dd>
                </template>
                <template v-if="display.resource">
                    <dt class="text-muted">{{ resourceLabel }}</dt>
                    <dd class="min-w-0 break-words font-medium text-highlighted [overflow-wrap:anywhere]">{{ display.resource.title }}</dd>
                </template>
                <dt class="text-muted">{{ t('audit_log.details.timestamp') }}</dt>
                <dd class="min-w-0 text-toned"><time :datetime="display.created_at">{{ timestamp }} {{ isServerTime ? 'ST' : 'LT' }}</time></dd>
                <dt class="text-muted">{{ t('audit_log.details.severity') }}</dt>
                <dd class="min-w-0">
                    <UBadge :color="severity.color" :icon="severity.icon" variant="subtle" size="sm" class="max-w-full rounded-full whitespace-normal">
                        {{ severityLabel }}
                    </UBadge>
                </dd>
                <dt class="text-muted">{{ t('audit_log.details.event_id') }}</dt>
                <dd class="font-medium tabular-nums text-toned">#{{ display.id }}</dd>
            </dl>
        </template>

        <div v-if="loading" role="status" :aria-label="t('audit_log.details.loading')" :aria-busy="true" class="space-y-4 border-t border-default pt-5">
            <USkeleton v-for="index in 3" :key="index" class="h-14 w-full rounded-lg" />
        </div>
        <div v-else-if="failed" role="alert" class="space-y-3 rounded-lg border border-error/25 bg-error/5 p-4">
            <p class="text-sm text-error">{{ t('audit_log.details.load_error') }}</p>
            <UButton type="button" color="neutral" variant="outline" icon="i-lucide-refresh-cw" class="rounded-lg"
                :label="t('audit_log.list.retry')" @click="emit('retry')" />
        </div>
        <template v-else-if="event">
            <section v-if="event.formatted_changes.length" class="space-y-3 border-t border-default pt-5">
                <h4 class="text-sm font-semibold text-highlighted">{{ t('audit_log.list.changes') }}</h4>
                <div class="overflow-hidden rounded-lg border border-default">
                    <div class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.2fr)] gap-4 bg-elevated/60 p-3 text-xs font-semibold text-muted @xs:grid">
                        <span>{{ t('audit_log.details.field') }}</span>
                        <span>{{ t('audit_log.list.old_value') }}</span>
                        <span>{{ t('audit_log.list.new_value') }}</span>
                    </div>
                    <div class="divide-y divide-default">
                        <div v-for="change in event.formatted_changes" :key="change.key"
                            class="grid min-w-0 gap-3 p-3 text-sm @xs:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.2fr)] @xs:gap-4">
                            <p class="col-span-full min-w-0 break-words font-medium text-highlighted @xs:col-span-1">{{ change.label }}</p>
                            <div class="min-w-0 space-y-1 text-muted">
                                <p class="text-xs font-medium @xs:hidden">{{ t('audit_log.list.old_value') }}</p>
                                <AuditEventValue :value="change.old_value" />
                            </div>
                            <div class="min-w-0 space-y-1 text-toned">
                                <p class="text-xs font-medium text-muted @xs:hidden">{{ t('audit_log.list.new_value') }}</p>
                                <AuditEventValue :value="change.new_value" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <section v-if="event.facts.length" class="space-y-3 border-t border-default pt-5">
                <h4 class="text-sm font-semibold text-highlighted">{{ t('audit_log.details.recorded_values') }}</h4>
                <dl class="space-y-4 text-sm">
                    <div v-for="fact in event.facts" :key="fact.key" class="min-w-0 space-y-1">
                        <dt class="font-medium text-muted">{{ fact.label }}</dt>
                        <dd class="min-w-0 text-toned"><AuditEventValue :value="fact.value" /></dd>
                    </div>
                </dl>
            </section>
        </template>

        <div v-if="display" class="flex flex-wrap gap-3 border-t border-default pt-5">
            <UButton v-if="display.activity?.url" type="button" color="brand" icon="i-lucide-arrow-up-right"
                class="min-w-0 flex-1 justify-center rounded-lg whitespace-normal" :label="t('audit_log.details.view_run')"
                @click="router.visit(display.activity.url)" />
            <UButton v-if="display.resource?.url" type="button" color="brand" icon="i-lucide-arrow-up-right"
                class="min-w-0 flex-1 justify-center rounded-lg whitespace-normal" :label="resourceActionLabel"
                @click="router.visit(display.resource.url)" />
            <UButton type="button" color="neutral" variant="outline" :icon="copied ? 'i-lucide-check' : 'i-lucide-link'"
                class="min-w-0 flex-1 justify-center rounded-lg whitespace-normal"
                :label="t(copied ? 'audit_log.details.link_copied' : 'audit_log.details.copy_link')" @click="emit('copy-link')" />
        </div>
    </div>
</template>
