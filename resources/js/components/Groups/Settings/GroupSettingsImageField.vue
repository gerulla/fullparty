<script setup lang="ts">
import { useId } from 'vue'
import { groupProfilePictureAccept } from '@/utils/groupProfilePictureValidation'

defineProps<{
    label: string
    help: string
    actionLabel: string
    previewUrl: string | null
    previewAlt: string
    filename?: string
    error?: string
    disabled?: boolean
    wide?: boolean
}>()
const emit = defineEmits<{ change: [event: Event] }>()
const inputId = useId()
</script>

<template>
    <div class="min-w-0 space-y-3">
        <label :for="inputId" class="block text-sm font-medium text-highlighted">{{ label }}</label>
        <div class="flex items-center justify-center overflow-hidden rounded-lg border border-default bg-muted/30"
            :class="wide ? 'aspect-[15/4] w-full' : 'size-28'">
            <img v-if="previewUrl" :src="previewUrl" :alt="previewAlt" class="h-full w-full object-cover object-center">
            <UIcon v-else :name="wide ? 'i-lucide-image' : 'i-lucide-users'" class="size-8 text-dimmed" aria-hidden="true" />
        </div>
        <label :for="inputId"
            class="relative flex min-h-10 w-fit max-w-full items-center gap-2 rounded-lg border border-default bg-default px-3 py-2 text-sm font-medium text-toned transition-colors focus-within:ring-2 focus-within:ring-brand"
            :class="disabled ? 'opacity-60' : 'cursor-pointer hover:bg-elevated hover:text-highlighted'">
            <UIcon name="i-lucide-upload" class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 break-words">{{ actionLabel }}</span>
            <input :id="inputId" class="sr-only" type="file" :accept="groupProfilePictureAccept"
                :aria-label="label" :aria-describedby="`${inputId}-help${error ? ` ${inputId}-error` : ''}`"
                :aria-invalid="error ? 'true' : undefined" :disabled="disabled" @change="emit('change', $event)">
        </label>
        <p v-if="filename" class="break-all text-xs text-toned">{{ filename }}</p>
        <p :id="`${inputId}-help`" class="text-xs leading-relaxed text-muted">{{ help }}</p>
        <p v-if="error" :id="`${inputId}-error`" role="alert" class="text-sm text-error">{{ error }}</p>
    </div>
</template>
