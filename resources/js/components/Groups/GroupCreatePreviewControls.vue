<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CreateGroupModal from '@/components/Groups/CreateGroupModal.vue'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import { useDevelopmentPreviewMenu } from '@/composables/useDevelopmentPreviewMenu'

const { t } = useI18n()
const { close } = useDevelopmentPreviewMenu()
const modal = ref<InstanceType<typeof CreateGroupModal> | null>(null)
const steps = [1, 2, 3, 4, 5]

async function openStep(step: number) {
    close()
    await nextTick()
    modal.value?.open(step)
}
</script>

<template>
    <DevelopmentPreviewMenu>
        <p class="px-2 text-xs leading-relaxed text-muted">{{ t('groups.index.create_preview.help') }}</p>
        <UButton v-for="step in steps" :key="step" color="neutral" variant="ghost"
            class="justify-start rounded-md" @click="openStep(step)">
            {{ step }}. {{ t(`groups.index.create_modal.steps.${step}`) }}
        </UButton>
    </DevelopmentPreviewMenu>
    <CreateGroupModal ref="modal" preview :show-trigger="false" />
</template>
