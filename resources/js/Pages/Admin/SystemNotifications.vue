<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { formatNotificationTime, resolveNotificationDescription, resolveNotificationMeta, resolveNotificationTitle, type NotificationRecord } from "@/utils/notificationPresentation";
import { useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";

type SystemNotificationHistoryItem = Pick<NotificationRecord,
	'id' | 'type' | 'is_mandatory' | 'title_key' | 'body_key' | 'message_params' | 'payload' | 'action_url' | 'created_at'
> & {
	actor: {
		id: number | null
		name: string
	}
	read_count: number
	delivery_count: number
}

type SystemBannerRecord = {
	id: number
	title: string
	message: string
	action_label: string | null
	action_url: string | null
	updated_at: string | null
}

const props = defineProps<{
	currentBanner: SystemBannerRecord | null
	history: Array<SystemNotificationHistoryItem>
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();

const bannerForm = useForm({
	title: '',
	message: '',
	action_label: '',
	action_url: '',
});

const maintenanceForm = useForm({
	headline: '',
	message: '',
	scheduled_for: '',
	action_url: '',
});

const announcementForm = useForm({
	headline: '',
	message: '',
	action_url: '',
});

const syncBannerForm = (banner: SystemBannerRecord | null) => {
	bannerForm.defaults({
		title: banner?.title ?? '',
		message: banner?.message ?? '',
		action_label: banner?.action_label ?? '',
		action_url: banner?.action_url ?? '',
	});

	bannerForm.reset();
};

watch(
	() => props.currentBanner,
	(banner) => syncBannerForm(banner),
	{ immediate: true },
);

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) {
			return;
		}

		if (success.includes('system_notification_maintenance_sent')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.system_notifications.toasts.maintenance_sent'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('system_notification_announcement_sent')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.system_notifications.toasts.announcement_sent'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('system_banner_saved')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.system_notifications.toasts.banner_saved'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('system_banner_cleared')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.system_notifications.toasts.banner_cleared'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}
	},
	{ immediate: true },
);

const saveBanner = () => {
	bannerForm.put(route('admin.system-notifications.banner.store'), {
		preserveScroll: true,
	});
};

const clearBanner = () => {
	bannerForm.delete(route('admin.system-notifications.banner.clear'), {
		preserveScroll: true,
	});
};

const sendMaintenance = () => {
	maintenanceForm.post('/admin/system-notifications/maintenance', {
		preserveScroll: true,
		onSuccess: () => maintenanceForm.reset(),
	});
};

const sendAnnouncement = () => {
	announcementForm.post('/admin/system-notifications/announcements', {
		preserveScroll: true,
		onSuccess: () => announcementForm.reset(),
	});
};

