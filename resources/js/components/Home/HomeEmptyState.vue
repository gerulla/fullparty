<script setup lang="ts">
defineProps<{
    title: string
    description?: string
    actionLabel: string
    actionTo: string
    spacious?: boolean
}>()
</script>

<template>
    <div class="flex w-full flex-col items-center justify-center gap-4 text-center"
        :class="spacious ? 'min-h-80 px-6 py-10 sm:py-12' : 'min-h-36 px-2 py-6'">
        <slot name="illustration" />
        <div class="max-w-xs">
            <h3 class="text-base font-semibold text-default">{{ title }}</h3>
            <p v-if="description" class="mt-2 text-sm leading-relaxed text-muted">{{ description }}</p>
        </div>
        <UButton :to="actionTo" color="neutral" variant="solid" size="md"
            class="max-w-full justify-center rounded-full px-5 py-2 text-center font-semibold whitespace-normal">
            {{ actionLabel }}
        </UButton>
    </div>
</template>
