<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import ChangelogButton from '@/components/Changelog/ChangelogButton.vue'
import { useAppearance } from '@/composables/useAppearance'

const page = usePage()
const { t, locale } = useI18n()
const { isDark } = useAppearance()
const logoUrl = '/logos/full.png'
const year = new Date().getFullYear()
const siteLinks = computed(() => page.props.site_links ?? {})
const appVersion = computed(() => {
    const version = page.props.app_version?.version
    return typeof version === 'string' && version.trim() ? version.trim() : 'dev'
})
const sections = computed(() => [
    {
        key: 'explore',
        label: t('navigation.footer.explore'),
        links: [
            { label: t('navigation.sidebar.find_runs'), href: route('dashboard.runs.index', { locale: locale.value }), external: false },
            { label: t('navigation.sidebar.groups'), href: route('groups.index', { locale: locale.value }), external: false },
            { label: t('navigation.sidebar.my_runs'), href: route('account.runs.index', { locale: locale.value }), external: false },
        ],
    },
    {
        key: 'community',
        label: t('navigation.footer.community'),
        links: [
            ...(siteLinks.value.discord ? [{ label: t('navigation.footer.discord'), href: siteLinks.value.discord, external: true }] : []),
            ...(siteLinks.value.github ? [{ label: t('navigation.footer.github'), href: siteLinks.value.github, external: true }] : []),
            { label: t('navigation.footer.api_documentation'), href: route('api-docs.index'), external: true },
        ],
    },
    {
        key: 'legal',
        label: t('navigation.footer.legal'),
        links: [
            { label: t('navigation.footer.privacy'), href: route('legal.privacy', { locale: locale.value }), external: false },
            { label: t('navigation.footer.cookie_policy'), href: route('legal.cookies', { locale: locale.value }), external: false },
        ],
    },
])
</script>

<template>
    <footer class="shrink-0 border-t border-default bg-elevation-1 px-4 py-8 text-sm text-muted dark:bg-elevation-2 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 gap-6 md:grid-cols-3 md:gap-8 lg:grid-cols-4">
            <div class="flex min-w-0 items-center justify-center max-md:col-start-2 max-md:row-start-1 md:col-span-3 lg:col-span-1">
                <Link :href="route('dashboard', { locale })" class="inline-flex w-64 max-w-full rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary sm:w-72">
                    <span class="inline-flex w-full" :class="{ 'bg-brand-950 mask-contain mask-center mask-no-repeat': !isDark }"
                        :style="isDark ? undefined : { maskImage: `url(${logoUrl})` }">
                        <img :src="logoUrl" alt="FullParty" class="h-auto w-full object-contain" :class="{ 'opacity-0': !isDark }">
                    </span>
                </Link>
            </div>

            <nav v-for="section in sections" :key="section.key" :aria-label="section.label">
                <h2 class="mb-3 font-semibold text-default">{{ section.label }}</h2>
                <ul class="space-y-1">
                    <li v-for="link in section.links" :key="link.href">
                        <component :is="link.external ? 'a' : Link" :href="link.href"
                            :target="link.external ? '_blank' : undefined" :rel="link.external ? 'noopener noreferrer' : undefined"
                            class="footer-link min-h-8 gap-1.5 py-1">
                            {{ link.label }}
                            <UIcon v-if="link.external" name="i-lucide-arrow-up-right" class="size-3.5 shrink-0" aria-hidden="true" />
                        </component>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="mt-7 border-t border-default pt-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p>{{ t('navigation.footer.copyright', { year }) }}</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>{{ t('navigation.footer.version', { version: appVersion }) }}</span>
                    <span aria-hidden="true">·</span>
                    <ChangelogButton :collapsed="false" appearance="footer" />
                </div>
            </div>
            <div class="mt-4 space-y-1 leading-relaxed">
                <p>{{ t('navigation.footer.game_assets') }}</p>
                <p>{{ t('navigation.footer.independence') }}</p>
                <i18n-t keypath="navigation.footer.fflogs_credit" tag="p">
                    <template #provider>
                        <a href="https://www.fflogs.com/" target="_blank" rel="noopener noreferrer" class="footer-link underline decoration-current/40 underline-offset-4">FF Logs</a>
                    </template>
                </i18n-t>
            </div>
        </div>
    </footer>
</template>

<style scoped>
@reference '../../../css/app.css';

.footer-link {
    @apply inline-flex items-center rounded-sm text-muted transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary;
}
</style>
