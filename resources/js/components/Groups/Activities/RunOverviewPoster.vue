<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'
import type { AttendeeActivity, RunOverviewGroup } from '@/Types/ActivityAttendee'
import { getActivityStatusMeta } from '@/utils/activityStatusMeta'
import { localizedValue } from '@/utils/localizedValue'

const props = defineProps<{
    activity: AttendeeActivity
    group: RunOverviewGroup
    backUrl: string
}>()
const emit = defineEmits<{ back: [event: MouseEvent] }>()

const { t, te, locale } = useI18n()
const { isDark } = useAppearance()
const showFactsByDefault = useBreakpoints(breakpointsTailwind).greaterOrEqual('md')
const page = usePage<{ locale?: { fallback?: string } }>()
const fallbackLocale = computed(() => page.props.locale?.fallback ?? 'en')
const activityName = computed(() =>
    localizedValue(props.activity.activity_type.draft_name, locale.value, fallbackLocale.value)
    || props.activity.activity_type.slug
    || t('groups.activities.cards.unknown_type'),
)
const title = computed(() => props.activity.title?.trim() || activityName.value)
const statusMeta = computed(() => getActivityStatusMeta(props.activity.status))
const organizer = computed(() => props.activity.organized_by_character ?? props.activity.organized_by)
const notes = computed(() => props.activity.notes?.trim())
const description = computed(() => props.activity.description?.trim())

const images = computed(() => {
    const candidates = [props.activity.banner_image_url, props.activity.small_image_url]
    return [...new Set(candidates.filter((url): url is string => Boolean(url)))]
})
const imageIndex = ref(0)
watch(images, () => { imageIndex.value = 0 })

function activityLabel(category: string, value: string | null) {
    if (!value) return '—'
    const key = `groups.activities.${category}.${value}`
    return te(key) || te(key, fallbackLocale.value) ? t(key) : value
}

const facts = computed(() => {
    const run = props.activity
    const target = localizedValue(run.target_prog_point_label, locale.value, fallbackLocale.value)
        || run.target_prog_point_key || t('groups.activities.overview.details.no_target_prog_point')
    return [
        { key: 'target_prog_point', icon: 'i-lucide-goal', value: target },
        { key: 'difficulty', icon: 'i-lucide-sparkles', value: activityLabel('difficulties', run.difficulty) },
        { key: 'run_style', icon: 'i-lucide-swords', value: activityLabel('run_styles', run.run_style) },
        { key: 'intensity', icon: 'i-lucide-activity', value: activityLabel('intensities', run.intensity) },
        { key: 'beginner_friendly', icon: 'i-lucide-sprout', value: t(run.beginner_friendly ? 'general.yes' : 'general.no') },
        { key: 'min_item_level', icon: 'i-lucide-layers', value: run.min_item_level ?? t('groups.activities.overview.details.no_min_item_level') },
    ]
})
</script>

