<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import FinderNavigation from '@/components/Navigation/FinderNavigation.vue'
import RunSearchBar from '@/components/Runs/RunSearchBar.vue'
import { useAppearance } from '@/composables/useAppearance'
import { useGlobalRunSearch } from '@/composables/useGlobalRunSearch'
import { usePersistentLocale } from '@/composables/usePersistentLocale'

withDefaults(defineProps<{ desktopLogo?: boolean }>(), { desktopLogo: true })
const page = usePage()
const { t } = useI18n()
const signedIn = computed(() => Boolean(page.props.auth?.user))
const { currentLocale } = usePersistentLocale()
const { isDark } = useAppearance()
const { onFindRuns, draft, appliedSearch, lookups, optionsLoading, optionsFailed, loadOptions, submit, backToBrowse } = useGlobalRunSearch()
const desktopPickerOpen = ref(false)
const searchBar = ref<InstanceType<typeof RunSearchBar> | null>(null)
watch(() => page.url, () => searchBar.value?.close())
</script>

<template>
    <header data-run-search-header data-account-flags-header
        class="sticky top-0 z-40 shrink-0"
        :class="[onFindRuns ? 'bg-elevation-2 dark:bg-elevation-1' : 'border-b border-default bg-elevation-1 dark:bg-elevation-2',
            { 'md:pb-[5.25rem]': desktopPickerOpen && signedIn && !onFindRuns }]">
        <div data-run-search-row class="topbar-grid" :class="{ 'with-search': signedIn && !onFindRuns, 'search-open': desktopPickerOpen, 'finder': signedIn && onFindRuns }">
            <div class="col-start-1 row-start-1 flex min-w-0 items-center gap-1 sm:gap-2">
                <slot name="leading" />
                <UButton v-if="signedIn && appliedSearch" type="button" color="neutral" variant="ghost" icon="i-lucide-arrow-left"
                    class="size-11 shrink-0 justify-center rounded-full md:hidden" :aria-label="t('runs.search.back_to_browse')" @click="backToBrowse" />
                <Link :href="route('dashboard', { locale: currentLocale })" aria-label="FullParty"
                    class="topbar-logo" :class="{ 'md:hidden': !desktopLogo }">
                    <span class="block" :class="{ 'bg-brand-950 mask-contain mask-center mask-no-repeat': !isDark }"
                        :style="isDark ? undefined : { maskImage: 'url(/logos/full.png)' }">
                        <img :src="'/logos/full.png'" alt="FullParty" class="block h-auto w-28 md:w-32 2xl:w-40" :class="{ 'opacity-0': !isDark }">
                    </span>
                </Link>
                <div class="topbar-context contents">
                    <slot name="context" />
                </div>
            </div>
            <div v-if="signedIn" class="topbar-search flex min-w-0 items-center gap-1.5 sm:gap-2.5">
                <RunSearchBar ref="searchBar" v-model="draft" :pinned="onFindRuns" :applied-search="appliedSearch" :lookups="lookups"
                    :options-loading="optionsLoading" :options-failed="optionsFailed" class="min-w-0 flex-1" :class="{ 'max-w-[850px]': onFindRuns }"
                    @open="loadOptions" @retry-options="loadOptions" @search="submit" @update:expanded="desktopPickerOpen = $event" />
                <div id="run-search-filters-trigger" class="shrink-0 empty:hidden" />
            </div>
            <FinderNavigation v-if="signedIn && onFindRuns" />
            <div class="col-start-3 row-start-1 flex min-w-0 items-center justify-end gap-0.5 sm:gap-1.5">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>

<style scoped>
[data-run-search-header] {
    container: search-header / inline-size;
    transition: padding-bottom 360ms cubic-bezier(.2,.8,.2,1);
}
.topbar-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    grid-template-rows: minmax(var(--ui-header-height, 4rem), auto);
    align-items: center;
    gap: 0 .5rem;
    padding-inline: clamp(.5rem, 1.75vw, 2rem);
}
.topbar-logo { flex: none; }
.topbar-search { grid-column: 1 / -1; grid-row: 2; width: 100%; margin-bottom: .65rem; }
@media (width < 48rem) {
    .topbar-logo { position: absolute; left: 50%; transform: translateX(-50%); }
}
@media (width >= 48rem) {
    .topbar-grid.with-search { grid-template-columns: minmax(0, 1fr) minmax(0, min(32%, 24rem)) minmax(0, 1fr); }
    .topbar-grid.with-search.search-open { grid-template-columns: minmax(0, 1fr) max-content minmax(0, 1fr); }
    .topbar-search { grid-column: 2; grid-row: 1; margin: 0; }
    .finder .topbar-search { grid-column: 1 / -1; grid-row: 2; justify-content: center; margin-block: .5rem .75rem; }
}
@media (prefers-reduced-motion: reduce) {
    [data-run-search-header] { transition: none; }
}
@container search-header (width < 64rem) {
    .with-search.search-open .topbar-context { display: none; }
}
</style>
