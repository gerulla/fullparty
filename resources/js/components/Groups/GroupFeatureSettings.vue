<script setup lang="ts">
import type { GroupFeatureSettings, GroupJoinMode, GroupType } from "@/Types/Groups";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		name: string
		description: string | null
		discord_invite_url: string | null
		datacenter: string
		group_type: GroupType
		join_mode: GroupJoinMode
		is_visible: boolean
		slug: string
		features: GroupFeatureSettings
		permissions: {
			can_update_group_settings: boolean
		}
	}
}>();

const { t } = useI18n();
const toast = useToast();

const form = useForm({
	name: props.group.name ?? "",
	description: props.group.description ?? "",
	discord_invite_url: props.group.discord_invite_url ?? "",
	datacenter: props.group.datacenter ?? "",
	join_mode: props.group.join_mode ?? "invite_only",
	is_visible: props.group.is_visible ?? true,
	features: {
		availability_scheduler_enabled: props.group.features?.availability_scheduler_enabled ?? false,
		statistics_enabled: props.group.features?.statistics_enabled ?? true,
		leaderboard_enabled: props.group.features?.leaderboard_enabled ?? true,
		calendar_sync_enabled: props.group.features?.calendar_sync_enabled ?? false,
		resource_hub_enabled: props.group.features?.resource_hub_enabled ?? false,
	},
});

const featureToggleRows = [
	{ key: "availability_scheduler_enabled", icon: "i-lucide-calendar-clock", implemented: true },
	{ key: "statistics_enabled", icon: "i-lucide-chart-no-axes-column-increasing", implemented: true },
	{ key: "leaderboard_enabled", icon: "i-lucide-trophy", implemented: true },
	{ key: "calendar_sync_enabled", icon: "i-lucide-calendar-sync", implemented: true },
	{ key: "resource_hub_enabled", icon: "i-lucide-folder-open", implemented: false },
] satisfies Array<{ key: keyof GroupFeatureSettings, icon: string, implemented: boolean }>;

const submit = () => {
	if (!props.group.permissions.can_update_group_settings) {
		return;
	}

	form
		.transform((data) => ({
			...data,
			_method: "put",
		}))
		.post(route("groups.dashboard.settings.update", props.group.slug), {
			preserveScroll: true,
			onSuccess: () => {
				toast.add({
					title: t("general.success"),
					description: t("groups.settings.features.toasts.updated"),
					color: "success",
					icon: "i-lucide-check",
				});
			},
		});
};
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.settings.features.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.settings.features.subtitle') }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-4" @submit.prevent="submit">
			<div class="divide-y divide-default">
				<div
					v-for="feature in featureToggleRows"
					:key="feature.key"
					class="feature-toggle-row"
				>
					<div class="flex min-w-0 items-start gap-3">
						<UIcon :name="feature.icon" class="mt-0.5 size-5 shrink-0 text-brand" />
						<div class="min-w-0">
							<p class="font-medium">
								{{ t(`groups.settings.features.items.${feature.key}.label`) }}
							</p>
							<p class="text-sm text-muted">
								{{ t(`groups.settings.features.items.${feature.key}.description`) }}
							</p>
						</div>
					</div>
					<UTooltip
						:text="feature.implemented ? undefined : t('groups.settings.features.coming_soon')"
						:disabled="feature.implemented"
					>
						<span class="inline-flex">
							<USwitch
								v-model="form.features[feature.key]"
								:disabled="!feature.implemented || !group.permissions.can_update_group_settings"
							/>
						</span>
					</UTooltip>
				</div>
			</div>

			<div class="flex pt-1">
				<UButton
					type="submit"
					color="neutral"
					:label="t('general.update')"
					:loading="form.processing"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</div>
		</form>
	</UCard>
</template>

<style scoped>
@reference '../../../css/app.css';

.feature-toggle-row {
	@apply flex items-center justify-between gap-4 py-4;
}
</style>
