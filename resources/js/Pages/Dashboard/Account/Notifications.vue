<script setup lang="ts">
import type { NotificationPageData, NotificationRecord } from "@/Types/Notifications"
import { computed, ref } from 'vue'
import axios from 'axios'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import V2Layout from '@/Layouts/V2Layout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import {
	formatNotificationTime,
	resolveNotificationDescription,
	resolveNotificationMeta,
	resolveNotificationTitle,
} from '@/utils/notificationPresentation'

defineOptions({ layout: V2Layout })

const props = defineProps<{
	notificationsPage: NotificationPageData
	unreadCount: number
}>()

const { t, locale } = useI18n()
const toast = useToast()

const notifications = ref<NotificationRecord[]>([...props.notificationsPage.items])
const nextPage = ref<number | null>(props.notificationsPage.pagination.next_page)
const hasMorePages = ref<boolean>(props.notificationsPage.pagination.has_more_pages)
const totalNotifications = ref<number>(props.notificationsPage.pagination.total)
const localUnreadCount = ref<number>(props.unreadCount)
const isLoadingMore = ref(false)
const isMarkingAllRead = ref(false)

const hasNotifications = computed(() => notifications.value.length > 0)

const formatExactDateTime = (value: string | null) => {
	if (!value) {
		return t('notifications.ui.just_now')
	}

	return createDateTimeFormatter(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(value))
}

const loadMore = async () => {
	if (!hasMorePages.value || !nextPage.value || isLoadingMore.value) {
		return
	}

	isLoadingMore.value = true

	try {
		const response = await axios.get(route('account.notifications.feed'), {
			params: {
				page: nextPage.value,
			},
		})

		const pageData = response.data as NotificationPageData

		notifications.value.push(...pageData.items)
		nextPage.value = pageData.pagination.next_page
		hasMorePages.value = pageData.pagination.has_more_pages
		totalNotifications.value = pageData.pagination.total
	} finally {
		isLoadingMore.value = false
	}
}

const markAllAsRead = () => {
	if (isMarkingAllRead.value || localUnreadCount.value === 0) {
		return
	}

	isMarkingAllRead.value = true

	router.post(route('account.notifications.read-all'), {}, {
		preserveState: true,
		onSuccess: () => {
			const timestamp = new Date().toISOString()

			notifications.value = notifications.value.map((notification) => ({
				...notification,
				read_at: notification.read_at ?? timestamp,
				is_unread: false,
			}))
			localUnreadCount.value = 0

			toast.add({
				title: t('notifications.page.mark_all_success_title'),
				description: t('notifications.page.mark_all_success_description'),
				color: 'success',
			})
		},
		onFinish: () => {
			isMarkingAllRead.value = false
		},
	})
}
</script>

<template>
	<div class="mx-auto w-full xl:max-w-5xl">
		<PageHeader
			:title="t('notifications.page.title')"
			:subtitle="t('notifications.page.subtitle')"
		>
			<UButton
				v-if="localUnreadCount > 0"
				color="neutral"
				variant="outline"
				icon="i-lucide-check-check"
				:loading="isMarkingAllRead"
				:label="t('notifications.ui.mark_all_as_read')"
				@click="markAllAsRead"
			/>
		</PageHeader>

		<div v-if="!hasNotifications" class="mt-2">
			<UCard class="dark:bg-elevated/25">
				<UAlert
					color="neutral"
					variant="soft"
					icon="i-lucide-bell"
					:title="t('notifications.page.empty_title')"
					:description="t('notifications.page.empty_description')"
				/>
			</UCard>
		</div>

		<div v-else class="mt-2 space-y-4">
			<UCard class="dark:bg-elevated/25">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div class="space-y-1">
						<p class="text-sm font-medium text-toned">
							{{ t('notifications.page.showing_latest_first') }}
						</p>
						<p class="text-sm text-muted">
							{{ t('notifications.page.total_count', { count: totalNotifications }) }}
						</p>
					</div>

					<UBadge
						v-if="localUnreadCount > 0"
						color="primary"
						variant="soft"
						:label="t('notifications.page.unread_count', { count: localUnreadCount })"
					/>
				</div>
			</UCard>

			<div class="space-y-4">
				<UCard
					v-for="notification in notifications"
					:key="notification.id"
					class="relative border border-default ring-0 transition-[scale,border-color] duration-150 xl:hover:border-brand xl:motion-safe:hover:scale-101 motion-reduce:transition-none dark:bg-elevated/25"
				>
					<div>
						<div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
							<div class="flex min-w-0 gap-4">
								<div class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-sm border border-default bg-default">
									<UIcon
										:name="resolveNotificationMeta(notification).icon"
										:class="[resolveNotificationMeta(notification).iconColor, 'size-5']"
									/>
								</div>

								<div class="min-w-0 space-y-2">
									<div class="flex flex-wrap items-center gap-2">
										<UBadge
											v-if="notification.is_unread"
											color="primary"
											variant="soft"
											:label="t('notifications.ui.new_badge')"
										/>
										<UBadge
											v-if="notification.is_mandatory"
											color="warning"
											variant="soft"
											:label="t('notifications.ui.important_badge')"
										/>
									</div>

									<div class="space-y-1">
										<h2 class="text-lg font-semibold text-toned">
											<Link
												:href="notification.open_url"
												class="after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-brand"
											>
												{{ resolveNotificationTitle(notification, t) }}
											</Link>
										</h2>
										<p
											v-if="resolveNotificationDescription(notification, t)"
											class="text-sm text-muted"
										>
											{{ resolveNotificationDescription(notification, t) }}
										</p>
									</div>
								</div>
							</div>

							<div class="flex shrink-0 items-center justify-between gap-4 xl:flex-col xl:items-end xl:gap-2">
								<div class="space-y-1 text-left xl:text-right">
									<p class="text-sm font-medium text-toned">
										{{ formatNotificationTime(notification.created_at, locale, t) }}
									</p>
									<p class="text-xs text-muted">
										{{ formatExactDateTime(notification.created_at) }}
									</p>
								</div>
								<UButton
									:to="notification.open_url"
									color="brand"
									variant="ghost"
									:label="t('general.view')"
									class="relative z-10 shrink-0"
								/>
							</div>
						</div>
					</div>
				</UCard>
			</div>

			<div class="flex justify-center pt-2">
				<UButton
					v-if="hasMorePages"
					color="neutral"
					variant="outline"
					icon="i-lucide-chevron-down"
					:loading="isLoadingMore"
					:label="isLoadingMore ? t('notifications.page.loading_more') : t('notifications.page.load_more')"
					@click="loadMore"
				/>
			</div>
		</div>
	</div>
</template>
