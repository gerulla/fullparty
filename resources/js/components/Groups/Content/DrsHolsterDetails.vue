<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BozjaHolsterDraft, BozjaHolsterSummary } from '@/Types/Bozja'
import LocalizedTextFields from '@/components/Admin/ActivityTypes/LocalizedTextFields.vue'
import { holsterRoles } from '@/utils/bozjaHolsterRoles'

const draft = defineModel<BozjaHolsterDraft>({ required: true })
const props = defineProps<{ holsters: BozjaHolsterSummary[]; selectedId: number | null; errors: Record<string, string>; busy: boolean; exceeded: boolean; dirty: boolean }>()
const emit = defineEmits<{ save: [] }>()
const { t } = useI18n()
const k = 'groups.index.content.delubrum_reginae_savage.holsters.'
const roles = computed(() => holsterRoles.map(role => ({ value: role.value, label: t(role.label) })))
const types = computed(() => ['prepop', 'refill'].map(value => ({ value, label: t(k + 'types.' + value) })))
const parents = computed(() => props.holsters.filter(h => h.type === 'prepop' && h.id !== props.selectedId).map(h => ({ value: h.id, label: h.display_name || t(k + 'untitled') })))
</script>

<template>
    <form class="drs-panel flex min-w-0 flex-col p-5 xl:min-h-[42rem]" @submit.prevent="emit('save')">
        <h2 class="mb-5 flex items-center gap-2 border-b border-default pb-4 text-lg font-semibold"><UIcon name="i-lucide-settings" class="size-5" />{{ t(k + 'workspace.details') }}</h2>
        <fieldset :disabled="busy" class="min-w-0 space-y-5">
            <LocalizedTextFields v-model="draft.name" :locales="['en', 'de', 'fr', 'ja']" :label="t(k + 'metadata.name')" :all-locales-label="t(k + 'manage_locales')" :maxlength="255" :disabled="busy" :error="Object.entries(errors).find(([key]) => key === 'name' || key.startsWith('name.'))?.[1]" rounded />
            <UFormField :label="t(k + 'metadata.role')" :error="errors.role" required><USelect v-model="draft.role" :items="roles" class="w-full min-w-0" /></UFormField>
            <UFormField :label="t(k + 'metadata.type')" :error="errors.type" required><USelect v-model="draft.type" :items="types" class="w-full min-w-0" /></UFormField>
            <UFormField v-if="draft.type === 'refill'" :label="t(k + 'metadata.parent_holster')" :error="errors.parent_holster_id" required>
                <USelect v-model="draft.parent_holster_id" :items="parents" :placeholder="t(k + 'metadata.parent_holster_placeholder')" class="w-full min-w-0"
                    :content="{ position: 'popper', collisionPadding: 12 }"
                    :ui="{ value: 'min-w-0 truncate', content: 'w-(--reka-select-trigger-width) max-w-[calc(100vw-1.5rem)]', itemLabel: 'whitespace-normal break-words' }" />
            </UFormField>
            <UFormField :label="t('general.notes')" :error="errors.notes"><UTextarea v-model="draft.notes" :rows="5" :maxlength="5000" class="w-full" /></UFormField>
        </fieldset>
        <div class="mt-auto pt-8">
            <p v-if="dirty" class="mb-2 text-xs text-muted">{{ t(k + 'workspace.unsaved') }}</p>
            <UButton type="submit" icon="i-lucide-save" :label="t(k + 'workspace.save')" :loading="busy" :disabled="exceeded" block size="lg" class="rounded-md" />
        </div>
    </form>
</template>
