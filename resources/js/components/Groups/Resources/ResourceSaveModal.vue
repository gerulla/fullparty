<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'

defineProps<{ workspace: ResourceWorkspaceController }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
</script>

<template>
    <UModal v-model:open="workspace.state.saveDialog" :title="l('save_changes')" :description="l('summary_help')" :dismissible="!workspace.busy" :close="!workspace.busy" :ui="{ content: 'rounded-none' }">
        <template #body>
            <form class="space-y-5" @submit.prevent="workspace.confirmSave()">
                <UAlert v-if="workspace.state.error" :title="workspace.state.error" color="error" variant="soft" />
                <UFormField name="summary" data-resource-field="summary" :error="workspace.fieldError('summary')" :label="l('change_summary')" required>
                    <UInput v-model="workspace.state.summary" autofocus required :maxlength="300" :disabled="workspace.busy" class="w-full" :ui="{ base: 'rounded-none' }" />
                </UFormField>
                <div class="flex justify-end gap-2">
                    <UButton color="neutral" variant="outline" class="rounded-none" :label="l('cancel')" :disabled="workspace.busy" @click="workspace.state.saveDialog = false" />
                    <UButton icon="i-lucide-save" type="submit" class="rounded-none" :label="l('save')" :loading="workspace.busy" :disabled="!workspace.state.summary.trim()" />
                </div>
            </form>
        </template>
    </UModal>
</template>
