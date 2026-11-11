<script setup lang="ts">
import ResourceKnowledgeHub from '@/components/Groups/Resources/ResourceKnowledgeHub.vue'
import type { ResourceReaderPage } from '../../../../Types/GroupResources'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const props = defineProps<ResourceReaderPage>()

const { t } = useI18n()
</script>

<template>
    <Head :title="resource && !resource.is_home ? resource.title : t('groups.resources.reader.title')" />
    <ResourceKnowledgeHub v-bind="props" class="mx-auto w-full max-w-7xl dark:[--ui-primary:var(--color-brand-400)]" />
</template>
