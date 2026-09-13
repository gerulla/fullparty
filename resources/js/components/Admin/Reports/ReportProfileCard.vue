<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ReportProfile } from '@/Types/Reports'
defineProps<{ profile: ReportProfile | null; label: string; guestReference?: string | null }>()
const { t, locale } = useI18n()
</script>

<template>
    <section class="min-w-0 space-y-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ label }}</h3>
        <template v-if="profile">
            <div class="flex gap-3 items-center min-w-0">
                <UAvatar :src="profile.avatar_url ?? undefined" :alt="profile.name" size="lg" />
                <div class="min-w-0"><p class="font-semibold break-words">{{ profile.name }}</p><p class="text-xs text-muted">{{ t('reports.admin.account') }} #{{ profile.id }}</p></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <UBadge :color="profile.banned_at ? 'error' : 'success'" variant="subtle" size="sm">{{ t('reports.admin.' + (profile.banned_at ? 'banned' : 'active')) }}</UBadge>
                <UBadge v-if="profile.is_admin" color="primary" variant="subtle" size="sm">{{ t('reports.admin.administrator') }}</UBadge>
                <UBadge color="neutral" variant="subtle" size="sm">{{ t('reports.admin.' + (profile.public_profile ? 'public_profile' : 'private_profile')) }}</UBadge>
            </div>
            <p v-if="profile.created_at" class="text-xs text-muted">{{ t('reports.admin.joined', { date: new Date(profile.created_at).toLocaleDateString(locale) }) }}</p>
            <p v-if="profile.description" class="text-sm whitespace-pre-wrap break-words">{{ profile.description }}</p>
            <UCollapsible v-if="profile.characters.length" :default-open="profile.characters.length <= 3">
                <UButton color="neutral" variant="link" size="xs" trailing-icon="i-lucide-chevron-down" class="px-0">{{ t('reports.admin.characters', { count: profile.characters.length }) }}</UButton>
                <template #content>
                    <div class="space-y-2 pt-2">
                        <div v-for="character in profile.characters" :key="character.id" class="flex items-center gap-2">
                            <UAvatar :src="character.avatar_url ?? undefined" :alt="character.name" size="sm" />
                            <div class="min-w-0 text-xs"><p class="font-medium break-words">{{ character.name }} <UIcon v-if="character.is_primary" name="i-lucide-star" class="text-primary" :aria-label="t('reports.admin.primary_character')" /></p><p class="text-muted">{{ character.world }} · {{ character.datacenter }}</p></div>
                        </div>
                    </div>
                </template>
            </UCollapsible>
        </template>
        <div v-else-if="guestReference" class="space-y-2">
            <p class="font-semibold flex items-center gap-2"><UIcon name="i-lucide-user-round" />{{ t('reports.admin.guest_reporter') }}</p>
            <code class="text-xs text-muted">{{ guestReference }}</code>
            <p class="text-xs text-muted">{{ t('reports.admin.guest_reference_help') }}</p>
        </div>
        <p v-else class="text-sm text-muted">{{ t('reports.admin.deleted_user') }}</p>
    </section>
</template>
