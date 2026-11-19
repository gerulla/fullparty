<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AdminPageHeader from '@/components/Admin/AdminPageHeader.vue'
import type { FormPagination, FormListRow } from '@/Types/Forms'
defineProps<{ forms: FormPagination<FormListRow> }>()
const { t } = useI18n()
</script>
<template>
    <Head :title="t('forms.title')" />
    <div class="space-y-6">
        <AdminPageHeader :title="t('forms.title')" :subtitle="t('forms.admin_description')">
            <UButton :to="route('admin.forms.create')" icon="i-lucide-plus" :label="t('forms.create')" />
        </AdminPageHeader>
        <div class="admin-panel divide-y divide-default overflow-hidden">
            <p v-if="!forms.data.length" class="p-8 text-center text-muted">{{ t('forms.empty') }}</p>
            <Link v-for="form in forms.data" :key="form.slug" :href="route('admin.forms.edit', { surveyForm: form.slug })" class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 p-4 transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary sm:grid-cols-[auto_minmax(0,1fr)_auto_auto] sm:gap-x-4 sm:px-5">
                <span class="admin-inset row-span-2 grid size-10 place-items-center sm:row-span-1"><UIcon name="i-lucide-clipboard-list" class="size-5 text-primary" /></span>
                <span class="min-w-0 break-words"><span class="block font-semibold text-highlighted">{{ form.title }}</span><span class="text-xs text-muted">/forms/{{ form.slug }}</span></span>
                <span class="col-start-2 row-start-2 text-xs text-muted sm:col-start-auto sm:row-start-auto sm:text-sm">{{ t('forms.response_count', { count: form.responses_count }) }}</span>
                <UBadge class="col-start-3 row-span-2 row-start-1 sm:col-start-auto sm:row-span-1 sm:row-start-auto" :color="form.is_published && form.is_open ? 'success' : 'neutral'" variant="subtle">{{ t(!form.is_published ? 'forms.draft' : form.is_open ? 'forms.open' : 'forms.closed_short') }}</UBadge>
            </Link>
        </div>
        <UPagination v-if="forms.total > forms.per_page" :page="forms.current_page" :total="forms.total" :items-per-page="forms.per_page" @update:page="router.get(route('admin.forms.index'), { page: $event })" />
    </div>
</template>
