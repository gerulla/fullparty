<script lang="ts" setup>
import { Link } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { getActivityStatusMeta } from '@/utils/activityStatusMeta'
import { localizedValue } from '@/utils/localizedValue'
import type { V2HomeRunCard } from '../../Types/V2Home'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const props = defineProps<{ run: V2HomeRunCard }>()
const { t, locale } = useI18n()
const { isDark } = useAppearance()
const { formatDateTime, displayTimeZoneLabel } = useDateTimeFormat()
const badgeVariant = computed(() => isDark.value ? 'outline' : 'solid')
const status = computed(() => getActivityStatusMeta(props.run.status))
const activityName = computed(() => localizedValue(props.run.activity_type.name, locale.value)
    || t('groups.activities.cards.unknown_type'))
const title = computed(() => props.run.title || activityName.value)
const imageUrl = computed(() => props.run.activity_type.banner_image_url || props.run.activity_type.small_image_url)
const imageFailed = ref(false)
watch(imageUrl, () => { imageFailed.value = false })
const startsAtLabel = computed(() => formatDateTime(new Date(props.run.starts_at), {
    dateStyle: 'full', timeStyle: 'short',
}))
const applicantAvatars = computed(() => props.run.applicant_avatars.slice(0, 3))
</script>

<template>
    <Link :href="run.href" :draggable="false" @dragstart.prevent
        class="group/run-card font-roboto w-72 max-w-full flex flex-col items-stretch justify-start p-2 gap-1 rounded-2xl cursor-grab active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand transition-all duration-100 hover:scale-105">
		<div class="relative w-full h-36 overflow-hidden rounded-2xl bg-elevation-3 dark:bg-elevation-5 ">
			<img v-if="imageUrl && !imageFailed" :src="imageUrl" alt="" loading="lazy" decoding="async"
                class="w-full h-full object-cover" @error="imageFailed = true">
            <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2">
                <UBadge :label="t(`groups.activities.statuses.${run.status}`)" :icon="status.icon"
                    :color="status.color" :variant="badgeVariant" size="md" class="rounded-md dark:bg-elevation-3/95" />
                <UBadge :label="t(`groups.dashboard.upcoming_runs.${run.is_public ? 'public' : 'private'}`)"
                    color="neutral" variant="soft" size="md" class="rounded-md bg-elevation-1 text-default dark:bg-elevation-3" />
            </div>
            <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-neutral-950/95 via-neutral-950/70 to-transparent px-3 pb-2.5 pt-8">
                <p class="text-center line-clamp-2 text-xs uppercase leading-snug text-inverted dark:text-muted" :title="activityName">{{ activityName }}</p>
            </div>
		</div>
		<p class="text-md break-words text-default  group-hover/run-card:text-brand-700 dark:group-hover/run-card:text-brand">{{ title }}</p>
		<time :datetime="run.starts_at" :title="displayTimeZoneLabel" class="text-sm text-muted">{{ startsAtLabel }}</time>
		<div class="flex flex-row items-center justify-between gap-2 text-muted">
            <div class="flex min-w-0 items-center gap-1.5" :title="run.group.name">
                <UAvatar :src="run.group.profile_picture_url ?? undefined" alt="" icon="i-lucide-users" size="2xs"
                    class="shrink-0" :ui="{ root: 'bg-elevation-3 text-muted dark:bg-elevation-5' }" />
                <p class="min-w-0 truncate text-sm">{{ run.group.name }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <UAvatarGroup v-if="applicantAvatars.length" size="2xs" :max="3" aria-hidden="true">
                    <UAvatar v-for="(avatar, index) in applicantAvatars" :key="index" :src="avatar ?? undefined"
                        alt="" icon="i-lucide-user-round" :ui="{ root: 'bg-elevation-3 text-muted dark:bg-elevation-5' }" />
                </UAvatarGroup>
                <span class="text-xs whitespace-nowrap">{{ t('dashboard.recommended_runs.applicants', run.application_count) }}</span>
            </div>
		</div>
	</Link>
</template>
