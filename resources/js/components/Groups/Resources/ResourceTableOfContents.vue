<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceReaderSection } from '@/Types/GroupResources'

withDefaults(defineProps<{ sections: ResourceReaderSection[]; activeId: string; showTitle?: boolean }>(), { showTitle: true })
const { t } = useI18n()
</script>

<template>
    <nav :aria-label="t('groups.resources.reader.contents')">
        <h2 v-if="showTitle" class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ t('groups.resources.reader.contents') }}</h2>
        <ol class="border-l border-default text-xs leading-relaxed">
            <li v-for="section in sections" :key="section.id">
                <a :href="`#${encodeURIComponent(section.id)}`" :aria-current="activeId === section.id ? 'location' : undefined" class="-ml-px block border-l-2 py-2 pr-2 transition-colors hover:text-primary" :class="activeId === section.id ? 'border-primary font-medium text-primary' : 'border-transparent text-muted'" :style="{ paddingLeft: `${12 + section.depth * 12}px` }">{{ section.title }}</a>
            </li>
        </ol>
    </nav>
</template>
