<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceLinkButton } from '@/Types/GroupResources'
import { MAX_RESOURCE_LINK_BUTTONS } from '@/utils/resourceWorkspace'

const props = defineProps<{ commandIndex: number; fieldError?: (path: string) => string | undefined }>()
const buttons = defineModel<ResourceLinkButton[]>({ required: true })
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const path = (key = '') => `commands.${props.commandIndex}.buttons${key ? `.${key}` : ''}`
const editor = ref<HTMLElement>()

async function addButton() {
    if (buttons.value.length >= MAX_RESOURCE_LINK_BUTTONS) return
    buttons.value = [...buttons.value, { label: '', url: '' }]
    await nextTick()
    editor.value?.lastElementChild?.querySelector('input')?.focus()
}

function updateButton(index: number, key: keyof ResourceLinkButton, value: string) {
    buttons.value = buttons.value.map((button, position) => position === index ? { ...button, [key]: value } : button)
}

function moveButton(index: number, offset: number) {
    const target = index + offset
    if (target < 0 || target >= buttons.value.length) return
    const next = [...buttons.value]
    const [button] = next.splice(index, 1)
    next.splice(target, 0, button)
    buttons.value = next
}
</script>

<template>
    <section>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">{{ l('link_buttons') }} <span class="text-xs font-normal text-muted">{{ buttons.length }}/{{ MAX_RESOURCE_LINK_BUTTONS }}</span></h3>
            <UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="l('add_button')" :disabled="buttons.length >= MAX_RESOURCE_LINK_BUTTONS" @click="addButton" />
        </div>
        <p class="text-sm text-muted">{{ t('groups.resources.workspace.embed_help.buttons', { max: MAX_RESOURCE_LINK_BUTTONS }) }}</p>
        <p v-if="fieldError?.(path())" :data-resource-field="path()" role="alert" class="text-sm text-error">{{ fieldError?.(path()) }}</p>
        <div ref="editor" class="space-y-4">
            <div v-for="(button, index) in buttons" :key="index" class="space-y-3 border-l-2 border-accented pl-3.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs text-muted">{{ t('groups.resources.workspace.embed_button_number', { number: index + 1 }) }}</span>
                    <div class="flex gap-1">
                        <UTooltip :text="l('move_up')"><UButton icon="i-lucide-arrow-up" color="neutral" variant="ghost" size="xs" :aria-label="l('move_up')" :disabled="index === 0" @click="moveButton(index, -1)" /></UTooltip>
                        <UTooltip :text="l('move_down')"><UButton icon="i-lucide-arrow-down" color="neutral" variant="ghost" size="xs" :aria-label="l('move_down')" :disabled="index === buttons.length - 1" @click="moveButton(index, 1)" /></UTooltip>
                        <UTooltip :text="l('delete')"><UButton icon="i-lucide-trash-2" color="error" variant="outline" size="xs" :aria-label="l('delete')" @click="buttons = buttons.filter((_, position) => position !== index)" /></UTooltip>
                    </div>
                </div>
                <UFormField :name="path(`${index}.label`)" :data-resource-field="path(`${index}.label`)" :label="l('button_label')" :error="fieldError?.(path(`${index}.label`))" :hint="`${Array.from(button.label).length}/80`" required>
                    <UInput :model-value="button.label" :maxlength="80" class="w-full" @update:model-value="updateButton(index, 'label', String($event))" />
                </UFormField>
                <UFormField :name="path(`${index}.url`)" :data-resource-field="path(`${index}.url`)" :label="l('button_url')" :error="fieldError?.(path(`${index}.url`))" required>
                    <UInput :model-value="button.url" type="url" :maxlength="512" placeholder="https://" class="w-full" @update:model-value="updateButton(index, 'url', String($event))" />
                </UFormField>
            </div>
        </div>
    </section>
</template>