<template>
    <article class="run-overview grid min-w-0" aria-labelledby="run-overview-title">
        <header class="run-poster relative isolate col-span-full row-start-1 grid grid-cols-subgrid overflow-hidden bg-neutral-900 text-white">
            <img
                v-if="images[imageIndex]"
                :key="images[imageIndex]"
                :src="images[imageIndex]"
                alt=""
                fetchpriority="high"
                class="absolute inset-0 -z-20 size-full object-cover object-center"
                @error="imageIndex++"
            />
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 bg-linear-to-t from-neutral-950/90 via-neutral-950/35 to-transparent" />
            <div class="col-start-2 flex min-w-0 flex-col items-start py-4 sm:py-5 xl:py-7">
                <a
                    :href="backUrl"
                    :aria-label="t('general.back')"
                    :title="t('general.back')"
                    @click="emit('back', $event)"
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-elevation-1 text-default shadow-sm transition-colors hover:bg-elevation-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current dark:bg-elevation-3 dark:hover:bg-elevation-4"
                >
                    <UIcon name="i-lucide-arrow-left" class="size-5" aria-hidden="true" />
                </a>
                <div class="mt-auto w-full pt-14">
                    <div class="flex flex-wrap items-center gap-2">
                        <UBadge color="neutral" variant="soft" size="md" icon="i-lucide-castle" :label="activityName"
                            class="max-w-full rounded-md bg-elevation-1 whitespace-normal text-default [overflow-wrap:anywhere] dark:bg-elevation-3" />
                        <UBadge
                            :icon="statusMeta.icon"
                            :color="statusMeta.color"
                            :label="activityLabel('statuses', activity.status)"
                            :variant="isDark ? 'outline' : 'solid'"
                            size="md"
                            class="rounded-md dark:bg-elevation-3/95"
                            :class="{ 'text-neutral-950': !isDark && statusMeta.color === 'warning' }"
                        />
                    </div>
                    <div class="mt-2 flex items-center gap-3 sm:mt-3 sm:gap-4">
                        <h1 id="run-overview-title" class="min-w-0 text-[clamp(1.875rem,3.4vw,3.75rem)] leading-[1.08] font-bold tracking-tight text-balance [overflow-wrap:anywhere] drop-shadow-md">
                            {{ title }}
                        </h1>
                        <ReportButton :target="{ type: 'run', id: activity.id, label: title }" :aria-label="t('reports.report')"
                            class="rounded-lg bg-neutral-950/75 text-white ring-white/30 hover:bg-neutral-950 max-sm:size-9 max-sm:justify-center max-sm:gap-0 max-sm:[&_[data-slot=label]]:hidden" />
                    </div>
                </div>
            </div>
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-0 border-t" :class="statusMeta.borderClass" />
        </header>

        <div class="col-start-2 row-start-2 mt-5 flex min-w-0 items-center gap-3 sm:mt-6 sm:gap-4 xl:mt-7">
            <UAvatar
                :src="organizer?.avatar_url ?? undefined"
                :alt="organizer?.name ?? ''"
                :icon="organizer ? undefined : 'i-lucide-user-round'"
                class="size-14 shrink-0 rounded-xl bg-elevation-3 text-lg font-semibold text-toned shadow-sm ring-1 ring-inset ring-default/70 sm:size-18 dark:bg-elevation-5"
                :ui="{ image: 'rounded-[inherit]' }"
            />
            <div class="min-w-0 [overflow-wrap:anywhere]">
                <p class="sr-only text-xs font-medium text-muted md:not-sr-only">{{ t('groups.activities.management.organizer') }}</p>
                <p class="mt-0.5 text-lg leading-tight font-semibold text-highlighted sm:text-xl">
                    {{ organizer?.name || t('groups.activities.cards.no_organizer') }}
                </p>
                <component
                    :is="group.href ? Link : 'span'"
                    :href="group.href ?? undefined"
                    class="mt-1 inline-flex max-w-full items-center gap-2 rounded-lg text-sm font-medium text-toned"
                    :class="{ 'min-h-9 bg-elevation-1 px-2 py-1 shadow-sm hover:bg-elevation-3 hover:text-highlighted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current dark:bg-elevation-3 dark:hover:bg-elevation-4': group.href }"
                >
                    <UAvatar :src="group.profile_picture_url || undefined" icon="i-lucide-users-round" alt="" aria-hidden="true" size="xs" />
                    <span class="min-w-0">{{ group.name }}</span>
                    <UIcon v-if="group.href" name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                </component>
            </div>
        </div>

        <div class="run-participation relative col-start-2 mt-5 min-w-0 self-end">
            <slot name="participation" />
        </div>

        <div v-if="notes || description" class="col-start-2 mt-5 min-w-0 space-y-5 sm:mt-6 sm:space-y-6 lg:row-start-3">
            <section>
                <h2 class="text-base font-semibold text-highlighted">{{ t('groups.activities.overview.host_note') }}</h2>
                <p class="mt-1.5 max-w-prose text-sm leading-relaxed whitespace-pre-wrap text-default [overflow-wrap:anywhere] sm:text-base">{{ notes || description }}</p>
            </section>
            <section v-if="notes && description && description !== notes">
                <h2 class="text-base font-semibold text-highlighted">{{ t('groups.activities.overview.details.description') }}</h2>
                <p class="mt-1.5 max-w-prose text-sm leading-relaxed whitespace-pre-wrap text-default [overflow-wrap:anywhere] sm:text-base">{{ description }}</p>
            </section>
        </div>

        <details class="group/facts col-start-2 mt-5 min-w-0 border-t border-default/70 sm:mt-6 lg:col-end-5 lg:row-start-4" :open="showFactsByDefault">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-sm text-sm font-medium text-toned hover:text-highlighted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current md:hidden [&::-webkit-details-marker]:hidden">
                <span class="group-open/facts:hidden">{{ t('groups.activities.overview.show_details') }}</span>
                <span class="hidden group-open/facts:inline">{{ t('groups.activities.overview.hide_details') }}</span>
                <UIcon name="i-lucide-chevron-down" class="size-4 group-open/facts:rotate-180" aria-hidden="true" />
            </summary>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-5 pt-2 sm:grid-cols-3 sm:gap-x-5 sm:gap-y-6 md:pt-5 xl:grid-cols-[repeat(6,minmax(0,max-content))] xl:justify-between">
                <div v-for="fact in facts" :key="fact.key" class="relative min-w-0 ps-8 [overflow-wrap:anywhere] sm:ps-9">
                    <dt class="text-xs leading-snug text-muted sm:text-sm">
                        <UIcon :name="fact.icon" class="absolute start-0 top-0.5 size-5 text-muted sm:size-6" aria-hidden="true" />
                        {{ t(`groups.activities.create.summary.${fact.key}`) }}
                    </dt>
                    <dd class="mt-0.5 text-sm leading-snug font-semibold text-highlighted sm:text-base">{{ fact.value }}</dd>
                </div>
            </dl>
        </details>
    </article>
</template>

<style scoped>
.run-overview {
    --gutter: 1rem;
    grid-template-columns: var(--gutter) minmax(0, 1fr) var(--gutter);
}

.run-poster {
    min-height: clamp(14rem, 46vw, 21rem);
}

@media (min-width: 40rem) {
    .run-overview { --gutter: 1.5rem; }
}

@media (min-width: 64rem) {
    .run-overview {
        grid-template-columns: var(--gutter) minmax(0, 1fr) 2.5rem fit-content(32rem) var(--gutter);
        grid-template-rows: 1fr auto auto auto;
    }

    .run-participation {
        grid-area: 1 / 4 / 4 / 5;
    }
}

@media (min-width: 80rem) {
    .run-overview { --gutter: max(2rem, calc((100% - 100rem) / 2 + 2rem)); }

    .run-poster {
        min-height: clamp(17rem, 24vh, 24rem);
    }
}

@media (min-width: 96rem) {
    .run-poster {
        min-height: clamp(20rem, 33vh, 29rem);
    }
}
</style>
