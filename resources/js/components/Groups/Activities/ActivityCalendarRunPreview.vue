<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { ActivityIndexItem } from '@/Types/ActivityCore'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { localizedValue } from '@/utils/localizedValue'
import { getActivityStatusMeta } from '@/utils/activityStatusMeta'

const props = defineProps<{
    activity: ActivityIndexItem
    reference: HTMLElement
    open: boolean
    focusContent: boolean
}>()
const emit = defineEmits<{ close: []; keepOpen: []; leave: [] }>()
const { t, te, locale } = useI18n()
const page = usePage()
const { formatDateTime, formatTimeWithMode } = useDateTimeFormat()
const card = ref<HTMLElement | null>(null)
const ready = ref(false)
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'))
const activityName = computed(() => localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
    || props.activity.activity_type?.slug || t('groups.activities.cards.unknown_type'))
const title = computed(() => props.activity.title || activityName.value)
const image = computed(() => props.activity.banner_image_url || props.activity.small_image_url)
const status = computed(() => getActivityStatusMeta(props.activity.status))
const date = computed(() => props.activity.starts_at ? formatDateTime(props.activity.starts_at, { dateStyle: 'medium' }) : '')
const time = computed(() => formatTimeWithMode(props.activity.starts_at, t('groups.activities.cards.no_time')))
const progress = computed(() => localizedValue(props.activity.target_prog_point_label, locale.value, fallbackLocale.value)
    || props.activity.target_prog_point_key)
const host = computed(() => props.activity.organized_by_character || props.activity.organized_by)
const hostLocation = computed(() => [props.activity.organized_by_character?.world, props.activity.organized_by_character?.datacenter]
    .filter(Boolean).join(' · '))
const groupHref = computed(() => props.activity.group ? route('groups.dashboard', { group: props.activity.group.slug }) : null)
const traitLabel = (namespace: string, value: string | null | undefined) => {
    if (!value) return '—'
    const key = `groups.activities.${namespace}.${value}`
    return te(key) ? t(key) : value
}
const traits = computed(() => [
    { label: t('groups.activities.create.summary.difficulty'), value: traitLabel('difficulties', props.activity.difficulty) },
    { label: t('groups.activities.create.summary.run_style'), value: traitLabel('run_styles', props.activity.run_style) },
    { label: t('groups.activities.create.summary.intensity'), value: traitLabel('intensities', props.activity.intensity) },
])

function focusCard() {
    if (props.focusContent && ready.value && !card.value?.contains(document.activeElement)) {
        card.value?.querySelector<HTMLAnchorElement>('a')?.focus({ preventScroll: true })
    }
}
function placed() {
    ready.value = true
    nextTick(focusCard)
}
function leave() {
    if (!card.value?.contains(document.activeElement)) emit('leave')
}
function focusOut(event: FocusEvent) {
    if (!(event.relatedTarget instanceof Node) || !card.value?.contains(event.relatedTarget)) emit('leave')
}
watch(() => props.open, open => {
    if (open && !card.value) ready.value = false
})
watch(() => props.focusContent, () => nextTick(focusCard))

const content = computed(() => ({
    reference: props.reference,
    side: 'right' as const,
    align: 'start' as const,
    sideOffset: 12,
    collisionPadding: 12,
    avoidCollisions: true,
    prioritizePosition: true,
    sticky: 'always' as const,
    hideWhenDetached: true,
    positionStrategy: 'fixed' as const,
    updatePositionStrategy: 'always' as const,
    'aria-label': title.value,
    onPlaced: placed,
    onOpenAutoFocus: (event: Event) => event.preventDefault(),
    onCloseAutoFocus: (event: Event) => event.preventDefault(),
    onInteractOutside: (event: CustomEvent) => {
        if (event.target instanceof Node && props.reference.contains(event.target)) event.preventDefault()
    },
    onEscapeKeyDown: () => {
        if (card.value?.contains(document.activeElement)) props.reference.focus({ preventScroll: true })
    },
}))
</script>

