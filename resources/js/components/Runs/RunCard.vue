<script lang="ts" setup>
import { Link } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { getActivityStatusMeta } from '@/utils/activityStatusMeta'
import { getRunParticipationMeta } from '@/utils/runParticipationMeta'
import { localizedValue } from '@/utils/localizedValue'
import type { RunCardData, RunCardType } from '@/Types/RunCards'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import RunCardDetails from '@/components/Runs/RunCardDetails.vue'

const props = withDefaults(defineProps<{
    run: RunCardData
    type?: RunCardType
    carousel?: boolean
}>(), { type: 'default', carousel: false })
const { t, locale } = useI18n()
const { isDark } = useAppearance()
const { formatDateTime, formatDateTimeParts, displayTimeZoneLabel } = useDateTimeFormat()
const badgeVariant = computed(() => isDark.value ? 'outline' : 'solid')
const status = computed(() => getActivityStatusMeta(props.run.status))
const participation = computed(() => getRunParticipationMeta(props.run.participation_status))
const activityName = computed(() => localizedValue(props.run.activity_type.name, locale.value)
    || t('groups.activities.cards.unknown_type'))
const title = computed(() => props.run.title || activityName.value)
const imageUrl = computed(() => props.run.activity_type.banner_image_url || props.run.activity_type.small_image_url)
const imageFailed = ref(false)
watch(imageUrl, () => { imageFailed.value = false })
const startsAtLabel = computed(() => formatDateTime(props.run.starts_at, {
    dateStyle: 'full', timeStyle: 'short',
}))
const dateBadgeDay = computed(() => formatDateTimeParts(props.run.starts_at, { day: 'numeric' })
    .find(part => part.type === 'day')?.value ?? '—')
const dateBadgeMonth = computed(() => formatDateTime(props.run.starts_at, { month: 'short' }, ''))
const hostName = computed(() => props.run.host_name || t('groups.activities.cards.no_organizer'))
const targetProgress = computed(() => localizedValue(props.run.target_prog_point_label, locale.value)
    || props.run.target_prog_point_key)
const applicantAvatars = computed(() => (props.run.applicant_avatars ?? []).slice(0, 3))
</script>

<template>
    <article @dragstart.prevent
        :class="[
            type === 'date' ? 'w-full min-w-0 max-w-md' : 'w-72 max-w-full',
            carousel ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        ]"
        class="group/run-card font-roboto flex flex-col items-stretch justify-start p-2 gap-1 rounded-2xl transition-all duration-100 hover:scale-105">
        <Link :href="run.href" :draggable="false"
            class="flex min-w-0 flex-col gap-1 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
		<div class="relative w-full h-36 overflow-hidden rounded-2xl bg-elevation-3 dark:bg-elevation-5 ">
			<img v-if="imageUrl && !imageFailed" :src="imageUrl" alt="" loading="lazy" decoding="async"
                class="w-full h-full object-cover" @error="imageFailed = true">
            <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2">
                <time v-if="type === 'date'" :datetime="run.starts_at ?? undefined" :aria-label="`${startsAtLabel} (${displayTimeZoneLabel})`" :title="displayTimeZoneLabel"
                    class="flex h-14 min-w-12 shrink-0 flex-col items-center justify-center rounded-md bg-elevation-1/95 px-2 text-highlighted ring-1 ring-default dark:bg-elevation-3/95">
                    <span aria-hidden="true" class="text-xl leading-none font-semibold tabular-nums">{{ dateBadgeDay }}</span>
                    <span aria-hidden="true" class="mt-1 text-xs leading-none font-semibold">{{ dateBadgeMonth }}</span>
                </time>
                <UBadge v-else :label="t(`groups.activities.statuses.${run.status}`)" :icon="status.icon"
                    :color="status.color" :variant="badgeVariant" size="md" class="rounded-md dark:bg-elevation-3/95" />
                <UBadge v-if="participation" :label="t(`runs.participation.statuses.${run.participation_status}`)"
                    :color="participation.color" :icon="participation.icon" :variant="badgeVariant" size="md"
                    class="rounded-md dark:bg-elevation-3/95" />
                <UBadge v-else :label="t(`groups.dashboard.upcoming_runs.${run.is_public ? 'public' : 'private'}`)"
                    color="neutral" variant="soft" size="md" class="rounded-md bg-elevation-1 text-default dark:bg-elevation-3" />
            </div>
            <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-neutral-950/95 via-neutral-950/70 to-transparent px-3 pb-2.5 pt-8">
                <p class="text-center line-clamp-2 text-xs uppercase leading-snug text-inverted dark:text-muted" :title="activityName">{{ activityName }}</p>
            </div>
		</div>
		<p class="text-md break-words text-default  group-hover/run-card:text-brand-700 dark:group-hover/run-card:text-brand">{{ title }}</p>
		<time :datetime="run.starts_at ?? undefined" :title="displayTimeZoneLabel" class="text-sm text-muted">{{ startsAtLabel }}</time>
        <RunCardDetails v-if="type === 'date'" :host-name="hostName" :duration-hours="run.duration_hours"
            :target-progress="targetProgress" />
        </Link>
        <component :is="type === 'date' ? Link : 'div'" :href="type === 'date' ? run.href : undefined" :draggable="false"
            class="flex flex-row items-center justify-between gap-2 rounded-md text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            <UBadge v-if="type === 'date'" :label="t(`groups.activities.statuses.${run.status}`)" :icon="status.icon"
                :color="status.color" :variant="badgeVariant" size="md" class="shrink-0 rounded-md" />
            <component v-else :is="run.group.dashboard_href ? Link : 'div'" :href="run.group.dashboard_href || undefined"
                :draggable="false" class="flex min-w-0 items-center gap-1.5 rounded-md" :title="run.group.name"
                :class="run.group.dashboard_href ? 'cursor-pointer hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand' : 'cursor-default'">
                <UAvatar :src="run.group.profile_picture_url ?? undefined" alt="" icon="i-lucide-users" size="2xs"
                    class="shrink-0" :ui="{ root: 'bg-elevation-3 text-muted dark:bg-elevation-5' }" />
                <p class="min-w-0 truncate text-sm">{{ run.group.name }}</p>
            </component>
            <component :is="type === 'default' ? Link : 'div'" :href="type === 'default' ? run.href : undefined" :draggable="false"
                class="flex shrink-0 items-center gap-1.5 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                <UAvatarGroup v-if="applicantAvatars.length" size="2xs" :max="3" aria-hidden="true">
                    <UAvatar v-for="(avatar, index) in applicantAvatars" :key="index" :src="avatar ?? undefined"
                        alt="" icon="i-lucide-user-round" :ui="{ root: 'bg-elevation-3 text-muted dark:bg-elevation-5' }" />
                </UAvatarGroup>
                <span class="text-xs whitespace-nowrap">{{ t('dashboard.recommended_runs.applicants', run.application_count) }}</span>
            </component>
        </component>
    </article>
</template>
