<script setup lang="ts">
import axios from 'axios'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import {
	formatNotificationTime,
	resolveNotificationDescription,
	resolveNotificationMeta,
	resolveNotificationTitle,
	type NotificationRecord,
} from '@/utils/notificationPresentation'

const page = usePage()
const { t, locale } = useI18n()
const POLL_INTERVAL_MS = 15000

const user = computed(() => page.props.auth?.user ?? null)
const unreadCount = ref(Number(page.props.notifications?.unread_count ?? 0))
const latestNotifications = ref<NotificationRecord[]>(
	(page.props.notifications?.latest ?? []) as NotificationRecord[],
)
const isPolling = ref(false)
let pollHandle: number | null = null

const syncFromPageProps = () => {
	unreadCount.value = Number(page.props.notifications?.unread_count ?? 0)
	latestNotifications.value = (page.props.notifications?.latest ?? []) as NotificationRecord[]
}

const refreshNotificationSummary = async () => {
	if (!user.value || isPolling.value || document.visibilityState === 'hidden') {
		return
	}

	isPolling.value = true

	try {
		const response = await axios.get(route('account.notifications.summary'))

		unreadCount.value = Number(response.data.unread_count ?? 0)
		latestNotifications.value = (response.data.latest ?? []) as NotificationRecord[]
	} finally {
		isPolling.value = false
	}
}

const startPolling = () => {
	if (pollHandle !== null || !user.value) {
		return
	}

	pollHandle = window.setInterval(() => {
		void refreshNotificationSummary()
	}, POLL_INTERVAL_MS)
}

const stopPolling = () => {
	if (pollHandle !== null) {
		window.clearInterval(pollHandle)
		pollHandle = null
	}
}

const markAllAsRead = () => {
	router.post(route('account.notifications.read-all'), {}, {
		preserveScroll: true,
		preserveState: true,
		onSuccess: () => {
			unreadCount.value = 0
			latestNotifications.value = latestNotifications.value.map((notification) => ({
				...notification,
				read_at: notification.read_at ?? new Date().toISOString(),
				is_unread: false,
			}))
		},
	})
}

watch(() => page.props.notifications, () => {
	syncFromPageProps()
}, { deep: true })

watch(user, (nextUser) => {
	if (nextUser) {
		syncFromPageProps()
		startPolling()
		void refreshNotificationSummary()
		return
	}

	stopPolling()
}, { immediate: true })

const handleVisibilityChange = () => {
	if (document.visibilityState === 'visible') {
		void refreshNotificationSummary()
	}
}

onMounted(() => {
	document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', handleVisibilityChange)
	stopPolling()
})
</script>

<template>
	<UPopover
		v-if="user"
		:popper="{ placement: 'bottom-end' }"
	>
		<template #default="{ open }">
			<UButton
				color="neutral"
				variant="ghost"
				:class="{ 'bg-gray-100 dark:bg-gray-800': open }"
				class="relative"
				icon="i-lucide-bell"
			>
				<UChip
					v-if="unreadCount > 0"
					:text="unreadCount.toString()"
					color="error"
					size="3xl"
					class="absolute top-1 right-2"
				/>
			</UButton>
		</template>

		<template #content>
			<div class="w-96 max-w-md">
				<div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-800">
					<h3 class="text-sm font-semibold text-gray-900 dark:text-white">
						{{ t('notifications.ui.title') }}
					</h3>
					<UButton
						v-if="unreadCount > 0"
						size="xs"
						color="neutral"
						variant="ghost"
						@click="markAllAsRead"
					>
						{{ t('notifications.ui.mark_all_as_read') }}
					</UButton>
				</div>

				<div class="max-h-96 overflow-y-auto">
					<div
						v-if="latestNotifications.length === 0"
						class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
					>
						{{ t('notifications.ui.empty') }}
					</div>

					<Link
						v-for="notification in latestNotifications"
						:key="notification.id"
						:href="notification.open_url"
						class="block px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border-b border-gray-100 dark:border-gray-800 last:border-b-0"
						:class="{ 'bg-blue-50/50 dark:bg-blue-950/20': notification.is_unread }"
					>
						<div class="flex gap-3">
							<div class="flex-shrink-0 mt-0.5">
								<div class="h-8 w-8 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
									<UIcon
										:name="resolveNotificationMeta(notification).icon"
										:class="[resolveNotificationMeta(notification).iconColor, 'h-4 w-4']"
									/>
								</div>
							</div>

							<div class="flex-1 min-w-0">
								<div class="flex items-start justify-between gap-2">
									<p class="text-sm font-medium text-gray-900 dark:text-white">
										{{ resolveNotificationTitle(notification, t) }}
									</p>
									<UBadge
										v-if="notification.is_unread"
										color="primary"
										variant="soft"
										size="xs"
										class="flex-shrink-0"
									>
										{{ t('notifications.ui.new_badge') }}
									</UBadge>
								</div>

								<p
									v-if="resolveNotificationDescription(notification, t)"
									class="mt-1 text-sm text-gray-600 dark:text-gray-400 line-clamp-2"
								>
									{{ resolveNotificationDescription(notification, t) }}
								</p>

								<p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
									{{ formatNotificationTime(notification.created_at, locale, t) }}
								</p>
							</div>
						</div>
					</Link>
				</div>

				<div class="px-4 py-3 border-t border-gray-200 dark:border-gray-800">
					<Link
						:href="route('account.notifications.index')"
						class="text-sm font-medium text-brand hover:text-brand-600 dark:text-brand-400 dark:hover:text-brand-300 transition-colors"
					>
						{{ t('notifications.ui.view_all') }}
					</Link>
				</div>
			</div>
		</template>
	</UPopover>
</template>

<style scoped>

</style>
