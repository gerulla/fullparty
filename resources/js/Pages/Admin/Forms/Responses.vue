<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AdminPageHeader from '@/components/Admin/AdminPageHeader.vue'
import FormResponseModal from '@/components/Forms/FormResponseModal.vue'
import { useFormResponses } from '@/composables/useFormResponses'
import { localizedValue } from '@/utils/localizedValue'
import type { FormVersion, FormSummaryRow, FormPagination, FormResponseRow } from '@/Types/Forms'
const props = defineProps<{ formRecord: { slug: string; title: string }; versions: FormVersion[]; selectedVersion: number; totalResponses: number; summary: FormSummaryRow[]; responses: FormPagination<FormResponseRow> }>()
const { t, locale } = useI18n()
const { open, loading, error, detail, show, close } = useFormResponses(() => props.formRecord.slug)
const visit = (version: number, page = 1) => router.get(route('admin.forms.responses', { surveyForm: props.formRecord.slug }), { version, page }, { preserveScroll: true })
const date = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
</script>
<template>
    <Head :title="`${t('forms.responses')} · ${formRecord.title}`" />
    <div class="space-y-6">
        <Link :href="route('admin.forms.edit', { surveyForm: formRecord.slug })" class="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-muted hover:text-highlighted focus-visible:outline-primary"><UIcon name="i-lucide-arrow-left" />{{ t('forms.edit') }}</Link>
        <AdminPageHeader :title="formRecord.title" :subtitle="t('forms.results_description')" />
        <div class="admin-panel flex flex-wrap items-end gap-4 p-4 sm:p-5">
            <div class="mr-auto"><p class="text-3xl font-semibold tabular-nums text-highlighted">{{ totalResponses }}</p><p class="text-sm text-muted">{{ t('forms.total_responses') }}</p></div>
            <UFormField :label="t('forms.published_version')"><USelect :model-value="selectedVersion" :items="versions.map(version => ({ value: version.id, label: t('forms.version_number', { number: version.number }) }))" class="min-w-40" @update:model-value="visit(Number($event))" /></UFormField>
            <UButton :to="route('admin.forms.export', { surveyForm: formRecord.slug, version: selectedVersion })" external icon="i-lucide-download" color="neutral" variant="outline" :label="t('forms.export_csv')" />
        </div>
        <p class="text-sm text-muted">{{ t('forms.version_results_help', { count: responses.total }) }}</p>
        <div class="grid items-start gap-4 lg:grid-cols-2">
            <section v-for="(row, index) in summary" :key="row.question.id" class="admin-panel min-w-0 space-y-3 break-words p-4 sm:p-5">
                <h2 class="font-medium">{{ index + 1 }}. {{ localizedValue(row.question.label, locale) }}</h2>
                <p class="text-xs text-muted">{{ t('forms.answered_count', { count: row.answered }) }}<span v-if="row.average !== null"> · {{ t('forms.average', { value: row.average }) }}</span></p>
                <div v-for="option in row.counts" :key="option.id" class="space-y-1">
                    <div class="flex justify-between gap-4 text-sm"><span>{{ localizedValue(option.label, locale) }}</span><span class="tabular-nums">{{ option.count }}</span></div>
                    <div class="admin-inset h-2 overflow-hidden rounded-full"><div class="h-full rounded-full bg-primary" :style="{ width: `${row.answered ? option.count / row.answered * 100 : 0}%` }" /></div>
                </div>
                <p v-if="!row.counts.length && row.average === null" class="text-sm text-muted">{{ t('forms.text_results_help') }}</p>
            </section>
        </div>
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ t('forms.individual_responses') }}</h2>
            <p v-if="!responses.data.length" class="admin-panel p-8 text-center text-muted">{{ t('forms.no_responses') }}</p>
            <div v-else class="admin-panel divide-y divide-default overflow-hidden">
                <button v-for="response in responses.data" :key="response.id" type="button" class="flex w-full items-center justify-between gap-3 p-4 text-left hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary" @click="show(response.id)">
                    <span><span class="mr-2 font-medium tabular-nums text-muted">#{{ response.id }}</span>{{ response.respondent }}<span class="mt-1 block text-xs text-muted">{{ t('forms.updated_at') }} {{ date(response.updated_at) }}</span></span>
                    <UIcon name="i-lucide-chevron-right" class="shrink-0 text-muted" />
                </button>
            </div>
            <UPagination v-if="responses.total > responses.per_page" :page="responses.current_page" :total="responses.total" :items-per-page="responses.per_page" @update:page="visit(selectedVersion, $event)" />
        </section>
        <FormResponseModal :open="open" :loading="loading" :error="error" :detail="detail" @close="close" />
    </div>
</template>
