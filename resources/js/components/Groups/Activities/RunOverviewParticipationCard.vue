<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { ActivityOverviewPermissions, AttendeeActivity, PublicGroupSummary, RunOverviewViewerParticipation } from '@/Types/ActivityAttendee'
import type { ActivitySlot } from '@/Types/ActivityRoster'
import type { RunApplicationPreviewState } from '@/Types/RunParticipation'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { useMinuteTicker } from '@/composables/useMinuteTicker'
import { SERVER_TIME_ZONE } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter, dateTimeInputInZone, parseTimestamp } from '@/utils/dateTimeFormat'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import { canAcceptActivityApplications, isArchivedActivityStatus } from '@/utils/activityLifecycle'
import { localizedValue } from '@/utils/localizedValue'
import { getRunParticipationMeta } from '@/utils/runParticipationMeta'
import { previewRunApplication } from '@/utils/runOverviewApplicationPreview'
import AddToCalendarMenu from './AddToCalendarMenu.vue'
import RunOverviewPlacementDetails from './RunOverviewPlacementDetails.vue'

const props = withDefaults(defineProps<{
    activity: AttendeeActivity
    group: PublicGroupSummary
    permissions: ActivityOverviewPermissions
    previewState: RunApplicationPreviewState
    previewingRun: boolean
    secretKey?: string | null
    viewerParticipation?: RunOverviewViewerParticipation | null
    selfAssignedSlot?: ActivitySlot | null
    hasSelfAssignmentCharacters?: boolean
}>(), { hasSelfAssignmentCharacters: false })
const emit = defineEmits<{ 'show-roster': [] }>()
const { t, locale } = useI18n()
const { formatDateTime, formatTimeWithMode, timeDisplayLabel } = useDateTimeFormat()
const now = useMinuteTicker()
const localZone = Intl.DateTimeFormat().resolvedOptions().timeZone
const start = computed(() => parseTimestamp(props.activity.starts_at))

const schedule = computed(() => {
    const instant = start.value
    if (!instant) return null
    const serverFirst = timeDisplayLabel.value === 'ST'
    const otherZone = serverFirst ? localZone : SERVER_TIME_ZONE
    const differentDays = dateTimeInputInZone(instant, SERVER_TIME_ZONE).slice(0, 10) !== dateTimeInputInZone(instant, localZone).slice(0, 10)
    const secondaryTime = createDateTimeFormatter(locale.value, {
        ...(differentDays ? { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } as const : {}),
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: otherZone,
    }).format(instant)
    return {
        date: formatDateTime(instant, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }),
        primary: formatTimeWithMode(instant),
        secondary: secondaryTime + (serverFirst ? ' LT' : ' ST'),
    }
})
const relativeStart = computed(() => formatRelativeTime(props.activity.starts_at, locale.value, t('notifications.ui.just_now'), '', now.value))
const duration = computed(() => props.activity.duration_hours
    ? new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'hour', unitDisplay: 'long' }).format(props.activity.duration_hours)
    : t('groups.activities.overview.meta.no_duration'))

const links = computed(() => {
    const params = { group: props.group.slug, activity: props.activity.id, secretKey: props.secretKey || undefined }
    return {
        overview: route('groups.activities.overview', params),
        calendar: route('groups.activities.calendar', params),
        apply: route('groups.activities.application', params),
        manage: route('groups.dashboard.activities.show', { group: props.group.slug, activity: props.activity.id }),
    }
})
const title = computed(() => props.activity.title || localizedValue(props.activity.activity_type.draft_name, locale.value) || t('calendar.run_fallback'))
const progressPoint = computed(() => localizedValue(props.activity.target_prog_point_label, locale.value) || props.activity.target_prog_point_key)
const previewing = computed(() => props.previewingRun || props.previewState !== 'live')
const viewer = computed(() => props.previewState === 'live' ? props.viewerParticipation
    : previewRunApplication(props.previewState, props.activity, links.value.apply))
const application = computed(() => viewer.value?.application)
const placement = computed(() => viewer.value?.placement ?? props.selfAssignedSlot)
const assignedSlot = computed(() => viewer.value?.placement
    ? props.activity.slots.find(slot => slot.id === viewer.value?.placement?.slot_id)
    : props.selfAssignedSlot)
const hasParticipation = computed(() => Boolean(application.value || placement.value))
const archived = computed(() => isArchivedActivityStatus(props.activity.status))
const showViewApplication = computed(() => !archived.value && application.value?.status !== 'declined' && Boolean(application.value?.view_url))
const applicationsOpen = computed(() => props.activity.needs_application && canAcceptActivityApplications(props.activity.status)
    && (!start.value || start.value.getTime() > now.value))
