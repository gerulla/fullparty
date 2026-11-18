<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t, locale } = useI18n()
const page = usePage()
const emit = defineEmits<{ navigate: [] }>()
</script>

<template>
    <nav :aria-label="t('runs.search.finder.label')" class="finder-nav">
        <Link :href="route('dashboard.runs.index', { locale })" class="finder-item"
            :aria-current="page.component === 'Dashboard/Runs/Index' ? 'page' : 'true'" @start="emit('navigate')">
            <UIcon name="i-lucide-compass" class="finder-icon text-brand-700 dark:text-brand-400" />
            {{ t('runs.search.finder.runs') }}
            <span aria-hidden="true" class="absolute inset-x-2 bottom-0 hidden h-0.5 rounded-full bg-brand-700 md:block dark:bg-brand-400" />
        </Link>
        <Link :href="`${route('groups.index', { locale })}#group-search`" class="finder-item" @start="emit('navigate')">
            <UIcon name="i-lucide-users-round" class="finder-icon" />
            {{ t('runs.search.finder.groups') }}
        </Link>
        <button type="button" disabled class="finder-item">
            <UIcon name="i-lucide-shield" class="finder-icon" />
            <span class="flex flex-col items-start gap-0.5">
                {{ t('runs.search.finder.statics') }}
                <span class="rounded-full bg-elevation-3 px-1.5 py-0.5 text-[0.625rem] font-medium leading-none text-muted dark:bg-elevation-5">
                    {{ t('runs.search.finder.coming_soon') }}
                </span>
            </span>
        </button>
    </nav>
</template>

<style scoped>
@reference "../../../css/app.css";

.finder-nav {
    @apply flex items-center gap-2;
}
.finder-item {
    @apply relative inline-flex min-h-11 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-3 text-xs font-semibold text-muted;
}
.finder-item:focus-visible {
    @apply outline-2 -outline-offset-2 outline-brand-700 dark:outline-brand-400;
}
.finder-item[aria-current], .finder-item[href]:hover {
    @apply text-highlighted;
}
.finder-icon {
    @apply size-4 shrink-0;
}
@media (width < 48rem) {
    .finder-nav {
        @apply col-span-full row-start-3 min-w-0 gap-1 overflow-x-auto px-1 pb-2.5 pt-1;
        justify-content: safe center;
        scrollbar-width: none;
    }
    .finder-item {
        @apply gap-1 bg-elevation-1 px-2 shadow-sm dark:bg-elevation-3;
    }
    .finder-item[aria-current] {
        @apply bg-brand-100 text-brand-800 dark:bg-brand-400/15 dark:text-brand-300;
    }
}
@media (width >= 48rem) {
    .finder-nav {
        @apply col-start-2 row-start-1 justify-self-center gap-1 lg:gap-4;
    }
    .finder-item {
        @apply min-h-12 gap-2 rounded-md px-2 text-sm;
    }
    .finder-icon {
        @apply size-5 lg:size-6;
    }
}
</style>
