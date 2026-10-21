<script setup lang="ts">
import type { NotificationRecord } from '@/Types/Notifications'
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
} from '@/utils/notificationPresentation'

declare global {
	interface Window {
		Echo?: {
			private: (channel: string) => {
				listen: (event: string, callback: (payload: unknown) => void) => unknown
			}
			leave?: (channel: string) => void
		}
	}
}

const page = usePage()
defineProps<{ touchTarget?: boolean }>()
const { t, locale } = useI18n()

const user = computed(() => page.props.auth?.user ?? null)
const unreadCount = ref(Number(page.props.notifications?.unread_count ?? 0))
const latestNotifications = ref<NotificationRecord[]>(
	(page.props.notifications?.latest ?? []) as NotificationRecord[],
)
const isPolling = ref(false)
const subscribedChannelName = ref<string | null>(null)

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

const subscribeToNotificationsChannel = (userId: number) => {
	if (!window.Echo) {
		return
	}

	const channelName = `App.Models.User.${userId}`

	if (subscribedChannelName.value === channelName) {
		return
	}

	if (subscribedChannelName.value && window.Echo.leave) {
		window.Echo.leave(subscribedChannelName.value)
	}

	window.Echo.private(channelName)
		.listen('.notifications.updated', () => {
			void refreshNotificationSummary()
		})

	subscribedChannelName.value = channelName
}

const unsubscribeFromNotificationsChannel = () => {
	if (subscribedChannelName.value && window.Echo?.leave) {
		window.Echo.leave(subscribedChannelName.value)
	}

	subscribedChannelName.value = null
}

const markAllAsRead = () => {
	router.post(route('account.notifications.read-all'), {}, {
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

const handleBellClick = () => {
	void refreshNotificationSummary()
}

watch(() => page.props.notifications, () => {
	syncFromPageProps()
}, { deep: true })

watch(user, (nextUser) => {
	if (nextUser) {
		syncFromPageProps()
		subscribeToNotificationsChannel(Number(nextUser.id))
		void refreshNotificationSummary()
		return
	}

	unsubscribeFromNotificationsChannel()
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
	unsubscribeFromNotificationsChannel()
})
</script>

<template>
	<UPopover
		v-if="user"
		arrow
		:content="{ side: 'bottom', align: 'middle', collisionPadding: 8 }"
		:ui="{ content: 'bg-elevation-1 ring-default', arrow: 'fill-elevation-1 stroke-default' }"
	>
		<template #default="{ open }">
			<UButton
				color="neutral"
				variant="ghost"
				:class="{ 'bg-elevation-1 rounded-md': open, 'size-11 justify-center': touchTarget }"
				class="relative rounded-md"
				icon="i-lucide-bell"
                :aria-label="t('navigation.sidebar.notifications')"
				@click="handleBellClick"
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
			<div class="w-96 max-w-[calc(100vw-1rem)] rounded-md text-default">
				<div class="max-h-96 overflow-y-auto rounded-t-md">
					<div
						v-if="latestNotifications.length === 0"
						class="px-4 py-8 text-center text-sm text-muted"
					>
						{{ t('notifications.ui.empty') }}
					</div>

					<Link
						v-for="notification in latestNotifications"
						:key="notification.id"
						:href="notification.open_url"
						class="block px-4 py-3 transition-colors border-b border-muted last:border-b-0"
						:class="notification.is_unread
                            ? 'border-l-4 border-l-primary bg-elevation-2 dark:bg-elevation-3 hover:bg-elevation-4 focus-visible:bg-elevation-7'
                            : 'bg-elevation-1 hover:bg-elevation-3 focus-visible:bg-elevation-5'"
					>
						<div class="flex gap-3">
							<div class="flex-shrink-0 mt-0.5">
								<div class="h-8 w-8 rounded-full bg-elevation-3 dark:bg-elevation-7 flex items-center justify-center">
									<UIcon
										:name="resolveNotificationMeta(notification).icon"
										:class="[resolveNotificationMeta(notification).iconColor, 'h-4 w-4']"
									/>
								</div>
							</div>

							<div class="flex-1 min-w-0">
								<div class="flex items-start justify-between gap-2">
									<p class="text-sm font-medium text-highlighted">
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
									class="mt-1 text-sm text-default dark:text-muted line-clamp-2"
								>
									{{ resolveNotificationDescription(notification, t) }}
								</p>

								<p class="mt-1 text-xs text-default dark:text-muted">
									{{ formatNotificationTime(notification.created_at, locale, t) }}
								</p>
							</div>
						</div>
					</Link>
				</div>

				<div class="px-4 py-1.5 rounded-b-md border-t border-default dark:bg-elevation-2 flex items-center justify-between">
					<Link
						:href="route('account.notifications.index')"
						class="text-sm font-medium text-brand hover:text-highlighted hover:underline focus-visible:underline transition-colors"
					>
						{{ t('notifications.ui.view_all') }}
					</Link>
					<UButton
						size="xs"
						color="neutral"
						variant="ghost"
						class="hover:bg-elevation-2"
						@click="markAllAsRead"
					>
						{{ t('notifications.ui.mark_all_as_read') }}
					</UButton>

					<!--					v-if="unreadCount > 0"-->
				</div>
			</div>
		</template>
	</UPopover>
</template>
