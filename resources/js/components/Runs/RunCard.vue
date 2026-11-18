<script lang="ts" setup>
import { Link } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { useRunBookmark } from '@/composables/useRunBookmark'
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
    eagerImage?: boolean
}>(), { type: 'default', carousel: false, eagerImage: false })
const emit = defineEmits<{ ready: []; 'bookmark-changed': [saved: boolean] }>()
const { t, locale } = useI18n()
const { isDark } = useAppearance()
const usesDateLayout = computed(() => props.type !== 'default')
const { saved, visible: showBookmark, pending: savingBookmark, toggle } = useRunBookmark(() => props.run)
const bookmarkLabel = computed(() => t(saved.value
    ? 'runs.discovery.results.placeholder_item.actions.unsave_run'
    : 'runs.discovery.results.placeholder_item.actions.save_run'))
async function toggleBookmark() {
    const changed = await toggle()
    if (changed !== undefined) emit('bookmark-changed', changed)
}
const { formatDateTime, formatDateTimeParts, displayTimeZoneLabel } = useDateTimeFormat()
const badgeVariant = computed(() => isDark.value ? 'outline' : 'solid')
const status = computed(() => getActivityStatusMeta(props.run.status))
const participation = computed(() => getRunParticipationMeta(props.run.participation_status))
const activityName = computed(() => localizedValue(props.run.activity_type.name, locale.value)
    || t('groups.activities.cards.unknown_type'))
const title = computed(() => props.run.title || activityName.value)
const imageUrls = computed(() => {
    if (props.run.image_urls) return props.run.image_urls
    const { banner_image_url, small_image_url } = props.run.activity_type
    return [...new Set([banner_image_url, small_image_url].filter(Boolean))]
})
const imageIndex = ref(0)
const imageUrl = computed(() => imageUrls.value[imageIndex.value])
const heroImage = ref<HTMLImageElement | null>(null)
const notifyIfReady = () => {
    if (!imageUrl.value || (heroImage.value?.complete && heroImage.value.naturalWidth > 0)) emit('ready')
}
watch(imageUrls, () => { imageIndex.value = 0 })
watch(imageUrl, notifyIfReady, { flush: 'post' })
onMounted(notifyIfReady)
const startsAtLabel = computed(() => formatDateTime(props.run.starts_at, {
    dateStyle: 'full', timeStyle: 'short',
}))
const dateBadgeDay = computed(() => formatDateTimeParts(props.run.starts_at, { day: 'numeric' })
    .find(part => part.type === 'day')?.value ?? '—')
const dateBadgeMonth = computed(() => formatDateTime(props.run.starts_at, { month: 'short' }, ''))
const hostName = computed(() => props.type === 'finder'
    ? props.run.group.name
    : props.run.host_name || t('groups.activities.cards.no_organizer'))
const targetProgress = computed(() => localizedValue(props.run.target_prog_point_label, locale.value)
    || props.run.target_prog_point_key)
const applicantAvatars = computed(() => (props.run.applicant_avatars ?? []).slice(0, 3))
</script>