const canApply = computed(() => applicationsOpen.value && (props.previewState !== 'live' || props.permissions.can_apply || props.permissions.can_apply_as_guest)
    && (!application.value || application.value.status === 'withdrawn') && !placement.value)
const loginRequired = computed(() => props.previewState === 'live' && props.permissions.requires_login && !archived.value && (!props.activity.needs_application || applicationsOpen.value))
const showApplicationCount = computed(() => !hasParticipation.value && props.activity.needs_application && (canApply.value || loginRequired.value))
const needsCharacter = computed(() => !props.activity.needs_application && props.permissions.can_self_assign && !archived.value
    && !props.hasSelfAssignmentCharacters && !placement.value)
const chooseSlot = computed(() => !props.activity.needs_application && !archived.value && props.permissions.can_self_assign
    && props.hasSelfAssignmentCharacters && !placement.value && props.activity.slots.some(slot => !slot.assigned_character_id && !slot.is_fill_in))

const placementCharacter = computed(() => assignedSlot.value?.assigned_character)
const characterName = computed(() => placementCharacter.value?.name || viewer.value?.placement?.character_name || application.value?.character_name)
const avatarUrl = computed(() => placementCharacter.value ? placementCharacter.value.avatar_url : application.value?.avatar_url)
const characterLocation = computed(() => {
    const character = placementCharacter.value ?? application.value
    return [character?.world, character?.datacenter].filter(Boolean).join(' · ')
})
const displayStatus = computed(() => {
    if (props.activity.status === 'cancelled') return 'cancelled'
    if (props.previewState !== 'live') return props.previewState
    if (placement.value) {
        const attendance = assignedSlot.value?.attendance_status
        if (attendance === 'late') return 'late'
        if (placement.value.slot_kind === 'fill_in') return 'filled_in'
        if (attendance === 'checked_in') return 'checked_in'
        return placement.value.slot_kind === 'bench' ? 'on_bench' : 'assigned'
    }
    if (application.value?.marked_missing) return 'missing'
    return application.value?.status
})
const badge = computed(() => {
    const status = displayStatus.value
    if (status === 'approved' || (status === 'on_bench' && !placement.value)) {
        return { color: 'neutral' as const, icon: 'i-lucide-user-round-minus', label: t('groups.activities.overview.participation.no_placement') }
    }
    const meta = getRunParticipationMeta(status)
    if (!meta) return null
    const label = status === 'pending' ? t('groups.activities.application.confirmation.statuses.pending') : t(`runs.participation.statuses.${status}`)
    return { color: meta.color, icon: meta.icon, label }
})
</script>

