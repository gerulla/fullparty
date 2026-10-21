<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const page = usePage()
const { t } = useI18n()
</script>

<template>
    <div class="w-full space-y-2 rounded-lg bg-elevation-2 p-4 text-left dark:bg-elevation-4" role="status">
        <p class="text-sm font-semibold text-highlighted">{{ t('characters.transfer.title') }}</p>
        <p class="text-sm leading-relaxed text-muted">{{ t('characters.transfer.description') }}</p>
        <UButton v-if="page.props.site_links?.discord" :href="page.props.site_links.discord"
            target="_blank" rel="noopener noreferrer" color="neutral" variant="ghost"
            icon="ic:baseline-discord" trailing-icon="i-lucide-arrow-up-right" class="rounded-md">
            {{ t('characters.claim_flow.support_discord') }}
        </UButton>
    </div>
</template>
