<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import AppLocaleSelect from '@/components/Navigation/AppLocaleSelect.vue'
import SeoHead from '@/components/Shared/SeoHead.vue'
import { usePersistentLocale } from '@/composables/usePersistentLocale'

defineProps<{ discordUrl: string | null }>()
defineOptions({ layout: [] })

const { t } = useI18n()
const { currentUiLocale } = usePersistentLocale()
const logout = useForm({})
</script>

<template>
    <UApp :locale="currentUiLocale">
        <SeoHead :title="t('reports.banned_page.title')" :description="t('reports.banned_page.description')" noindex />
        <div class="relative flex min-h-dvh flex-col overflow-hidden bg-neutral-950 text-neutral-50">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-radial-[at_50%_30%] from-brand-900/25 via-neutral-950 to-neutral-950" />
            <header class="relative flex items-center justify-between gap-4 border-b border-white/10 px-6 py-5 sm:px-10">
                <span class="text-2xl font-bold italic tracking-tight">FullParty</span>
                <AppLocaleSelect compact />
            </header>

            <main class="relative flex flex-1 items-center justify-center px-6 py-16">
                <div class="w-full max-w-xl text-center">
                    <div aria-hidden="true" class="relative mx-auto mb-8 flex h-36 items-center justify-center">
                        <span class="absolute text-[10rem] leading-none font-black tracking-tighter text-white/5">403</span>
                        <div class="relative flex size-20 items-center justify-center border border-brand-400/30 bg-brand-950/70 text-brand-300">
                            <UIcon name="i-lucide-shield-ban" class="size-10" />
                        </div>
                    </div>
                    <p class="text-xs font-semibold tracking-widest text-brand-300 uppercase">{{ t('reports.banned_page.label') }}</p>
                    <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">{{ t('reports.banned_page.title') }}</h1>
                    <p class="mx-auto mt-4 max-w-lg text-base leading-7 text-neutral-300">{{ t('reports.banned_page.description') }}</p>

                    <div class="mt-8 border border-white/10 bg-white/3 p-6">
                        <p class="text-sm leading-6 text-neutral-300">{{ t('reports.banned_page.help') }}</p>
                        <UButton v-if="discordUrl" :href="discordUrl" target="_blank" rel="noopener noreferrer" icon="i-simple-icons-discord"
                            size="lg" class="mt-5" :label="t('reports.banned_page.discord')" />
                    </div>

                    <UButton color="neutral" variant="ghost" icon="i-lucide-log-out" class="mt-6" :loading="logout.processing"
                        :label="t('reports.banned_page.logout')" @click="logout.post(route('logout'))" />
                </div>
            </main>
        </div>
    </UApp>
</template>