<template>
    <UPopover
        :open="open"
        :content="content"
        :ui="{ content: 'calendar-run-preview z-50 w-96 max-w-[calc(100vw-1.5rem)] overflow-visible rounded-xl bg-transparent shadow-none drop-shadow-2xl ring-0 dark:drop-shadow-none data-[state=open]:animate-none' }"
        @update:open="value => { if (!value) emit('close') }"
    >
        <template #content>
            <article
                ref="card"
                class="calendar-preview-card p-1"
                :data-ready="ready"
                @pointerenter="emit('keepOpen')"
                @pointerleave="leave"
                @focusin="emit('keepOpen')"
                @focusout="focusOut"
            >
                <header class="preview-heading relative z-30 overflow-hidden rounded-t-xl bg-white dark:bg-elevation-3 dark:shadow-md">
                    <div class="relative h-28 overflow-hidden bg-elevation-3 dark:bg-elevation-5">
                        <img v-if="image" :src="image" alt="" class="size-full object-cover" />
                        <UIcon v-else name="i-lucide-layers-3" class="absolute left-5 top-5 size-8 text-muted" />
                        <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/15 to-transparent" />
                        <p class="absolute inset-x-4 bottom-3 text-xs font-medium text-white">{{ activityName }}</p>
                    </div>
                    <div class="space-y-3 p-4">
                        <Link :href="activity.links.view" class="block rounded-sm text-lg font-semibold leading-snug text-highlighted outline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                            {{ title }}
                        </Link>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                            <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
                            <span v-if="date">{{ date }} ·</span><span>{{ time }}</span>
                        </p>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="flex items-center gap-1.5 text-sm text-toned">
                                <UIcon name="i-lucide-users" class="size-4 shrink-0" />
                                {{ t('groups.activities.calendar.preview.applicants', { count: activity.application_count }) }}
                            </span>
                            <UBadge :label="t(`groups.activities.statuses.${activity.status}`)" :icon="status.icon" :color="status.color" variant="soft" size="sm" class="rounded-md" />
                        </div>
                        <UBadge v-if="progress" :label="progress" icon="i-lucide-flag-triangle-right" color="brand" variant="soft" class="max-w-full rounded-md" :ui="{ label: 'whitespace-normal break-words' }" />
                    </div>
                </header>

                <div class="preview-fold preview-host relative z-20">
                    <section class="border-t border-default/50 bg-elevation-1 px-4 py-3 dark:bg-elevation-4">
                        <p class="mb-2 text-xs font-medium text-muted">{{ t('groups.activities.context_menu.summary_labels.host') }}</p>
                        <UUser
                            :name="host?.name || t('groups.activities.cards.no_organizer')"
                            :description="hostLocation"
                            :avatar="{ src: host?.avatar_url || undefined, icon: 'i-lucide-user-round' }"
                            :ui="{ wrapper: 'min-w-0', name: 'whitespace-normal break-words', description: 'whitespace-normal' }"
                        />
                    </section>
                    <div class="preview-fold preview-group relative z-10">
                        <section class="border-t border-default/50 bg-white px-4 py-3 dark:bg-elevation-3">
                            <p class="mb-2 text-xs font-medium text-muted">{{ t('groups.activities.management.overview.group') }}</p>
                            <Link v-if="activity.group && groupHref" :href="groupHref" class="block rounded-md outline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                                <UUser :name="activity.group.name" :avatar="{ src: activity.group.profile_picture_url || undefined, icon: 'i-lucide-users' }" :ui="{ wrapper: 'min-w-0', name: 'whitespace-normal break-words text-inherit' }" />
                            </Link>
                            <p v-else class="text-sm text-muted">—</p>
                        </section>
                        <div class="preview-fold preview-traits">
                            <dl class="grid grid-cols-3 gap-3 rounded-b-xl border-t border-default/50 bg-elevation-1 p-4 dark:bg-elevation-4">
                                <div v-for="trait in traits" :key="trait.label" class="min-w-0">
                                    <dt class="mb-1 text-xs text-muted">{{ trait.label }}</dt>
                                    <dd class="break-words text-sm font-medium text-highlighted">{{ trait.value }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </article>
        </template>
    </UPopover>
</template>

<style scoped>
.calendar-preview-card {
    --ui-primary: var(--color-brand-700);
    --ui-brand: var(--color-brand-700);
    max-height: min(calc(100dvh - 24px), var(--reka-popper-available-height, 100dvh));
    overflow-y: auto;
    overscroll-behavior: contain;
    perspective: 1000px;
}
.dark .calendar-preview-card {
    --ui-primary: var(--color-brand-400);
    --ui-brand: var(--color-brand-400);
}
.calendar-preview-card[data-ready='false'] { pointer-events: none; }
.preview-heading, .preview-fold { opacity: 0; }
.preview-heading { transform-origin: var(--reka-popper-transform-origin, center top); }
.preview-fold {
    /* Seal fractional-pixel seams between independently animated panels. */
    margin-top: -1px;
    transform-origin: center top;
    backface-visibility: hidden;
}
/* All four panels occupy their final size before positioning; only their visual folds animate. */
[data-ready='true'] .preview-heading {
    animation: preview-arrive 200ms cubic-bezier(.2, .7, .25, 1) both;
}
[data-ready='true'] .preview-fold {
    animation: preview-unfold 240ms cubic-bezier(.2, .7, .25, 1) both;
}
[data-ready='true'] .preview-host { animation-delay: 200ms; }
[data-ready='true'] .preview-group { animation-delay: 360ms; }
[data-ready='true'] .preview-traits { animation-delay: 520ms; }
@keyframes preview-arrive {
    from { opacity: 0; transform: scale(0); }
    to { opacity: 1; transform: none; }
}
@keyframes preview-unfold {
    from { opacity: 0; transform: perspective(900px) rotateX(-85deg); }
    to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
    [data-ready='true'] .preview-heading, [data-ready='true'] .preview-fold {
        animation: none;
        opacity: 1;
        transform: none;
    }
}
</style>

<style>
/* Animate the popover's own element so Reka Presence retains it until the exit finishes. */
.calendar-run-preview[data-state='closed'] {
    transform-origin: var(--reka-popper-transform-origin, center top);
    animation: calendar-preview-exit 180ms ease-in both;
    pointer-events: none;
}
@keyframes calendar-preview-exit {
    from { opacity: 1; transform: scale(1); }
    to { opacity: 0; transform: scale(0); }
}
@media (prefers-reduced-motion: reduce) {
    .calendar-run-preview[data-state='closed'] { animation: none; }
}
</style>
