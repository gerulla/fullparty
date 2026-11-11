<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupNavigationContext } from '@/Types/GroupNavigation'
import type { DrsWorkspaceData } from '@/Types/Bozja'
import type { GroupContentSummary } from '@/Types/GroupContent'

const props = defineProps<{
    group: GroupNavigationContext
    content_summary: GroupContentSummary
    drs: DrsWorkspaceData | null
}>()
const DrsHolsterWorkspace = defineAsyncComponent(() => import('@/components/Groups/Content/DrsHolsterWorkspace.vue'))
const { t, locale } = useI18n()
const title = computed(() => t('groups.dashboard.navigation.content_customization'))
const cards = computed(() => [
    {
        key: 'drs',
        title: t('groups.index.content.delubrum_reginae_savage.title'),
        description: t('groups.index.content.overview.drs_description'),
        action: t('groups.index.content.overview.open_holsters'),
        count: t('groups.index.content.overview.holster_count', props.content_summary.holsters.total),
        active: props.content_summary.holsters.active,
        href: route('groups.dashboard.content.index', { group: props.group.slug, locale: locale.value, type: 'drs' }, false),
    },
    {
        key: 'forked_tower',
        title: t('groups.index.content.overview.forked_tower_title'),
        description: t('groups.index.content.overview.forked_tower_description'),
        action: t('groups.index.content.overview.open_compositions'),
        count: t('groups.index.content.overview.composition_count', props.content_summary.compositions.total),
        active: props.content_summary.compositions.active,
        href: route('groups.dashboard.content.forked-tower-blood', { group: props.group.slug, locale: locale.value }, false),
    },
])
const holsterIcons = ['i-lucide-shield', 'i-lucide-swords', 'i-lucide-heart-pulse']
const parties = ['A', 'B', 'C', 'D', 'E', 'F']
</script>

<template>
    <Head :title="title" />
    <div class="w-full">
        <h1 class="sr-only">{{ title }}</h1>
        <DrsHolsterWorkspace v-if="drs" :key="group.id" :group-slug="group.slug" :initial-data="drs" />
        <div v-else class="grid max-w-6xl gap-5 md:mx-auto md:grid-cols-2">
            <UCard v-for="card in cards" :key="card.key" :as="Link" :href="card.href"
                :aria-labelledby="`content-choice-${card.key}`"
                class="group flex min-w-0 flex-col rounded-xl border border-elevation-3 bg-elevation-1 text-left shadow-sm ring-0 transition-colors hover:border-brand-500 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand motion-reduce:transition-none dark:border-elevation-7 dark:bg-elevation-3 dark:shadow-none dark:hover:border-brand-400"
                :ui="{ body: 'flex flex-1 flex-col p-5 sm:p-6' }">
                <div class="mb-5 flex h-28 items-center justify-center gap-4 rounded-lg bg-elevation-2 text-muted dark:bg-elevation-4" aria-hidden="true">
                    <template v-if="card.key === 'drs'">
                        <span v-for="(icon, index) in holsterIcons" :key="icon"
                            class="flex size-12 items-center justify-center rounded-lg border border-elevation-4 bg-elevation-1 dark:border-elevation-7 dark:bg-elevation-3"
                            :class="index === 1 ? '-translate-y-2 text-brand-700 dark:text-brand-300' : ''">
                            <UIcon :name="icon" class="size-4" />
                        </span>
                    </template>
                    <template v-else>
                        <div class="grid grid-cols-3 gap-1.5">
                            <span v-for="party in parties" :key="party"
                                class="flex h-8 w-9 items-center justify-center rounded-md border border-elevation-4 bg-elevation-1 text-xs dark:border-elevation-7 dark:bg-elevation-3">
                                {{ party }}
                            </span>
                        </div>
                        <UIcon name="i-lucide-sparkles" class="size-4" />
                    </template>
                </div>
                <h2 :id="`content-choice-${card.key}`" class="text-lg font-semibold text-highlighted">
                    {{ card.title }}
                </h2>
                <p class="mt-1 text-sm leading-relaxed text-muted">{{ card.description }}</p>
                <p class="mt-auto pt-5 text-xs text-muted">
                    {{ card.count }} · {{ t('groups.index.content.overview.active_count', { count: card.active }) }}
                </p>
                <span class="mt-4 flex items-center justify-between gap-3 text-sm font-semibold text-brand-700 dark:text-brand-300">
                    {{ card.action }}
                    <UIcon name="i-lucide-arrow-right" class="size-4 shrink-0" aria-hidden="true" />
                </span>
            </UCard>
        </div>
    </div>
</template>
