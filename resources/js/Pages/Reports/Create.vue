<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { ReportTarget } from '@/Types/Reports'
import PageHeader from '@/components/PageHeader.vue'
import ReportModal from '@/components/Shared/Reports/ReportModal.vue'

defineProps<{ target: ReportTarget }>()
const { t } = useI18n()
const open = ref(true)
</script>

<template>
    <Head :title="t('reports.title')" />
    <PageHeader :title="t('reports.title')" :subtitle="target.label">
        <UButton icon="i-lucide-flag" color="neutral" variant="outline" :label="t('reports.report')" @click="open = true" />
    </PageHeader>
    <ReportModal v-model:open="open" :target="target" />
</template>
