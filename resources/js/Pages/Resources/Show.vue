<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersistentLocale } from '@/composables/usePersistentLocale'
import SeoHead from '@/components/Shared/SeoHead.vue'
import ResourceKnowledgeHub from '@/components/Groups/Resources/ResourceKnowledgeHub.vue'
import ResourcePublicHeader from '@/components/Groups/Resources/ResourcePublicHeader.vue'
import { usePublicResourceAppearance } from '@/composables/usePublicResourceAppearance'
import type { ResourceReaderPage, ResourceReaderSeo } from '../../Types/GroupResources'

defineOptions({ layout: [] })
const props = defineProps<ResourceReaderPage & { main_site_url: string; seo: ResourceReaderSeo }>()
const { t } = useI18n()
const { currentUiLocale } = usePersistentLocale()
const { isDark, toggle } = usePublicResourceAppearance(() => props.library)
const accent = computed(() => /^#[a-fA-F0-9]{6}$/.test(props.library.customization.accent_color || '') ? { '--ui-primary': props.library.customization.accent_color } : {})
</script>

<template>
    <SeoHead :title="seo.title" :description="seo.description" :canonical="seo.url" :image="seo.image" :og-type="seo.type" :append-site-name="false" />
    <UApp :locale="currentUiLocale">
        <div class="public-resource-page min-h-screen bg-default text-default" :style="accent">
            <ResourcePublicHeader :group="group" :library="library">
                <UButton :icon="isDark ? 'i-lucide-sun' : 'i-lucide-moon'" :label="t(`groups.resources.reader.${isDark ? 'light_mode' : 'dark_mode'}`)" color="neutral" variant="outline" size="sm" @click="toggle" />
            </ResourcePublicHeader>
            <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8"><ResourceKnowledgeHub v-bind="props" public-view /></div>
            <footer class="mx-auto max-w-7xl border-t border-default px-5 py-5 text-center text-xs text-muted sm:px-8"><a :href="main_site_url" class="hover:text-primary">{{ t('groups.resources.reader.powered_by') }}</a></footer>
        </div>
    </UApp>
</template>

<style scoped>
.public-resource-page :deep(input), .public-resource-page :deep(button[role="combobox"]) { background-color: var(--ui-bg-elevated); color: var(--ui-text); }
.public-resource-page :deep(input::placeholder) { color: var(--ui-text-muted); }
</style>
