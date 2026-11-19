<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useChangelogEditor } from '@/composables/useChangelogEditor'
import RichTextEditor from '@/components/Shared/RichText/RichTextEditor.vue'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import AdminPageHeader from '@/components/Admin/AdminPageHeader.vue'
import type { ChangelogDraft, ChangelogVersionChoices } from '@/Types/Changelog'
import type { RichTextDocument } from '@/Types/RichText'

const props = defineProps<{ entry: ChangelogDraft | null; versionChoices: ChangelogVersionChoices; emptyDocument: RichTextDocument }>()
const { t } = useI18n()
const page = usePage()
const { form, selectedLocale, locales, preview, errors, busy, refreshing, lockedVersions, versionChanged, versionLabel, save, refreshVersions, setPublished } = useChangelogEditor(props)
</script>

<template>
    <Head :title="t(entry ? 'changelog.edit' : 'changelog.create')" />
    <div class="space-y-5">
        <Link :href="route('admin.changelog.index')" class="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-muted hover:text-highlighted focus-visible:outline-primary"><UIcon name="i-lucide-arrow-left" />{{ t('changelog.title') }}</Link>
        <AdminPageHeader :title="t(entry ? 'changelog.edit' : 'changelog.create')" :subtitle="t('changelog.admin_description')" />
        <UAlert v-if="String(page.props.flash?.success ?? '').startsWith('changelog_')" color="success" variant="soft" :title="t('changelog.saved')" />
        <UAlert v-for="error in errors" :key="error" color="error" variant="soft" :title="error" />
        <form class="space-y-5" @submit.prevent="save">
            <section class="admin-panel min-w-0 space-y-4 p-4 sm:p-6">
                <h2 class="font-semibold">{{ t('changelog.coverage') }}</h2>
                <template v-if="lockedVersions">
                    <p class="font-medium text-primary">{{ versionLabel }}</p>
                    <p class="text-sm text-muted">{{ t('changelog.versions_locked') }}</p>
                </template>
                <template v-else>
                    <UAlert v-if="!versionChoices.current_version" color="warning" variant="soft" :title="t('changelog.errors.no_version')" />
                    <UAlert v-if="versionChanged" color="warning" variant="soft" :title="t('changelog.errors.version_changed')" />
                    <URadioGroup v-model="form.version_mode" :items="[
                        { value: 'current', label: t('changelog.current_choice', { version: form.version_context.current_version ?? '—' }), disabled: !form.version_context.current_version },
                        { value: 'since_last', label: t('changelog.since_choice', { from: form.version_context.previous_version ?? '—', to: form.version_context.current_version ?? '—' }), disabled: !versionChoices.can_use_range },
                    ]" />
                    <p v-if="!versionChoices.can_use_range" class="text-xs text-muted">{{ t('changelog.range_unavailable') }}</p>
                    <UButton color="neutral" variant="outline" icon="i-lucide-refresh-cw" :loading="refreshing" :label="t('changelog.refresh_versions')" @click="refreshVersions" />
                </template>
            </section>
            <section class="admin-panel min-w-0 space-y-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="admin-inset inline-flex flex-wrap gap-1 p-1" :aria-label="t('changelog.language')">
                        <UButton v-for="language in locales" :key="language" :color="selectedLocale === language ? 'primary' : 'neutral'" :variant="selectedLocale === language ? 'solid' : 'ghost'" :label="language.toUpperCase()" :aria-pressed="selectedLocale === language" @click="selectedLocale = language" />
                    </div>
                    <USwitch v-model="preview" :label="t('changelog.preview')" />
                </div>
                <p class="text-sm text-muted">{{ t('changelog.translation_help') }}</p>
                <template v-if="!preview">
                    <UFormField :label="t('changelog.entry_title')" :required="selectedLocale === 'en'"><UInput v-model="form.translations[selectedLocale].title" class="w-full" :maxlength="160" /></UFormField>
                    <RichTextEditor :key="selectedLocale" v-model="form.translations[selectedLocale].body" :max-length="50000" class="min-h-80 rounded-xl border border-default" @save="save" />
                </template>
                <article v-else class="admin-inset min-w-0 space-y-4 break-words p-4 sm:p-5">
                    <p class="text-sm text-primary">{{ versionLabel }}</p>
                    <h2 class="text-2xl font-semibold">{{ form.translations[selectedLocale].title || form.translations.en.title }}</h2>
                    <RichTextReader :document="form.translations[selectedLocale].title ? form.translations[selectedLocale].body : form.translations.en.body" />
                </article>
            </section>
            <div class="admin-panel flex flex-wrap items-center justify-between gap-3 p-4">
                <span class="text-sm text-muted">{{ t(entry?.is_published ? 'changelog.edit_live' : 'changelog.save_before_publish') }}</span>
                <div class="flex flex-wrap gap-2">
                    <UButton type="submit" icon="i-lucide-save" :loading="form.processing" :disabled="busy || (!lockedVersions && (!form.version_mode || !versionChoices.current_version || versionChanged))" :label="t('changelog.save')" />
                    <UButton v-if="entry" :color="entry.is_published ? 'warning' : 'success'" :loading="busy" :disabled="form.isDirty || form.processing || versionChanged" :label="t(entry.is_published ? 'changelog.unpublish' : 'changelog.publish')" @click="setPublished(!entry.is_published)" />
                </div>
            </div>
        </form>
    </div>
</template>
