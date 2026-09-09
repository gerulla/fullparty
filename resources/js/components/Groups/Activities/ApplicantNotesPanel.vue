<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MemberNotePayload } from '@/Types/Groups';
import { createDateTimeFormatter } from '@/utils/dateTimeFormat';
import { memberNotePresentation } from '@/utils/memberNotePresentation';

const props = defineProps<{
    notes: MemberNotePayload | null
    loading: boolean
    error: boolean
    applicationNote: string | null
}>();
defineEmits<{ retry: [] }>();
const { t, locale } = useI18n();
const sections = computed(() => [
    { key: 'current_group', items: props.notes?.current_group ?? [] },
    { key: 'shared', items: props.notes?.shared ?? [] },
]);
const date = (value: string | null) => value
    ? createDateTimeFormatter(locale.value, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : t('groups.members.roster.not_available');
</script>

<template>
    <div class="space-y-6 text-sm">
        <div v-if="loading" class="space-y-3" role="status" :aria-label="t('general.loading')">
            <USkeleton class="h-4 w-1/3" /><USkeleton class="h-16 w-full" />
        </div>
        <div v-else-if="error" class="flex items-center justify-between gap-3" role="alert">
            <p class="text-muted">{{ t('groups.members.notes.load_error') }}</p>
            <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" :aria-label="t('groups.activities.management.queue.modal.inspector.retry')" :title="t('groups.activities.management.queue.modal.inspector.retry')" @click="$emit('retry')" />
        </div>
        <template v-else-if="notes?.can_view">
            <section v-for="section in sections" :key="section.key" class="space-y-3">
                <div>
                    <h3 class="text-sm font-semibold">{{ t(`groups.members.notes.sections.${section.key}.title`) }}</h3>
                    <p class="text-sm text-muted">{{ t(`groups.members.notes.sections.${section.key}.subtitle`) }}</p>
                </div>
                <p v-if="!section.items.length" class="text-muted">{{ t(`groups.members.notes.sections.${section.key}.empty`) }}</p>
                <div class="flex flex-col gap-3">
                    <UCard v-for="note in section.items" :key="note.id" :class="['dark:bg-elevated/20', memberNotePresentation(note.severity).borderClass]">
                        <div class="flex flex-col gap-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div v-if="section.key === 'shared'" class="min-w-0">
                                    <p class="font-medium [overflow-wrap:anywhere]">{{ note.source_group?.name ?? t('groups.members.notes.unknown_group') }}</p>
                                    <p class="text-xs text-muted [overflow-wrap:anywhere]">{{ t('groups.members.notes.shared_from', { author: note.author?.name ?? t('audit_log.defaults.system'), date: date(note.created_at) }) }}</p>
                                </div>
                                <div v-else class="flex min-w-0 items-center gap-3">
                                    <UAvatar :src="note.author?.avatar_url ?? undefined" :alt="note.author?.name ?? t('audit_log.defaults.system')" icon="i-lucide-user" class="shrink-0" />
                                    <div class="min-w-0">
                                        <p class="font-medium [overflow-wrap:anywhere]">{{ note.author?.name ?? t('audit_log.defaults.system') }}</p>
                                        <p class="text-xs text-muted">{{ date(note.created_at) }}</p>
                                    </div>
                                </div>
                                <div class="flex max-w-full flex-wrap items-center gap-2">
                                    <UBadge :color="memberNotePresentation(note.severity).color" :class="memberNotePresentation(note.severity).badgeClass" :icon="memberNotePresentation(note.severity).icon" variant="subtle" :label="t(`groups.members.notes.severities.${note.severity}`)" />
                                    <UBadge v-if="section.key === 'current_group' && note.is_shared_with_groups" color="secondary" variant="soft" icon="i-lucide-globe" :label="t('general.shared')" />
                                </div>
                            </div>
                            <p class="whitespace-pre-wrap text-sm text-toned [overflow-wrap:anywhere]">{{ note.body }}</p>
                            <div v-if="note.addenda.length" class="space-y-3 border-l border-default pl-3">
                                <h4 class="text-xs font-semibold text-muted">{{ t('groups.members.notes.addenda.title') }}</h4>
                                <div v-for="addendum in note.addenda" :key="addendum.id" class="space-y-1">
                                    <p class="text-xs text-muted [overflow-wrap:anywhere]">{{ t('groups.members.notes.addenda.byline', { author: addendum.author?.name ?? t('audit_log.defaults.system'), date: date(addendum.created_at) }) }}</p>
                                    <p class="whitespace-pre-wrap text-sm text-toned [overflow-wrap:anywhere]">{{ addendum.body }}</p>
                                </div>
                            </div>
                        </div>
                    </UCard>
                </div>
            </section>
        </template>
        <p v-else class="text-muted">{{ t('groups.members.notes.hidden') }}</p>
        <section class="space-y-2 border-t border-default pt-4">
            <h3 class="text-xs font-medium text-muted">{{ t('groups.activities.management.queue.modal.inspector.application_note') }}</h3>
            <p class="whitespace-pre-line leading-relaxed [overflow-wrap:anywhere]">{{ applicationNote || t('groups.activities.management.queue.modal.no_notes') }}</p>
        </section>
    </div>
</template>
