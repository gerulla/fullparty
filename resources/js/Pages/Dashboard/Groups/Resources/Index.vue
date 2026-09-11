<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import ResourceReaderArticle from '@/components/Groups/Resources/ResourceReaderArticle.vue'
import type { ResourceReaderDocument } from '@/Types/GroupResources'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

defineProps<{ group: { id: number, name: string, slug: string }; resource?: ResourceReaderDocument }>()

const { t } = useI18n()
</script>

<template>
	<Head :title="t('groups.resources.reader.title')" />
	<PageHeader
		:title="t('groups.resources.reader.title')"
		:subtitle="t('groups.resources.reader.description')"
	/>
    <ResourceReaderArticle v-if="resource" :resource="resource" class="mt-6" />
</template>
