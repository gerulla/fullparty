<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { SettingsUser } from '@/Types/Settings'

const props = defineProps<{ user: SettingsUser }>()
const { t } = useI18n()
const toast = useToast()
const form = useForm({ page_transition_enabled: props.user.page_transition_enabled ?? true })

watch(() => props.user.page_transition_enabled, enabled => {
    form.page_transition_enabled = enabled ?? true
})

function save(enabled: boolean) {
    if (form.processing) return
    form.page_transition_enabled = enabled
    form.patch(route('settings.page-transition'), {
        preserveScroll: true,
        onSuccess: () => toast.add({
            title: t('settings.toasts.title'),
            description: t('settings.page_transition.saved'),
            color: 'success',
            icon: 'i-lucide-check',
        }),
        onError: () => {
            form.page_transition_enabled = props.user.page_transition_enabled ?? true
            toast.add({
                title: t('settings.toasts.error_title'),
                description: t('settings.page_transition.save_failed'),
                color: 'error',
                icon: 'i-lucide-triangle-alert',
            })
        },
    })
}
</script>

<template>
    <UCard class="w-full rounded-lg bg-elevation-1 dark:bg-elevation-2">
        <template #header>
            <div class="flex items-center gap-2 font-semibold">
                <UIcon name="i-lucide-sparkles" class="size-5" />
                <h3>{{ t('settings.page_transition.title') }}</h3>
            </div>
        </template>
        <USwitch :model-value="form.page_transition_enabled" :disabled="form.processing"
            color="brand" :label="t('settings.page_transition.label')"
            :description="t('settings.page_transition.description')"
            class="w-full [--ui-brand:var(--color-brand-700)] dark:[--ui-brand:var(--color-brand-400)]"
            :ui="{ label: 'font-semibold text-highlighted', description: 'mt-1 text-sm text-muted' }"
            @update:model-value="save" />
    </UCard>
</template>
