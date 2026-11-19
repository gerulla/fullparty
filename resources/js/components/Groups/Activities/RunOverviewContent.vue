<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import ActivityCompletionSummaryPanel from '@/components/Groups/Activities/ActivityCompletionSummaryPanel.vue'
import RunOverviewParticipationCard from '@/components/Groups/Activities/RunOverviewParticipationCard.vue'
import RunOverviewPreviewControls from '@/components/Groups/Activities/RunOverviewPreviewControls.vue'
import RunOverviewPoster from '@/components/Groups/Activities/RunOverviewPoster.vue'
import SeoHead from '@/components/Shared/SeoHead.vue'
import type { ActivityOverviewPermissions, AttendeeActivity, RunOverviewGroup, RunOverviewViewerParticipation } from '@/Types/ActivityAttendee'
import type { ActivitySlot } from '@/Types/ActivityRoster'
import type { ActivityStatus } from '@/Types/ActivityCore'
import type { RunApplicationPreviewState } from '@/Types/RunParticipation'
import { buildActivityCompletionSummary } from '@/utils/buildActivityCompletionSummary'
import { previewRunCompletion } from '@/utils/runOverviewCompletionPreview'
import { getRunStatusIcon } from '@/utils/runStatusIcons'
import { localizedValue } from '@/utils/localizedValue'
import { runOrigin } from '@/bootstrap/runOverviewHistory'

const props = defineProps<{
    group: RunOverviewGroup
    activity: AttendeeActivity
    permissions: ActivityOverviewPermissions
    secretKey?: string | null
    viewerParticipation?: RunOverviewViewerParticipation | null
    selfAssignedSlot?: ActivitySlot | null
    hasSelfAssignmentCharacters?: boolean
}>()

const { t, locale } = useI18n()
const page = usePage()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const runPreview = ref<ActivityStatus | 'live'>('live')
const applicationPreview = ref<RunApplicationPreviewState>('live')
const previewingRun = computed(() => canPreview.value && runPreview.value !== 'live')
const displayedActivity = computed(() => previewingRun.value ? { ...props.activity, status: runPreview.value } : props.activity)
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'))
const title = computed(() => props.activity.title
    || localizedValue(props.activity.activity_type.draft_name, locale.value, fallbackLocale.value)
    || props.activity.activity_type.slug
    || t('groups.activities.cards.unknown_type'))
const description = computed(() => props.activity.description
    || t('meta.seo.activities.overview_description', { title: title.value, group: props.group.name }))
const image = computed(() => props.activity.banner_image_url || props.activity.small_image_url)
const structuredData = computed(() => ({
    '@context': 'https://schema.org',
    '@type': 'Event',
    name: title.value,
    description: description.value,
    startDate: props.activity.starts_at || undefined,
    eventAttendanceMode: 'https://schema.org/OnlineEventAttendanceMode',
    eventStatus: props.activity.status === 'cancelled' ? 'https://schema.org/EventCancelled'
        : props.activity.status === 'complete' ? 'https://schema.org/EventCompleted' : 'https://schema.org/EventScheduled',
    organizer: { '@type': 'Organization', name: props.group.name },
    image: image.value || undefined,
    url: route('groups.activities.overview', { group: props.group.slug, activity: props.activity.id, secretKey: props.secretKey || undefined }),
}))
const runPath = computed(() => route('groups.activities.overview', { group: props.group.slug, activity: props.activity.id }, false))
const fallbackUrl = computed(() => props.group.permissions?.can_view_members
    ? route('groups.dashboard.activities.index', { group: props.group.slug }, false)
    : route('dashboard.runs.index', {}, false))
const backUrl = computed(() => runOrigin(runPath.value)?.href ?? fallbackUrl.value)

function goBack(event: MouseEvent) {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    const origin = runOrigin(runPath.value)
    if (origin?.steps && origin.steps < window.history.length) {
        window.history.go(-origin.steps)
    } else {
        router.visit(origin?.href ?? fallbackUrl.value)
    }
}
const completedProgression = computed(() => buildActivityCompletionSummary({
    activity: canPreview.value && runPreview.value === 'complete'
        ? previewRunCompletion(props.activity, t('groups.activities.overview.complete_alert.preview_note'))
        : displayedActivity.value,
    locale: locale.value, fallbackLocale: fallbackLocale.value, t,
}))
const roster = ref<HTMLElement | null>(null)

function showRoster() {
    roster.value?.focus({ preventScroll: true })
    roster.value?.scrollIntoView({ block: 'start', behavior: 'instant' })
}
</script>

<template>
    <SeoHead :title="title" :description="description" :image="image" og-type="event" :structured-data="structuredData" />
    <RunOverviewPreviewControls v-if="canPreview" v-model:run="runPreview" v-model:application="applicationPreview"
        :actual-status="activity.status" :show-application="activity.needs_application" />
    <RunOverviewPoster :activity="displayedActivity" :group="group" :back-url="backUrl" @back="goBack">
        <template #participation>
            <RunOverviewParticipationCard
                :activity="displayedActivity"
                :preview-state="canPreview && activity.needs_application ? applicationPreview : 'live'"
                :previewing-run="previewingRun"
                :group="group"
                :permissions="permissions"
                :secret-key="secretKey"
                :viewer-participation="viewerParticipation"
                :self-assigned-slot="selfAssignedSlot"
                :has-self-assignment-characters="hasSelfAssignmentCharacters"
                @show-roster="showRoster"
            />
        </template>
    </RunOverviewPoster>

    <div class="mx-auto flex w-full max-w-[100rem] flex-col gap-5 px-4 pt-6 pb-10 sm:px-6 xl:px-8 xl:pt-8">
        <UAlert
            v-if="displayedActivity.status === 'cancelled'"
            class="rounded-xl whitespace-pre-wrap"
            color="error"
            variant="soft"
            :icon="getRunStatusIcon('cancelled')"
            :title="t('groups.activities.overview.cancelled_alert.title')"
            :description="activity.cancellation_reason || t('groups.activities.overview.cancelled_alert.description')"
        />
        <UAlert v-else-if="displayedActivity.status === 'complete'"
            class="rounded-xl" color="success" variant="soft" :icon="getRunStatusIcon('complete')"
            :ui="{ title: 'text-green-800 dark:text-green-300', description: 'text-green-900 dark:text-green-200', icon: 'text-green-700 dark:text-green-400' }"
            :title="t('groups.activities.overview.complete_alert.title')"
            :description="t(completedProgression ? 'groups.activities.overview.complete_alert.description' : 'groups.activities.overview.complete_alert.no_results')" />
        <ActivityCompletionSummaryPanel v-if="completedProgression" :completed-progression="completedProgression" />
        <div id="run-overview-roster" ref="roster" tabindex="-1" aria-labelledby="run-overview-roster-title" class="min-w-0 scroll-mt-[var(--ui-header-height)] rounded-xl">
            <slot name="roster" :activity="displayedActivity" :previewingRun="previewingRun" />
        </div>
    </div>
</template>
