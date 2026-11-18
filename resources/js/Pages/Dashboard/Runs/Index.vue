<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { useBreakpoints, useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import RunBrowseSection from '@/components/Runs/RunBrowseSection.vue'
import TrendingActivitiesSection from '@/components/Runs/TrendingActivitiesSection.vue'
import RunSearchResults from '@/components/Runs/RunSearchResults.vue'
import RunSearchFilters from '@/components/Runs/RunSearchFilters.vue'
import { useRunBrowseSearch } from '@/composables/useRunBrowseSearch'
import type { RunBrowseSection as BrowseSection, RunDiscoveryPageProps } from '../../../Types/RunDiscovery'
import { localizedValue } from '@/utils/localizedValue'

defineOptions({ layout: V2Layout })

const props = defineProps<RunDiscoveryPageProps>()
const { t, locale } = useI18n()
const gridBreakpoints = useBreakpoints({ sm: '40rem', lg: '64rem', xl: '80rem', '2xl': '96rem' }).current()
const resultColumns = computed(() => 1 + gridBreakpoints.value.length)
const wideDesktop = useMediaQuery('(min-width: 120rem)')
const resultsPerPage = computed(() => wideDesktop.value ? 20 : resultColumns.value * Math.round(10 / resultColumns.value))
const { active: searching, applied, searchTimezone, applyFilters, setSort, loading: searchLoading, failed, result, selectActivity, goToPage, bookmarkChanged, clear, retry } = useRunBrowseSearch(() => props.lookups, () => resultsPerPage.value)
const loading = computed(() => props.browseSections === undefined)
const sections = computed<BrowseSection[]>(() => props.browseSections ?? [
    { id: 'recommended', title_key: 'recommended', runs: [] },
    { id: 'recently-posted', title_key: 'recently_posted', runs: [] },
    { id: 'almost-full', title_key: 'almost_full', runs: [] },
    { id: 'latest-content', title_key: 'latest_content', runs: [] },
])

function sectionTitle(section: BrowseSection): string {
    return t(`runs.browse.sections.${section.title_key}`, {
        activity: localizedValue(section.activity_name, locale.value),
    })
}
</script>

<template>
    <Head :title="`${t('runs.browse.title')} -`" />

    <div class="run-browse mx-auto w-full min-w-0 max-w-[116rem] space-y-10 pb-6 sm:space-y-12">
        <h1 class="sr-only">{{ t('runs.browse.title') }}</h1>

        <template v-if="searching">
            <RunSearchFilters :search="applied" :timezone="searchTimezone" :lookups="lookups" @apply="applyFilters" />
            <RunSearchResults :result="result" :loading="searchLoading" :failed="failed"
                :columns="resultColumns" :per-page="resultsPerPage" :sort="applied.sort"
                @sort="setSort" @page="goToPage" @clear="clear" @retry="retry" @bookmark-changed="bookmarkChanged" />
        </template>
        <template v-else>
            <template v-for="section in sections" :key="section.id">
                <RunBrowseSection :title="sectionTitle(section)" :runs="section.runs" :loading="loading" />
                <TrendingActivitiesSection v-if="section.id === 'recommended'" :activities="trendingActivities" @select="selectActivity" />
            </template>
        </template>
    </div>
</template>

<style scoped>
.run-browse {
    --ui-primary: var(--color-brand-700);
    --ui-brand: var(--color-brand-700);
}

.dark .run-browse {
    --ui-primary: var(--color-brand-400);
    --ui-brand: var(--color-brand-400);
}
</style>
