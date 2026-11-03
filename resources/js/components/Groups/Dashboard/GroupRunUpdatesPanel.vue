<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NotificationRecord } from '@/Types/Notifications'
import { formatNotificationTime, resolveNotificationDescription, resolveNotificationMeta, resolveNotificationTitle } from '@/utils/notificationPresentation'

const props = defineProps<{ notifications: NotificationRecord[] }>()
const { t, locale } = useI18n()
const items = computed(() => props.notifications.map(notification => ({
    ...notification,
    meta: resolveNotificationMeta(notification),
})))
</script>

<template>
    <section aria-labelledby="group-run-updates-title" class="flex min-w-0 flex-col gap-4">
        <h2 id="group-run-updates-title" class="text-lg font-semibold text-highlighted">
            {{ t('groups.dashboard.staff_home.run_updates') }}
        </h2>
        <div class="relative flex min-h-48 flex-1 flex-col overflow-hidden rounded-lg border border-default bg-elevated/20 md:min-h-0">
            <div v-if="notifications.length" class="max-h-80 overflow-y-auto overscroll-contain md:absolute md:inset-0 md:max-h-none">
                <Link v-for="notification in items" :key="notification.id" :href="notification.open_url"
                    class="flex gap-3 border-b border-l-2 border-default p-4 transition-colors last:border-b-0 hover:bg-elevated focus-visible:bg-elevated"
                    :class="notification.meta.surfaceClass ?? (notification.is_unread ? 'border-l-brand bg-brand/5' : 'border-l-transparent')">
                    <UIcon :name="notification.meta.icon"
                        :class="notification.meta.iconColor" class="mt-0.5 size-5 shrink-0" />
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="text-sm font-medium text-highlighted [overflow-wrap:anywhere]">{{ resolveNotificationTitle(notification, t) }}</p>
                        <p v-if="notification.body_key" class="text-xs leading-5 text-toned [overflow-wrap:anywhere]">{{ resolveNotificationDescription(notification, t) }}</p>
                        <time :datetime="notification.created_at ?? undefined" class="block text-xs text-muted">
                            {{ formatNotificationTime(notification.created_at, locale, t) }}
                        </time>
                    </div>
                    <span v-if="notification.is_unread" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-current"
                        :class="notification.meta.iconColor" :aria-label="t('notifications.ui.new_badge')" />
                </Link>
            </div>
            <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-5 text-center">
                <UIcon name="i-lucide-bell" class="size-6 text-muted" />
                <p class="max-w-xs text-sm leading-6 text-muted">{{ t('groups.dashboard.staff_home.no_updates') }}</p>
            </div>
        </div>
    </section>
</template>
