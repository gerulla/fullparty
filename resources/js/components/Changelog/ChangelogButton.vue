<script setup lang="ts">
import { defineAsyncComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChangelog } from '@/composables/useChangelog'

const { collapsed, appearance = 'sidebar' } = defineProps<{ collapsed: boolean; appearance?: 'sidebar' | 'footer' }>()
const { t } = useI18n()
const activated = ref(false)
const ChangelogModal = defineAsyncComponent(() => import('./ChangelogModal.vue'))
const { isOpen, loading, error, article, history, historyOpen, historyLoading, hasMore, unread, open, close, load, older } = useChangelog()
</script>

<template>
    <button type="button" class="relative inline-flex min-w-0 items-center gap-1 transition-colors focus-visible:outline-2 focus-visible:outline-primary"
        :class="appearance === 'footer' ? 'min-h-8 gap-1.5 rounded-sm text-sm text-muted hover:text-primary focus-visible:outline-offset-4' : 'text-[10px] uppercase tracking-wide text-brand-100/60 hover:text-brand-100'"
        :title="t('changelog.title')" :aria-label="unread ? t('changelog.unread') : t('changelog.title')" aria-haspopup="dialog" @click="activated = true; open()">
        <span v-if="!collapsed" class="truncate">{{ t('changelog.title') }}</span>
        <UIcon name="i-lucide-info" class="size-3.5 shrink-0" />
        <span v-if="unread" class="absolute -right-1 -top-1 size-1.5 rounded-full bg-primary" />
    </button>
    <ChangelogModal v-if="activated" :open="isOpen" :loading="loading" :error="error" :article="article" :history="history" :history-open="historyOpen" :history-loading="historyLoading" :has-more="hasMore" @close="close" @load="load" @older="older" />
</template>