<template>
    <section class="max-lg:@container min-w-0 rounded-2xl border border-elevation-3 bg-elevation-1 p-4 shadow-md sm:p-5 lg:p-6 dark:border-elevation-6 dark:bg-elevation-3 dark:shadow-none dark:[--ui-primary:var(--color-brand-400)]">
        <div class="schedule" :class="{ 'schedule--personal': hasParticipation }">
            <div class="flex min-w-0 items-start gap-3">
                <UIcon name="i-lucide-calendar-days" class="mt-0.5 size-5 shrink-0 text-muted" />
                <div class="min-w-0">
                    <time v-if="schedule" :datetime="activity.starts_at ?? undefined" :title="relativeStart" class="block font-bold leading-tight text-highlighted sm:text-lg">
                        {{ schedule.date }}
                    </time>
                    <span v-else class="font-semibold text-highlighted">{{ t('groups.activities.cards.no_time') }}</span>
                    <p v-if="schedule" class="mt-1 text-sm leading-snug text-toned">
                        {{ schedule.primary }} / {{ schedule.secondary }} <span class="wrap-anywhere text-muted">({{ localZone }})</span>
                    </p>
                </div>
            </div>
            <div class="schedule-duration flex items-center gap-3">
                <UIcon name="i-lucide-clock-3" class="size-5 shrink-0 text-muted" />
                <span class="text-sm text-toned">{{ duration }}</span>
            </div>
        </div>

        <div class="mt-3 space-y-3">
            <p v-if="showApplicationCount" class="flex items-center gap-3 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-users-round" class="size-5 shrink-0 text-muted" />
                {{ t('groups.activities.overview.participation.pending_count', activity.pending_application_count) }}
            </p>
            <dl class="flex items-center gap-2 text-sm">
                <dt class="flex items-center gap-3 text-muted">
                    <UIcon name="i-lucide-database" class="size-5 shrink-0" />
                    {{ t('groups.activities.create.summary.datacenter') }}
                </dt>
                <dd class="font-medium text-toned">{{ activity.datacenter || '—' }}</dd>
            </dl>
            <p v-if="showApplicationCount && activity.allow_guest_applications" class="flex items-center gap-3 text-sm text-toned">
                <UIcon name="i-lucide-circle-check" class="size-5 shrink-0 text-muted" />
                {{ t('groups.activities.overview.participation.guest_welcome') }}
            </p>
        </div>

        <div v-if="hasParticipation" class="mt-4 space-y-3 border-t border-default/50 pt-4">
            <h2 class="font-semibold text-highlighted">
                {{ t(placement ? 'groups.activities.overview.participation.your_slot' : 'groups.activities.overview.participation.your_application') }}
            </h2>
            <div class="flex flex-wrap items-center gap-3">
                <UUser
                    v-if="characterName"
                    size="lg"
                    class="min-w-0 flex-1"
                    :avatar="{ src: avatarUrl || undefined, alt: '', class: 'rounded-lg' }"
                    :name="characterName"
                    :description="characterLocation || undefined"
                    :ui="{ name: 'font-semibold wrap-anywhere text-highlighted', description: 'text-sm text-muted' }"
                />
                <UBadge v-if="badge" v-bind="badge" variant="subtle" class="rounded-lg" />
            </div>
            <RunOverviewPlacementDetails v-if="assignedSlot" :slot="assignedSlot" />
            <p v-if="selfAssignedSlot?.assigned_character && permissions.can_self_assign && !archived" class="text-sm text-muted">
                {{ t('groups.activities.overview.self_signup.alerts.assigned_description', { character: selfAssignedSlot.assigned_character.name }) }}
            </p>
        </div>
        <p v-else-if="activity.needs_application && !showApplicationCount" class="mt-4 flex items-center gap-3 text-sm text-muted">
            <UIcon name="i-lucide-lock-keyhole" class="size-5 shrink-0 text-muted" />
            {{ t('groups.activities.application.form.closed_title') }}
        </p>
        <UAlert v-if="needsCharacter" class="mt-4 rounded-lg" color="warning" variant="soft" icon="i-lucide-user-round"
            :title="t('groups.activities.overview.self_signup.alerts.no_characters_title')"
            :description="t('groups.activities.overview.self_signup.alerts.no_characters_description')" />

        <div v-if="showViewApplication || canApply || loginRequired || chooseSlot" class="mt-4 grid gap-2">
            <UButton v-if="showViewApplication" :to="previewing ? undefined : application?.view_url" :aria-disabled="previewing || undefined" color="primary" block size="lg" class="min-h-11 rounded-lg"
                :label="t('groups.activities.overview.participation.view_application')" />
            <UButton v-else-if="canApply" :to="previewing ? undefined : links.apply" :aria-disabled="previewing || undefined" color="primary" block size="lg" icon="i-lucide-user-round-plus" class="min-h-11 rounded-lg" :label="t('groups.activities.overview.open_application')" />
            <UButton v-else-if="loginRequired" :to="route('login')" color="primary" block size="lg" icon="i-lucide-log-in" class="min-h-11 rounded-lg" :label="t('auth.login')" />
            <UButton v-else-if="chooseSlot" block color="primary" size="lg" class="min-h-11 rounded-lg"
                :label="t('groups.activities.overview.participation.choose_slot')"
                @click="emit('show-roster')" />
        </div>
        <footer class="mt-4 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 empty:hidden [&_:is(a,button)]:rounded-lg">
            <AddToCalendarMenu :title="title" :group-name="group.name" :starts-at="activity.starts_at" :duration-hours="activity.duration_hours"
                :status="activity.status" :overview-url="links.overview" :ics-url="links.calendar" :enabled="group.features.calendar_sync_enabled"
                :progress-point="progressPoint" :notes="activity.notes" size="sm" variant="ghost" />
            <template v-if="permissions.can_manage">
                <span aria-hidden="true" class="h-4 w-px shrink-0 bg-elevation-6 first:hidden dark:bg-elevation-8" />
                <UButton :to="links.manage" size="sm" variant="ghost" color="neutral" icon="i-lucide-settings-2" :label="t('groups.activities.overview.go_to_management')" />
            </template>
        </footer>
    </section>
</template>

<style scoped>
.schedule { display: grid; gap: 0.875rem; }
@container (min-width: 26rem) {
    .schedule--personal { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
    .schedule--personal .schedule-duration { border-inline-start: 1px solid var(--ui-border); padding-inline-start: 1rem; }
}
@media (min-width: 64rem) {
    .schedule--personal { display: flex; flex-wrap: wrap; align-items: center; }
}
</style>

