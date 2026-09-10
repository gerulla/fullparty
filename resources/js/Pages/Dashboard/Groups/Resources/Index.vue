<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

defineProps<{ group: { id: number, name: string, slug: string } }>()

const { t } = useI18n()
</script>

<template>
	<Head :title="t('groups.resources.reader.title')" />
	<PageHeader
		:title="t('groups.resources.reader.title')"
		:subtitle="t('groups.resources.reader.description')"
	/>
</template>