const historyWithMeta = computed(() => props.history.map((item) => ({
	...item,
	meta: resolveNotificationMeta(item),
	title: resolveNotificationTitle(item, t),
	description: resolveNotificationDescription(item, t),
	relativeTime: formatNotificationTime(item.created_at, locale.value, t),
})));
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.system_notifications.title')"
			:subtitle="t('admin.system_notifications.subtitle')"
		>
			<UBadge
				size="lg"
				variant="subtle"
				class="min-w-44 justify-center py-2"
				color="warning"
				icon="i-lucide-shield-alert"
				:label="t('admin.system_notifications.admin_access')"
			/>
		</PageHeader>

		<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
			<UCard class="xl:col-span-2 dark:bg-elevated/25">
				<template #header>
					<div class="flex items-start gap-3">
						<UIcon name="i-lucide-panel-top-open" class="mt-0.5 h-5 w-5 text-emerald-500" />
						<div class="space-y-1">
							<h2 class="text-base font-semibold text-highlighted">
								{{ t('admin.system_notifications.banner.title') }}
							</h2>
							<p class="text-sm text-muted">
								{{ t('admin.system_notifications.banner.description') }}
							</p>
						</div>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="saveBanner">
					<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
						<div class="space-y-4">
							<UFormField
								:label="t('admin.system_notifications.banner.fields.title')"
								:error="bannerForm.errors.title"
								required
							>
								<UInput
									v-model="bannerForm.title"
									:placeholder="t('admin.system_notifications.banner.placeholders.title')"
									class="w-full"
									size="xl"
								/>
							</UFormField>

							<UFormField
								:label="t('admin.system_notifications.banner.fields.message')"
								:error="bannerForm.errors.message"
								required
							>
								<UTextarea
									v-model="bannerForm.message"
									:rows="5"
									:placeholder="t('admin.system_notifications.banner.placeholders.message')"
									class="w-full"
									size="xl"
								/>
							</UFormField>

							<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
								<UFormField
									:label="t('admin.system_notifications.banner.fields.action_label')"
									:error="bannerForm.errors.action_label"
								>
									<UInput
										v-model="bannerForm.action_label"
										:placeholder="t('admin.system_notifications.banner.placeholders.action_label')"
										class="w-full"
										size="xl"
									/>
								</UFormField>

								<UFormField
									:label="t('admin.system_notifications.banner.fields.action_url')"
									:error="bannerForm.errors.action_url"
								>
									<UInput
										v-model="bannerForm.action_url"
										:placeholder="t('admin.system_notifications.banner.placeholders.action_url')"
										class="w-full"
										size="xl"
									/>
								</UFormField>
							</div>
						</div>

						<div class="space-y-4">
							<UAlert
								:color="props.currentBanner ? 'success' : 'neutral'"
								variant="subtle"
								icon="i-lucide-monitor-up"
								:title="props.currentBanner ? t('admin.system_notifications.banner.live_title') : t('admin.system_notifications.banner.empty_title')"
								:description="props.currentBanner ? t('admin.system_notifications.banner.live_description') : t('admin.system_notifications.banner.empty_description')"
							/>

							<div class="rounded-sm border border-default bg-default/40 p-4">
								<p class="text-xs font-medium uppercase tracking-wide text-muted">
									{{ t('admin.system_notifications.banner.preview') }}
								</p>
								<div class="mt-3 space-y-2">
									<p class="text-sm font-semibold text-highlighted">
										{{ bannerForm.title || t('admin.system_notifications.banner.preview_empty_title') }}
									</p>
									<p class="whitespace-pre-line text-sm text-toned">
										{{ bannerForm.message || t('admin.system_notifications.banner.preview_empty_message') }}
									</p>
									<UButton
										v-if="bannerForm.action_label && bannerForm.action_url"
										as="a"
										:href="bannerForm.action_url"
										target="_blank"
										rel="noopener noreferrer"
										color="warning"
										variant="soft"
										icon="i-lucide-arrow-up-right"
										:label="bannerForm.action_label"
									/>
								</div>
							</div>
						</div>
					</div>

					<div class="flex flex-wrap justify-end gap-3">
						<UButton
							type="button"
							color="neutral"
							variant="soft"
							icon="i-lucide-eraser"
							:label="t('admin.system_notifications.banner.clear')"
							:disabled="!props.currentBanner"
							:loading="bannerForm.processing"
							@click="clearBanner"
						/>
						<UButton
							type="submit"
							color="success"
							icon="i-lucide-save"
							:label="t('admin.system_notifications.banner.save')"
							:loading="bannerForm.processing"
						/>
					</div>
				</form>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex items-start gap-3">
						<UIcon name="i-lucide-wrench" class="mt-0.5 h-5 w-5 text-amber-500" />
						<div class="space-y-1">
							<h2 class="text-base font-semibold text-highlighted">
								{{ t('admin.system_notifications.maintenance.title') }}
							</h2>
							<p class="text-sm text-muted">
								{{ t('admin.system_notifications.maintenance.description') }}
							</p>
						</div>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="sendMaintenance">
					<UFormField
						:label="t('admin.system_notifications.fields.headline')"
						:error="maintenanceForm.errors.headline"
						required
					>
						<UInput
							v-model="maintenanceForm.headline"
							:placeholder="t('admin.system_notifications.placeholders.headline')"
							class="w-full"
							size="xl"
						/>
					</UFormField>

					<UFormField
						:label="t('admin.system_notifications.fields.message')"
						:error="maintenanceForm.errors.message"
						required
					>
						<UTextarea
							v-model="maintenanceForm.message"
							:rows="6"
							:placeholder="t('admin.system_notifications.placeholders.message')"
							class="w-full"
							size="xl"
						/>
					</UFormField>

					<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
						<UFormField
							:label="t('admin.system_notifications.fields.scheduled_for')"
							:error="maintenanceForm.errors.scheduled_for"
						>
							<UInput
								v-model="maintenanceForm.scheduled_for"
								type="datetime-local"
								class="w-full"
								size="xl"
							/>
						</UFormField>

						<UFormField
							:label="t('admin.system_notifications.fields.action_url')"
							:error="maintenanceForm.errors.action_url"
						>
							<UInput
								v-model="maintenanceForm.action_url"
								:placeholder="t('admin.system_notifications.placeholders.action_url')"
								class="w-full"
								size="xl"
							/>
						</UFormField>
					</div>

					<UAlert
						color="warning"
						variant="subtle"
						icon="i-lucide-triangle-alert"
						:title="t('admin.system_notifications.maintenance.notice_title')"
						:description="t('admin.system_notifications.maintenance.notice_description')"
					/>

					<div class="flex justify-end">
						<UButton
							type="submit"
							color="warning"
							icon="i-lucide-send"
							:label="t('admin.system_notifications.maintenance.send')"
							:loading="maintenanceForm.processing"
						/>
					</div>
				</form>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex items-start gap-3">
						<UIcon name="i-lucide-megaphone" class="mt-0.5 h-5 w-5 text-sky-500" />
						<div class="space-y-1">
							<h2 class="text-base font-semibold text-highlighted">
								{{ t('admin.system_notifications.announcement.title') }}
							</h2>
							<p class="text-sm text-muted">
								{{ t('admin.system_notifications.announcement.description') }}
							</p>
						</div>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="sendAnnouncement">
					<UFormField
						:label="t('admin.system_notifications.fields.headline')"
						:error="announcementForm.errors.headline"
						required
					>
						<UInput
							v-model="announcementForm.headline"
							:placeholder="t('admin.system_notifications.placeholders.headline')"
							class="w-full"
							size="xl"
						/>
					</UFormField>

					<UFormField
						:label="t('admin.system_notifications.fields.message')"
						:error="announcementForm.errors.message"
						required
					>
						<UTextarea
							v-model="announcementForm.message"
							:rows="6"
							:placeholder="t('admin.system_notifications.placeholders.message')"
							class="w-full"
							size="xl"
						/>
					</UFormField>

					<UFormField
						:label="t('admin.system_notifications.fields.action_url')"
						:error="announcementForm.errors.action_url"
					>
						<UInput
							v-model="announcementForm.action_url"
							:placeholder="t('admin.system_notifications.placeholders.action_url')"
							class="w-full"
							size="xl"
						/>
					</UFormField>

					<UAlert
						color="info"
						variant="subtle"
						icon="i-lucide-info"
						:title="t('admin.system_notifications.announcement.notice_title')"
						:description="t('admin.system_notifications.announcement.notice_description')"
					/>

					<div class="flex justify-end">
						<UButton
							type="submit"
							color="primary"
							icon="i-lucide-send"
							:label="t('admin.system_notifications.announcement.send')"
							:loading="announcementForm.processing"
						/>
					</div>
				</form>
			</UCard>
		</div>

		<div class="mt-8">
			<h2 class="text-lg font-semibold text-highlighted">
				{{ t('admin.system_notifications.history.title') }}
			</h2>
			<p class="mt-1 text-sm text-muted">
				{{ t('admin.system_notifications.history.subtitle') }}
			</p>

			<div class="mt-4 flex flex-col gap-4">
				<UCard
					v-for="item in historyWithMeta"
					:key="item.id"
					class="dark:bg-elevated/25"
				>
					<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
						<div class="min-w-0 space-y-3">
							<div class="flex flex-wrap items-center gap-2">
								<UIcon :name="item.meta.icon" class="h-5 w-5 shrink-0" :class="item.meta.iconColor" />
								<h3 class="text-base font-semibold text-highlighted">
									{{ item.title }}
								</h3>
								<UBadge
									:color="item.is_mandatory ? 'warning' : 'neutral'"
									variant="subtle"
									:label="item.is_mandatory ? t('admin.system_notifications.history.badges.mandatory') : t('admin.system_notifications.history.badges.optional')"
								/>
							</div>

							<p v-if="item.description" class="whitespace-pre-line text-sm text-toned">
								{{ item.description }}
							</p>

							<div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-muted">
								<span>{{ t('admin.system_notifications.history.sent_by', { user: item.actor.name }) }}</span>
								<span>{{ t('admin.system_notifications.history.reads', { count: item.read_count }) }}</span>
								<span>{{ t('admin.system_notifications.history.deliveries', { count: item.delivery_count }) }}</span>
								<span>{{ item.relativeTime }}</span>
							</div>
						</div>

						<UButton
							v-if="item.action_url"
							as="a"
							:href="item.action_url"
							target="_blank"
							rel="noopener noreferrer"
							color="neutral"
							variant="soft"
							icon="i-lucide-arrow-up-right"
							:label="t('admin.system_notifications.history.open_link')"
						/>
					</div>
				</UCard>

				<UCard v-if="historyWithMeta.length === 0" class="dark:bg-elevated/25">
					<div class="py-8 text-center text-sm text-muted">
						{{ t('admin.system_notifications.history.empty') }}
					</div>
				</UCard>
			</div>
		</div>
	</div>
</template>
