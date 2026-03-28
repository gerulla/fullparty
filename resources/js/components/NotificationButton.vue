<script setup lang="ts">

// Notification system
import {computed, ref} from "vue";

interface Notification {
	id: number
	title: string
	description?: string
	icon: string
	iconColor?: string
	time: string
	read: boolean
	href?: string
}

// Mock notifications - replace with actual data from your backend
const notifications = ref<Notification[]>([
	{
		id: 1,
		title: "New application received",
		description: "John Doe applied to your static group",
		icon: "i-lucide-user-plus",
		iconColor: "text-blue-500",
		time: "2 min ago",
		read: false,
		href: "/dashboard/applications"
	},
	{
		id: 2,
		title: "Run scheduled",
		description: "P12S clear party starts in 30 minutes",
		icon: "i-lucide-calendar-check",
		iconColor: "text-green-500",
		time: "15 min ago",
		read: false,
		href: "/dashboard/runs"
	},
	{
		id: 3,
		title: "Account warning",
		description: "We've detected that you are cringe, this is a serious offense",
		icon: "i-lucide-triangle-alert",
		iconColor: "text-amber-500",
		time: "1 hour ago",
		read: false,
		href: "/dashboard/account/characters"
	},
	{
		id: 4,
		title: "New member joined",
		description: "Jane Smith joined your static group",
		icon: "i-lucide-user-check",
		iconColor: "text-purple-500",
		time: "3 hours ago",
		read: true,
		href: "/dashboard"
	},
	{
		id: 5,
		title: "Maintenance notice",
		description: "Scheduled maintenance on Sunday 2AM-4AM UTC",
		icon: "i-lucide-wrench",
		iconColor: "text-gray-500",
		time: "1 day ago",
		read: true,
		href: "/dashboard"
	}
])

const unreadCount = computed(() => {
	return notifications.value.filter(n => !n.read).length
})

const latestNotifications = computed(() => {
	return notifications.value.slice(0, 5)
})

const markAsRead = (id: number) => {
	const notification = notifications.value.find(n => n.id === id)
	if (notification) {
		notification.read = true
	}
}

const markAllAsRead = () => {
	notifications.value.forEach(n => n.read = true)
}
</script>

<template>
	<UPopover :popper="{ placement: 'bottom-end' }">
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
			<div class="w-80 max-w-sm">
				<!-- Header -->
				<div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-800">
					<h3 class="text-sm font-semibold text-gray-900 dark:text-white">
						Notifications
					</h3>
					<UButton
						v-if="unreadCount > 0"
						size="xs"
						color="neutral"
						variant="ghost"
						@click="markAllAsRead"
					>
						Mark all as read
					</UButton>
				</div>

				<!-- Notifications List -->
				<div class="max-h-96 overflow-y-auto">
					<div
						v-if="latestNotifications.length === 0"
						class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
					>
						No notifications
					</div>

					<a
						v-for="notification in latestNotifications"
						:key="notification.id"
						:href="notification.href"
						class="block px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border-b border-gray-100 dark:border-gray-800 last:border-b-0"
						:class="{ 'bg-blue-50/50 dark:bg-blue-950/20': !notification.read }"
						@click="markAsRead(notification.id)"
					>
						<div class="flex gap-3">
							<!-- Icon -->
							<div class="flex-shrink-0 mt-0.5">
								<div class="h-8 w-8 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
									<UIcon
										:name="notification.icon"
										:class="[notification.iconColor || 'text-gray-600 dark:text-gray-400', 'h-4 w-4']"
									/>
								</div>
							</div>

							<!-- Content -->
							<div class="flex-1 min-w-0">
								<div class="flex items-start justify-between gap-2">
									<p class="text-sm font-medium text-gray-900 dark:text-white">
										{{ notification.title }}
									</p>
									<UBadge
										v-if="!notification.read"
										color="blue"
										variant="soft"
										size="xs"
										class="flex-shrink-0"
									>
										New
									</UBadge>
								</div>
								<p
									v-if="notification.description"
									class="mt-1 text-sm text-gray-600 dark:text-gray-400 line-clamp-2"
								>
									{{ notification.description }}
								</p>
								<p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
									{{ notification.time }}
								</p>
							</div>
						</div>
					</a>
				</div>

				<!-- Footer -->
				<div class="px-4 py-3 border-t border-gray-200 dark:border-gray-800">
					<a
						href="/dashboard/notifications"
						class="text-sm font-medium text-brand hover:text-brand-600 dark:text-brand-400 dark:hover:text-brand-300 transition-colors"
					>
						View all notifications →
					</a>
				</div>
			</div>
		</template>
	</UPopover>
</template>

<style scoped>

</style>