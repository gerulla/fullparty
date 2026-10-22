<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ name: string; uploadPercent: number | null }>()
const { t } = useI18n()
const heading = ref<HTMLElement | null>(null)
const uploading = computed(() => props.uploadPercent !== null && props.uploadPercent < 100)
onMounted(() => heading.value?.focus({ preventScroll: true }))
</script>

<template>
    <section role="status" aria-live="polite" aria-busy="true"
        class="mx-auto flex min-h-96 max-w-md flex-col items-center justify-center gap-7 py-12 text-center sm:min-h-112">
        <div class="relative flex size-24 items-center justify-center" aria-hidden="true">
            <span class="absolute inset-0 rounded-full border-4 border-brand-200 dark:border-brand-950" />
            <span class="absolute inset-0 animate-spin rounded-full border-4 border-transparent border-t-brand-600 motion-reduce:animate-none dark:border-t-brand-400" />
            <UIcon name="i-lucide-users" class="size-10 text-brand-700 dark:text-brand-300" />
        </div>
        <div class="space-y-3">
            <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">{{ t('groups.index.create_flow.submission.loading_title') }}</h1>
            <p class="break-words text-base font-medium text-toned [overflow-wrap:anywhere]">{{ name }}</p>
            <p class="text-sm leading-relaxed text-muted">
                {{ t(`groups.index.create_flow.submission.${uploading ? 'uploading' : 'creating'}`) }}
            </p>
        </div>
        <UProgress v-if="uploading" :model-value="uploadPercent" color="brand" size="xs" class="max-w-56"
            :get-value-label="() => t('groups.index.create_flow.submission.uploading')" />
        <p class="text-xs text-muted">{{ t('groups.index.create_flow.submission.keep_open') }}</p>
    </section>
</template>