<template>
    <article @dragstart.prevent
        :class="[
            usesDateLayout ? 'w-full min-w-0 max-w-md' : 'w-72 max-w-full',
            carousel ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        ]"
        class="group/run-card relative font-roboto flex flex-col items-stretch justify-start p-2 gap-1 rounded-2xl transition-all duration-100 hover:scale-105">
        <Link :href="run.href" :draggable="false"
            class="flex min-w-0 flex-col gap-1 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
		<div class="relative w-full h-36 overflow-hidden rounded-2xl bg-elevation-3 dark:bg-elevation-5 ">
			<img v-if="imageUrl" :key="imageUrl" ref="heroImage" :src="imageUrl" alt="" :loading="eagerImage ? 'eager' : 'lazy'" decoding="async"
                class="w-full h-full object-cover" @load="emit('ready')" @error="imageIndex++">
            <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2">
                <time v-if="usesDateLayout" :datetime="run.starts_at ?? undefined" :aria-label="`${startsAtLabel} (${displayTimeZoneLabel})`" :title="displayTimeZoneLabel"
                    class="flex h-14 min-w-12 shrink-0 flex-col items-center justify-center rounded-md bg-elevation-1/95 px-2 text-highlighted ring-1 ring-default dark:bg-elevation-3/95">
                    <span aria-hidden="true" class="text-xl leading-none font-semibold tabular-nums">{{ dateBadgeDay }}</span>
                    <span aria-hidden="true" class="mt-1 text-xs leading-none font-semibold">{{ dateBadgeMonth }}</span>
                </time>
                <UBadge v-else :label="t(`groups.activities.statuses.${run.status}`)" :icon="status.icon"
                    :color="status.color" :variant="badgeVariant" size="md" class="rounded-md dark:bg-elevation-3/95" />
            </div>
            <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-neutral-950/95 via-neutral-950/70 to-transparent px-3 pb-2.5"
                :class="type === 'finder' ? 'flex items-end gap-3 pt-2' : 'pt-8'">
                <span v-if="type === 'finder'" role="img" :aria-label="run.group.name" :title="run.group.name" class="flex shrink-0">
                    <UAvatar :src="run.group.profile_picture_url || undefined" alt="" icon="i-lucide-users" size="3xl"
                        :loading="eagerImage ? 'eager' : 'lazy'" decoding="async" :draggable="false"
                        class="bg-elevation-1 shadow-md shadow-black/35 dark:bg-elevation-5" />
                </span>
                <p class="line-clamp-2 text-xs uppercase leading-snug text-inverted dark:text-muted" :title="activityName"
                    :class="type === 'finder' ? 'min-w-0 flex-1 text-right' : 'text-center'">{{ activityName }}</p>
            </div>
		</div>
		<p class="text-md break-words text-default  group-hover/run-card:text-brand-700 dark:group-hover/run-card:text-brand">{{ title }}</p>
		<time :datetime="run.starts_at ?? undefined" :title="displayTimeZoneLabel" class="text-sm text-muted">{{ startsAtLabel }}</time>
        <RunCardDetails v-if="usesDateLayout" :host-name="hostName" :group-host="type === 'finder'" :duration-hours="run.duration_hours"
            :target-progress="targetProgress" />
        </Link>
        <div class="pointer-events-none absolute end-4 top-4 flex flex-col items-end gap-1.5">
            <UBadge v-if="participation" :label="t(`runs.participation.statuses.${run.participation_status}`)"
                :color="participation.color" :icon="participation.icon" :variant="badgeVariant" size="md"
                class="rounded-md dark:bg-elevation-3/95" />
            <UBadge v-else :label="t(`groups.dashboard.upcoming_runs.${run.is_public ? 'public' : 'private'}`)"
                color="neutral" variant="soft" size="md" class="rounded-md bg-elevation-1 text-default dark:bg-elevation-3" />
            <UButton v-if="showBookmark" type="button" color="neutral" variant="link"
                :icon="saved ? 'i-lucide-bookmark-check' : 'i-lucide-bookmark'"
                :aria-label="`${bookmarkLabel} · ${title}`" :title="bookmarkLabel" :aria-pressed="saved"
                :loading="savingBookmark" :disabled="savingBookmark"
                class="pointer-events-auto size-9 justify-center rounded-md focus-visible:ring-white"
                :ui="{ leadingIcon: 'size-5 drop-shadow-[0_1px_2px_rgba(0,0,0,0.95)]' }"
                :class="saved ? 'text-brand-300 hover:text-brand-200 active:text-brand-200' : 'text-white hover:text-brand-200 active:text-brand-200'"
                @click.stop="toggleBookmark" />
        </div>
        <component :is="usesDateLayout ? Link : 'div'" :href="usesDateLayout ? run.href : undefined" :draggable="false"
            class="flex flex-row items-center justify-between gap-2 rounded-md text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            <UBadge v-if="usesDateLayout" :label="t(`groups.activities.statuses.${run.status}`)" :icon="status.icon"
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
