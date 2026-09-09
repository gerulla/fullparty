<script setup lang="ts">
import { computed } from 'vue';
import { useMediaQuery } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import type { MemberNoteSeverity } from '@/Types/Groups';
import { memberNotePresentation } from '@/utils/memberNotePresentation';

const props = defineProps<{
    name: string
    avatarUrl?: string
    description?: string
    notesCount: number
    notesSeverity?: MemberNoteSeverity | null
}>();
defineEmits<{ close: [] }>();
const section = defineModel<string>({ required: true });
const { t } = useI18n();
const desktop = useMediaQuery('(min-width: 640px)');
const notesColor = computed(() => memberNotePresentation(props.notesSeverity ?? 'info').color);
const hasCriticalNotes = computed(() => props.notesCount > 0 && props.notesSeverity === 'critical');
const items = computed(() => [
    { value: 'application', label: t(`groups.activities.management.queue.modal.inspector.${desktop.value ? 'application' : 'details'}`), icon: 'i-lucide-sliders-horizontal' },
    { value: 'character', label: t('groups.activities.management.queue.modal.character'), icon: 'i-lucide-user-round' },
    { value: 'record', label: t('groups.activities.management.queue.modal.inspector.record'), icon: 'i-lucide-chart-no-axes-combined' },
    {
        value: 'notes', label: t('general.notes'), icon: 'i-lucide-sticky-note',
        ui: { trigger: hasCriticalNotes.value ? 'ring-1 ring-inset ring-error' : '' },
    },
]);
</script>

<template>
    <div class="flex min-h-0 flex-col overflow-hidden">
        <UTabs
            v-model="section"
            :items="items"
            :orientation="desktop ? 'vertical' : 'horizontal'"
            :unmount-on-hide="false"
            class="inspector-tabs"
            :ui="{
                root: 'grid min-h-0 flex-1 items-stretch gap-0 overflow-y-auto sm:grid-cols-[12rem_minmax(0,1fr)] sm:overflow-hidden',
                list: 'inspector-rail relative grid grid-cols-4 items-start gap-1 rounded-none border-b border-default bg-elevated/40 p-3 sm:flex sm:flex-col sm:gap-1 sm:border-b-0 sm:border-r sm:p-4 sm:overflow-y-auto',
                indicator: 'hidden',
                trigger: 'min-w-0 flex-none justify-center gap-2 rounded-sm px-1 py-2.5 text-xs font-normal whitespace-normal data-[state=active]:bg-default data-[state=active]:text-highlighted sm:w-full sm:justify-start sm:px-3 sm:text-sm',
                leadingIcon: 'hidden size-4 shrink-0 sm:block',
                label: 'whitespace-normal break-words',
                content: 'min-w-0 p-5 outline-none sm:overflow-y-auto sm:p-6',
            }"
        >
            <template #list-leading>
                <div class="col-span-4 flex min-w-0 items-center gap-3 pb-3 sm:mb-3 sm:block sm:w-full sm:pb-0">
                    <UAvatar :src="avatarUrl" :alt="name" size="lg" class="shrink-0 sm:mb-3" />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-highlighted [overflow-wrap:anywhere]">{{ name }}</p>
                        <p v-if="description" class="mt-1 text-xs text-muted [overflow-wrap:anywhere]">{{ description }}</p>
                        <slot name="identity" />
                    </div>
                    <UButton class="ml-auto shrink-0 sm:hidden" icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="t('general.close')" :title="t('general.close')" @click="$emit('close')" />
                </div>
            </template>
            <template #trailing="{ item }">
                <UBadge v-if="item.value === 'notes' && notesCount > 0" :label="String(notesCount)" size="xs" :color="notesColor" variant="soft" class="shrink-0 sm:ml-auto" />
            </template>
            <template #list-trailing>
                <div class="hidden w-full pt-6 text-xs sm:block"><slot name="metadata" /></div>
            </template>
            <template #content="{ item }">
                <header class="mb-5 flex items-center justify-between gap-3">
                    <h2 class="text-base font-medium text-highlighted">{{ item.value === 'application' ? t('groups.activities.management.queue.modal.inspector.application') : item.label }}</h2>
                    <UButton class="hidden sm:inline-flex" icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="t('general.close')" :title="t('general.close')" @click="$emit('close')" />
                </header>
                <slot :name="item.value" />
            </template>
        </UTabs>
        <footer class="shrink-0 border-t border-default px-4 py-3 sm:px-5"><slot name="footer" /></footer>
    </div>
</template>

<style scoped>
.inspector-tabs { height: 34rem; }
@media (max-width: 639px) {
    .inspector-tabs { display: block; }
}
</style>
