<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import type { FormPagination, FormListRow } from '@/Types/Forms'
defineProps<{ forms: FormPagination<FormListRow> }>()
const { t } = useI18n()
</script>
<template>
    <Head :title="t('forms.title')" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3"><PageHeader :title="t('forms.title')" :subtitle="t('forms.admin_description')" /><UButton :to="route('admin.forms.create')" icon="i-lucide-plus" :label="t('forms.create')" /></div>
        <div class="divide-y divide-default border border-default">
            <p v-if="!forms.data.length" class="p-8 text-center text-muted">{{ t('forms.empty') }}</p>
            <Link v-for="form in forms.data" :key="form.slug" :href="route('admin.forms.edit', { surveyForm: form.slug })" class="flex items-center gap-4 p-4 hover:bg-elevated">
                <UIcon name="i-lucide-clipboard-list" class="size-5 text-primary" />
                <span class="min-w-0 flex-1"><span class="block font-semibold">{{ form.title }}</span><span class="text-xs text-muted">/forms/{{ form.slug }}</span></span>
                <span class="text-sm text-muted">{{ t('forms.response_count', { count: form.responses_count }) }}</span>
                <UBadge :color="form.is_published && form.is_open ? 'success' : 'neutral'" variant="subtle">{{ t(!form.is_published ? 'forms.draft' : form.is_open ? 'forms.open' : 'forms.closed_short') }}</UBadge>
            </Link>
        </div>
        <UPagination v-if="forms.total > forms.per_page" :page="forms.current_page" :total="forms.total" :items-per-page="forms.per_page" @update:page="router.get(route('admin.forms.index'), { page: $event })" />
    </div>
</template>
