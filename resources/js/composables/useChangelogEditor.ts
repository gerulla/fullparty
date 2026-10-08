import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { ChangelogDraft, ChangelogForm, ChangelogLocale, ChangelogVersionChoices } from '@/Types/Changelog'
import type { RichTextDocument } from '@/Types/RichText'

export function useChangelogEditor(props: { entry: ChangelogDraft | null; versionChoices: ChangelogVersionChoices; emptyDocument: RichTextDocument }) {
    const { t } = useI18n()
    const confirmation = useConfirmationModal()
    const selectedLocale = ref<ChangelogLocale>('en')
    const preview = ref(false)
    const actionErrors = ref<Record<string, string>>({})
    const busy = ref(false)
    const refreshing = ref(false)
    const locales: ChangelogLocale[] = ['en', 'de', 'fr', 'ja']
    const initial = (): ChangelogForm => ({
        translations: Object.fromEntries(locales.map(key => [key, JSON.parse(JSON.stringify(props.entry?.translations[key] ?? { title: '', body: props.emptyDocument }))])) as ChangelogForm['translations'],
        version_mode: props.entry?.version_mode ?? '',
        revision: props.entry?.revision ?? 1,
        version_context: props.entry ? {
            ...props.versionChoices,
            current_version: props.entry.version_to, current_commit: props.entry.commit,
            previous_entry_id: props.entry.baseline_id,
            previous_version: props.entry.version_mode === 'since_last' ? props.entry.version_from : props.versionChoices.previous_version,
        } : { ...props.versionChoices },
    })
    const form = useForm<ChangelogForm>(initial())
    watch(() => props.entry?.id, () => { form.defaults(initial()); form.reset(); form.clearErrors(); actionErrors.value = {} })
    const lockedVersions = computed(() => Boolean(props.entry?.published_at))
    const versionChanged = computed(() => !lockedVersions.value && ['current_version', 'current_commit', 'previous_entry_id', 'previous_version'].some(key => form.version_context[key as keyof ChangelogVersionChoices] !== props.versionChoices[key as keyof ChangelogVersionChoices]))
    const versionLabel = computed(() => lockedVersions.value
        ? (props.entry?.version_from ? t('changelog.range', { from: props.entry.version_from, to: props.entry.version_to }) : t('changelog.version', { version: props.entry?.version_to }))
        : form.version_mode === 'since_last' ? t('changelog.range', { from: form.version_context.previous_version, to: form.version_context.current_version }) : t('changelog.version', { version: form.version_context.current_version ?? '—' }))
    const errors = computed(() => [...new Set([...Object.values(form.errors), ...Object.values(actionErrors.value)])])

    function save() {
        if (form.processing || busy.value) return
        actionErrors.value = {}
        const options = { preserveScroll: true, onSuccess: () => { form.defaults(initial()); form.reset() } }
        if (props.entry) form.put(route('admin.changelog.update', { changelogEntry: props.entry.id }), options)
        else form.post(route('admin.changelog.store'), options)
    }

    function refreshVersions() {
        refreshing.value = true
        router.reload({ only: ['versionChoices'], onSuccess: () => {
            form.version_context = { ...props.versionChoices }
            if (form.version_mode === 'since_last' && !props.versionChoices.can_use_range) form.version_mode = ''
            form.clearErrors('version_mode')
            actionErrors.value = {}
        }, onFinish: () => { refreshing.value = false } })
    }

    async function setPublished(published: boolean) {
        if (!props.entry || form.isDirty || busy.value) return
        const confirmed = await confirmation.open({
            title: t(published ? 'changelog.publish' : 'changelog.unpublish'),
            description: versionLabel.value,
            warningText: t(published ? 'changelog.publish_confirm' : 'changelog.unpublish_confirm'),
            severity: published ? 'info' : 'warning',
            confirmLabel: t(published ? 'changelog.publish' : 'changelog.unpublish'),
        })
        if (!confirmed) return
        busy.value = true
        actionErrors.value = {}
        router.post(route(published ? 'admin.changelog.publish' : 'admin.changelog.unpublish', { changelogEntry: props.entry.id }), { revision: props.entry.revision }, {
            preserveScroll: true,
            onSuccess: () => { form.defaults(initial()); form.reset() },
            onError: value => { actionErrors.value = value },
            onFinish: () => { busy.value = false },
        })
    }
    return { form, selectedLocale, locales, preview, errors, busy, refreshing, lockedVersions, versionChanged, versionLabel, save, refreshVersions, setPublished }
}
