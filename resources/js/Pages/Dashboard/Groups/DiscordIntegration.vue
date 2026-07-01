<script setup lang="ts">
import AccessBadge from "@/components/Groups/AccessBadge.vue";
import PageHeader from "@/components/PageHeader.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type GroupPayload = {
	id: number
	name: string
	slug: string
	current_user_role: string | null
	permissions: {
		can_manage_group: boolean
	}
	discord_link_token_expires_at: string | null
}

type DiscordGuildIntegration = {
	id: number
	discord_guild_id: string
	name: string | null
	icon_url: string | null
	permissions: string | null
	guild_installed_at: string | null
	updated_at: string | null
}

type LinkToken = {
	token: string
	expires_at: string
}

type ActivityTypeOption = {
	id: number
	name: string
	difficulty: string | null
}

type SnapshotOption = {
	id: string | number
	label?: string | null
	name?: string | null
	color?: number | null
	colors?: {
		primary_color?: number | null
		primary_hex?: string | null
		secondary_color?: number | null
		secondary_hex?: string | null
		tertiary_color?: number | null
		tertiary_hex?: string | null
		primaryColor?: number | null
		primaryHex?: string | null
		secondaryColor?: number | null
		secondaryHex?: string | null
		tertiaryColor?: number | null
		tertiaryHex?: string | null
	} | null
	usable?: boolean | null
	disabled_reason?: string | null
	type?: string | null
	type_name?: string | null
	viewable_by_bot?: boolean | null
	sendable_by_bot?: boolean | null
	position?: number | null
	managed?: boolean | null
}

type SnapshotAvailableOptions = {
	bot_log_channels?: SnapshotOption[]
	run_announcement_channels?: SnapshotOption[]
	run_role_template_roles?: SnapshotOption[]
	bot_moderator_roles?: SnapshotOption[]
	roles?: SnapshotOption[]
	channels?: SnapshotOption[]
}

type SelectOption = {
	label: string
	value: string
	disabled?: boolean
	description?: string
}

type RoleSelectOption = SelectOption & {
	roleTextStyle?: Record<string, string> | null
}

type RoleTemplateOverride = {
	activity_id: number | string
	activity_name?: string | null
	role_id: string | number | null
	created_at?: string | null
	updated_at?: string | null
}

type RoleTemplateOverrideForm = {
	activity_id: string
	activity_name: string
	role_id: string
}

type DiscordSettingId = string | number | null

type DiscordGuildSettings = {
	bot_log_channel_id: DiscordSettingId
	member_facing_channel_id: DiscordSettingId
	run_announcement_channel_id: DiscordSettingId
	template_role_id: DiscordSettingId
	run_role_template_id: DiscordSettingId
	run_role_template_role_id: DiscordSettingId
	moderation_role_id: DiscordSettingId
	bot_moderator_role_id: DiscordSettingId
	name_sync_enabled: boolean | string | number | null
	enable_name_sync: boolean | string | number | null
	nickname_sync_enabled: boolean | string | number | null
	sync_discord_names_to_ff14: boolean | string | number | null
	run_role_template_overrides: RoleTemplateOverride[] | null
}

type DiscordGuildSnapshot = {
	name?: string | null
	guild_name?: string | null
	icon_url?: string | null
	member_count?: number | null
	bot_permissions?: string | string[] | null
	roles?: SnapshotOption[]
	channels?: SnapshotOption[]
	available_options?: SnapshotAvailableOptions | null
	settings?: Partial<DiscordGuildSettings> | null
}

type MembershipCoverage = {
	app_linked_member_count: number
	unlinked_member_count: number
	member_count: number
	stats_available: boolean
	membership_cache?: {
		refresh_status?: string | null
		stale?: boolean | null
		last_full_refresh_at?: string | null
		next_refresh_after?: string | null
		cache_age_seconds?: number | null
		last_error?: string | null
		refresh_queued?: boolean | null
	} | null
}

const props = defineProps<{
	group: GroupPayload
	integration: DiscordGuildIntegration | null
	inviteUrl: string
	activityTypes: ActivityTypeOption[]
	snapshot: DiscordGuildSnapshot | null
	membershipCoverage?: MembershipCoverage | null
}>();

const { t } = useI18n();
const page = usePage();
const toast = useToast();
const linkToken = ref<LinkToken | null>(null);
const generatingToken = ref(false);
const refreshingSnapshot = ref(false);
const NONE_VALUE = "__none";
const newOverrideActivityId = ref<string>(NONE_VALUE);
const newOverrideRoleId = ref<string>(NONE_VALUE);

const hasActiveToken = computed(() => {
	if (linkToken.value) {
		return true;
	}

	if (!props.group.discord_link_token_expires_at) {
		return false;
	}

	return new Date(props.group.discord_link_token_expires_at).getTime() > Date.now();
});

const tokenExpiresAt = computed(() => linkToken.value?.expires_at ?? props.group.discord_link_token_expires_at);
const snapshotSettings = computed(() => props.snapshot?.settings ?? null);
const channels = computed(() => props.snapshot?.channels ?? []);
const roles = computed(() => props.snapshot?.roles ?? []);
const availableOptions = computed(() => props.snapshot?.available_options ?? null);
const noSelectionOption = computed(() => ({
	label: t("groups.discord.settings.none"),
	value: NONE_VALUE,
}));
const activityTypeMenuOptions = computed(() => [
	noSelectionOption.value,
	...props.activityTypes.map((activityType) => ({
		label: activityType.name,
		value: String(activityType.id),
		description: activityType.difficulty ?? undefined,
	})),
]);
const optionLabel = (option: SnapshotOption, prefix = "") => {
	const label = option.label ?? option.name ?? option.id;

	return `${prefix}${label}`;
};
const numericColorToHex = (color: number | null | undefined): string | null => {
	if (typeof color !== "number" || !Number.isFinite(color) || color <= 0) {
		return null;
	}

	return `#${Math.round(color).toString(16).padStart(6, "0").slice(-6).toUpperCase()}`;
};
const normalizeHexColor = (color: string | null | undefined): string | null => {
	if (typeof color !== "string") {
		return null;
	}

	const trimmed = color.trim();

	return /^#[0-9a-f]{6}$/i.test(trimmed) ? trimmed : null;
};
const roleTextStyle = (option: SnapshotOption): Record<string, string> | null => {
	const colors = [
		normalizeHexColor(option.colors?.primary_hex ?? option.colors?.primaryHex)
			?? numericColorToHex(option.colors?.primary_color ?? option.colors?.primaryColor)
			?? numericColorToHex(option.color),
		normalizeHexColor(option.colors?.secondary_hex ?? option.colors?.secondaryHex)
			?? numericColorToHex(option.colors?.secondary_color ?? option.colors?.secondaryColor),
		normalizeHexColor(option.colors?.tertiary_hex ?? option.colors?.tertiaryHex)
			?? numericColorToHex(option.colors?.tertiary_color ?? option.colors?.tertiaryColor),
	].filter((color): color is string => Boolean(color));

	if (colors.length === 0) {
		return null;
	}

	if (colors.length === 1) {
		return {
			color: colors[0],
		};
	}

	return {
		backgroundImage: `linear-gradient(135deg, ${colors.join(", ")})`,
		backgroundClip: "text",
		WebkitBackgroundClip: "text",
		WebkitTextFillColor: "transparent",
		color: "transparent",
		display: "inline-block",
	};
};
const selectOption = (option: SnapshotOption, prefix = ""): SelectOption => {
	const disabledReason = option.usable === false
		? option.disabled_reason ?? t("groups.discord.settings.option_unavailable")
		: null;
	const label = optionLabel(option, prefix);

	return {
		label: disabledReason ? `${label} (${disabledReason})` : label,
		value: String(option.id),
		disabled: option.usable === false,
		description: disabledReason ?? undefined,
	};
};
const roleSelectOption = (option: SnapshotOption): RoleSelectOption => ({
	...selectOption(option, "@"),
	roleTextStyle: roleTextStyle(option),
});
const optionBucket = (
	bucket: SnapshotOption[] | undefined,
	fallback: SnapshotOption[],
	prefix = "",
) => [
	noSelectionOption.value,
	...(bucket ?? fallback).map((option) => selectOption(option, prefix)),
];
const botLogChannelOptions = computed(() => optionBucket(
	availableOptions.value?.bot_log_channels,
	availableOptions.value?.channels ?? channels.value,
	"# ",
));
const memberFacingChannelOptions = computed(() => optionBucket(
	availableOptions.value?.run_announcement_channels,
	availableOptions.value?.channels ?? channels.value,
	"# ",
));
const roleOptionBucket = (
	bucket: SnapshotOption[] | undefined,
	fallback: SnapshotOption[],
): RoleSelectOption[] => [
	{ ...noSelectionOption.value, roleTextStyle: null },
	...(bucket ?? fallback).map(roleSelectOption),
];
const templateRoleMenuOptions = computed(() => roleOptionBucket(
	availableOptions.value?.run_role_template_roles,
	availableOptions.value?.roles ?? roles.value,
));
const moderationRoleMenuOptions = computed(() => roleOptionBucket(
	availableOptions.value?.bot_moderator_roles,
	availableOptions.value?.roles ?? roles.value,
));
const selectedRoleOption = (
	options: RoleSelectOption[],
	value: DiscordSettingId,
): RoleSelectOption | null => {
	if (value === null || value === undefined) {
		return null;
	}

	return options.find((option) => String(option.value) === String(value)) ?? null;
};
const botPermissionsLabel = computed(() => {
	const permissions = props.snapshot?.bot_permissions;

	if (Array.isArray(permissions)) {
		return permissions.length > 0 ? permissions.join(", ") : t("groups.discord.stats.none");
	}

	return permissions || t("groups.discord.stats.unknown");
});
const snapshotGuildName = computed(() => props.snapshot?.guild_name ?? props.snapshot?.name ?? props.integration?.name ?? t("groups.discord.status.unknown_guild"));
const snapshotIconUrl = computed(() => props.snapshot?.icon_url ?? props.integration?.icon_url ?? null);
const canEditSettings = computed(() => Boolean(props.integration && props.snapshot));
const coverageLoaded = computed(() => props.membershipCoverage !== undefined);
const coverageSource = computed(() => props.membershipCoverage ?? null);
const sanitizeCount = (value: number | null | undefined) => {
	if (typeof value !== "number" || !Number.isFinite(value)) {
		return null;
	}

	return Math.max(Math.floor(value), 0);
};
const formatCount = (value: number | null) => value === null ? "—" : new Intl.NumberFormat().format(value);
const linkedMemberCount = computed(() => sanitizeCount(coverageSource.value?.app_linked_member_count ?? null));
const explicitUnlinkedMemberCount = computed(() => sanitizeCount(coverageSource.value?.unlinked_member_count ?? null));
const totalMemberCount = computed(() => sanitizeCount(coverageSource.value?.member_count ?? null));
const coverageTotal = computed(() => {
	if (totalMemberCount.value !== null) {
		return totalMemberCount.value;
	}

	if (linkedMemberCount.value !== null && explicitUnlinkedMemberCount.value !== null) {
		return linkedMemberCount.value + explicitUnlinkedMemberCount.value;
	}

	return null;
});
const unlinkedMemberCount = computed(() => {
	if (explicitUnlinkedMemberCount.value !== null) {
		return explicitUnlinkedMemberCount.value;
	}

	if (coverageTotal.value !== null && linkedMemberCount.value !== null) {
		return Math.max(coverageTotal.value - linkedMemberCount.value, 0);
	}

	return null;
});
const linkedCoveragePercent = computed(() => {
	if (coverageTotal.value === null || coverageTotal.value <= 0 || linkedMemberCount.value === null) {
		return null;
	}

	return Math.round((linkedMemberCount.value / coverageTotal.value) * 100);
});
const coverageStatsAvailable = computed(() => Boolean(coverageSource.value?.stats_available));
const linkedCoverageLabel = computed(() => linkedCoveragePercent.value === null ? "—" : `${linkedCoveragePercent.value}%`);
const coveragePieStyle = computed(() => {
	const percent = linkedCoveragePercent.value ?? 0;

	return {
		background: `conic-gradient(rgb(168 85 247) 0 ${percent}%, rgba(255, 255, 255, 0.12) ${percent}% 100%)`,
	};
});

const normalizeSelection = (value: string | null | undefined) => value ?? NONE_VALUE;
const nullableSelection = (value: string) => value === NONE_VALUE ? null : value;
const settingStringValue = (
	settings: Partial<DiscordGuildSettings> | null | undefined,
	keys: Array<keyof DiscordGuildSettings>,
) => {
	if (!settings) {
		return null;
	}

	for (const key of keys) {
		if (!Object.prototype.hasOwnProperty.call(settings, key)) {
			continue;
		}

		const value = settings[key];

		if (typeof value === "string" && value !== "") {
			return value;
		}

		if (typeof value === "number" && Number.isFinite(value)) {
			return String(value);
		}

		return null;
	}

	return null;
};
const settingBooleanValue = (
	settings: Partial<DiscordGuildSettings> | null | undefined,
	keys: Array<keyof DiscordGuildSettings>,
) => {
	if (!settings) {
		return false;
	}

	for (const key of keys) {
		if (!Object.prototype.hasOwnProperty.call(settings, key)) {
			continue;
		}

		const value = settings[key];

		if (typeof value === "boolean") {
			return value;
		}

		if (value === "true" || value === "1" || value === 1) {
			return true;
		}

		return false;
	}

	return false;
};
const activityTypeName = (activityId: string | number | null | undefined): string => {
	const activityType = props.activityTypes.find((item) => String(item.id) === String(activityId));

	return activityType?.name ?? "";
};
const normalizeRoleTemplateOverrides = (
	overrides: RoleTemplateOverride[] | null | undefined,
): RoleTemplateOverrideForm[] => {
	if (!Array.isArray(overrides)) {
		return [];
	}

	return overrides
		.map((override): RoleTemplateOverrideForm | null => {
			const activityId = String(override.activity_id ?? "");
			const roleId = String(override.role_id ?? "");

			if (!activityId || activityId === NONE_VALUE || !roleId || roleId === NONE_VALUE) {
				return null;
			}

			return {
				activity_id: activityId,
				activity_name: override.activity_name ?? activityTypeName(activityId) ?? activityId,
				role_id: roleId,
			};
		})
		.filter((override): override is RoleTemplateOverrideForm => Boolean(override));
};
const roleTemplateOverridesForPayload = (overrides: RoleTemplateOverrideForm[]) => overrides
	.map((override) => ({
		activity_id: Number(override.activity_id),
		activity_name: override.activity_name || activityTypeName(override.activity_id),
		role_id: override.role_id,
	}))
	.filter((override) => Number.isFinite(override.activity_id) && override.activity_id > 0 && override.role_id !== NONE_VALUE);

const settingsForm = useForm({
	bot_log_channel_id: normalizeSelection(settingStringValue(snapshotSettings.value, ["bot_log_channel_id"])),
	member_facing_channel_id: normalizeSelection(settingStringValue(snapshotSettings.value, ["run_announcement_channel_id", "member_facing_channel_id"])),
	template_role_id: normalizeSelection(settingStringValue(snapshotSettings.value, ["run_role_template_id", "run_role_template_role_id", "template_role_id"])),
	moderation_role_id: normalizeSelection(settingStringValue(snapshotSettings.value, ["bot_moderator_role_id", "moderation_role_id"])),
	name_sync_enabled: settingBooleanValue(snapshotSettings.value, ["sync_discord_names_to_ff14", "name_sync_enabled", "enable_name_sync", "nickname_sync_enabled"]),
	run_role_template_overrides: normalizeRoleTemplateOverrides(snapshotSettings.value?.run_role_template_overrides),
});
const selectedModerationRoleOption = computed(() => selectedRoleOption(moderationRoleMenuOptions.value, settingsForm.moderation_role_id));
const selectedTemplateRoleOption = computed(() => selectedRoleOption(templateRoleMenuOptions.value, settingsForm.template_role_id));
const selectedTemplateRoleForValue = (roleId: DiscordSettingId): RoleSelectOption | null => selectedRoleOption(templateRoleMenuOptions.value, roleId);
const canAddOverride = computed(() => newOverrideActivityId.value !== NONE_VALUE && newOverrideRoleId.value !== NONE_VALUE);

const generateToken = () => {
	generatingToken.value = true;

	router.post(route("groups.dashboard.discord-integration.link-token", props.group.slug), {}, {
		onFinish: () => {
			generatingToken.value = false;
		},
	});
};

const copyToken = async () => {
	if (!linkToken.value) {
		return;
	}

	await navigator.clipboard.writeText(linkToken.value.token);

	toast.add({
		title: t("groups.discord.toasts.token_copied"),
		color: "success",
		icon: "i-lucide-copy-check",
	});
};

const refreshSnapshot = () => {
	refreshingSnapshot.value = true;

	router.reload({
		only: ["snapshot"],
		onFinish: () => {
			refreshingSnapshot.value = false;
		},
	});
};
const updateOverrideActivity = (index: number, activityId: string) => {
	const override = settingsForm.run_role_template_overrides[index];

	if (!override) {
		return;
	}

	override.activity_id = activityId;
	override.activity_name = activityTypeName(activityId) || override.activity_name;
	saveSettings();
};
const updateOverrideRole = (index: number, roleId: string) => {
	const override = settingsForm.run_role_template_overrides[index];

	if (!override) {
		return;
	}

	override.role_id = roleId;
	saveSettings();
};
const addRoleTemplateOverride = () => {
	if (!canAddOverride.value) {
		return;
	}

	const activityName = activityTypeName(newOverrideActivityId.value);

	if (!activityName) {
		return;
	}

	const existing = settingsForm.run_role_template_overrides.find((override) => override.activity_id === newOverrideActivityId.value);

	if (existing) {
		existing.activity_name = activityName;
		existing.role_id = newOverrideRoleId.value;
	} else {
		settingsForm.run_role_template_overrides.push({
			activity_id: newOverrideActivityId.value,
			activity_name: activityName,
			role_id: newOverrideRoleId.value,
		});
	}

	newOverrideActivityId.value = NONE_VALUE;
	newOverrideRoleId.value = NONE_VALUE;
	saveSettings();
};
const removeRoleTemplateOverride = (activityId: string) => {
	settingsForm.run_role_template_overrides = settingsForm.run_role_template_overrides
		.filter((override) => override.activity_id !== activityId);
	saveSettings();
};

const saveSettings = () => {
	settingsForm
		.transform((data) => ({
			bot_log_channel_id: nullableSelection(data.bot_log_channel_id),
			member_facing_channel_id: nullableSelection(data.member_facing_channel_id),
			run_announcement_channel_id: nullableSelection(data.member_facing_channel_id),
			template_role_id: nullableSelection(data.template_role_id),
			run_role_template_id: nullableSelection(data.template_role_id),
			run_role_template_role_id: nullableSelection(data.template_role_id),
			moderation_role_id: nullableSelection(data.moderation_role_id),
			bot_moderator_role_id: nullableSelection(data.moderation_role_id),
			name_sync_enabled: data.name_sync_enabled,
			enable_name_sync: data.name_sync_enabled,
			nickname_sync_enabled: data.name_sync_enabled,
			sync_discord_names_to_ff14: data.name_sync_enabled,
			run_role_template_overrides: roleTemplateOverridesForPayload(data.run_role_template_overrides),
		}))
		.put(route("groups.dashboard.discord-integration.settings.update", props.group.slug), {
			onSuccess: () => {
				toast.add({
					title: t("groups.discord.toasts.settings_saved"),
					color: "success",
					icon: "i-lucide-check",
				});
			},
		});
};

watch(
	() => page.props.flash?.data?.discord_guild_link_token,
	(value) => {
		linkToken.value = (value as LinkToken | null) ?? null;
	},
	{ immediate: true },
);

watch(
	snapshotSettings,
	(settings) => {
		settingsForm.defaults({
			bot_log_channel_id: normalizeSelection(settingStringValue(settings, ["bot_log_channel_id"])),
			member_facing_channel_id: normalizeSelection(settingStringValue(settings, ["run_announcement_channel_id", "member_facing_channel_id"])),
			template_role_id: normalizeSelection(settingStringValue(settings, ["run_role_template_id", "run_role_template_role_id", "template_role_id"])),
			moderation_role_id: normalizeSelection(settingStringValue(settings, ["bot_moderator_role_id", "moderation_role_id"])),
			name_sync_enabled: settingBooleanValue(settings, ["sync_discord_names_to_ff14", "name_sync_enabled", "enable_name_sync", "nickname_sync_enabled"]),
			run_role_template_overrides: normalizeRoleTemplateOverrides(settings?.run_role_template_overrides),
		});
		settingsForm.reset();
		newOverrideActivityId.value = NONE_VALUE;
		newOverrideRoleId.value = NONE_VALUE;
	},
	{ immediate: true },
);
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.discord.title')"
			:subtitle="t('groups.discord.subtitle', { group: group.name })"
		>
			<AccessBadge
				:role="group.current_user_role"
				fallback-role="owner"
			/>
		</PageHeader>

		<div
			v-if="!integration"
			class="mt-4 flex flex-col gap-4 lg:flex-row"
		>
			<UCard class="min-w-0 flex-1">
				<template #header>
					<div class="flex items-center gap-3">
						<div class="flex size-10 items-center justify-center border border-brand-400/25 bg-brand-500/10 text-brand">
							<span class="text-sm font-semibold">1</span>
						</div>
						<div>
							<h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.invite.title") }}</h2>
							<p class="text-sm text-muted">{{ t("groups.discord.setup.invite.subtitle") }}</p>
						</div>
					</div>
				</template>

				<div class="space-y-4 text-sm text-muted">
					<p>{{ t("groups.discord.setup.invite.description") }}</p>
					<UButton
						:href="inviteUrl"
						icon="i-lucide-external-link"
						color="primary"
						variant="solid"
					>
						{{ t("groups.discord.actions.invite") }}
					</UButton>
				</div>
			</UCard>

			<UCard class="min-w-0 flex-1">
				<template #header>
					<div class="flex items-center gap-3">
						<div class="flex size-10 items-center justify-center border border-brand-400/25 bg-brand-500/10 text-brand">
							<span class="text-sm font-semibold">2</span>
						</div>
						<div>
							<h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.link.title") }}</h2>
							<p class="text-sm text-muted">{{ t("groups.discord.setup.link.subtitle") }}</p>
						</div>
					</div>
				</template>

				<div class="space-y-4 text-sm text-muted">
					<p>{{ t("groups.discord.setup.link.description") }}</p>
					<UButton
						icon="i-lucide-key-round"
						color="neutral"
						variant="soft"
						:loading="generatingToken"
						@click="generateToken"
					>
						{{ t("groups.discord.actions.generate_token") }}
					</UButton>

					<div
						v-if="linkToken"
						class="space-y-3 border border-brand-400/25 bg-brand-500/10 p-4"
					>
						<div class="min-w-0">
							<p class="text-xs font-semibold uppercase tracking-wide text-brand">{{ t("groups.discord.link.generated_token") }}</p>
							<p class="mt-1 break-all font-mono text-lg font-semibold text-highlighted">{{ linkToken.token }}</p>
							<p class="mt-1 text-xs text-muted">{{ t("groups.discord.link.expires_at", { date: new Date(linkToken.expires_at).toLocaleString() }) }}</p>
						</div>
						<UButton
							icon="i-lucide-copy"
							color="neutral"
							variant="soft"
							@click="copyToken"
						>
							{{ t("groups.discord.actions.copy_token") }}
						</UButton>
					</div>

					<UAlert
						v-else-if="hasActiveToken"
						color="info"
						variant="soft"
						icon="i-lucide-clock"
						:title="t('groups.discord.link.active_token_title')"
						:description="t('groups.discord.link.active_token_description', { date: tokenExpiresAt ? new Date(tokenExpiresAt).toLocaleString() : '' })"
					/>
				</div>
			</UCard>

			<UCard>
				<template #header>
					<div class="flex items-center gap-3">
						<div class="flex size-10 items-center justify-center border border-default/60 bg-muted text-muted">
							<span class="text-sm font-semibold">3</span>
						</div>
						<div>
							<h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.complete.title") }}</h2>
							<p class="text-sm text-muted">{{ t("groups.discord.setup.complete.subtitle") }}</p>
						</div>
					</div>
				</template>

				<div class="space-y-4 text-sm text-muted">
					<p>{{ t("groups.discord.setup.complete.description") }}</p>
					<UAlert
						color="neutral"
						variant="soft"
						icon="i-lucide-terminal"
						:title="t('groups.discord.setup.complete.command_title')"
						:description="t('groups.discord.setup.complete.command_description')"
					/>
				</div>
			</UCard>
		</div>

		<template v-else>
			<div class="mt-4 flex flex-col gap-4 xl:flex-row xl:items-start">
			<UCard class="min-w-0 flex-1">
				<template #header>
					<div class="flex items-center justify-between gap-3">
						<div class="flex min-w-0 items-center gap-3">
							<div class="flex size-10 items-center justify-center border border-brand-400/25 bg-brand-500/10 text-brand">
								<UIcon name="i-lucide-sliders-horizontal" class="size-5" />
							</div>
							<div class="min-w-0">
								<h2 class="truncate text-base font-semibold text-highlighted">{{ t("groups.discord.settings.title") }}</h2>
								<p class="truncate text-sm text-muted">{{ t("groups.discord.settings.subtitle") }}</p>
							</div>
						</div>
						<UButton
							color="neutral"
							variant="soft"
							icon="i-lucide-refresh-cw"
							:loading="refreshingSnapshot"
							:disabled="!integration"
							@click="refreshSnapshot"
						>
							{{ t("groups.discord.actions.refresh_snapshot") }}
						</UButton>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="saveSettings">
					<UAlert
						v-if="!integration"
						color="neutral"
						variant="soft"
						icon="i-lucide-link"
						:title="t('groups.discord.settings.not_linked_title')"
						:description="t('groups.discord.settings.not_linked_description')"
					/>

					<UAlert
						v-else-if="!snapshot"
						color="warning"
						variant="soft"
						icon="i-lucide-rotate-cw"
						:title="t('groups.discord.settings.snapshot_unavailable_title')"
						:description="t('groups.discord.settings.snapshot_unavailable_description')"
					/>

					<div class="flex flex-col gap-6 lg:flex-row">
						<section class="min-w-0 flex-1 space-y-4">
							<div class="border-b border-default pb-3">
								<h3 class="text-sm font-semibold uppercase tracking-wide text-highlighted">{{ t("groups.discord.settings.general_title") }}</h3>
								<p class="mt-1 text-sm text-muted">{{ t("groups.discord.settings.general_description") }}</p>
							</div>

							<UFormField :label="t('groups.discord.settings.bot_log_channel')" :error="settingsForm.errors.bot_log_channel_id">
								<USelectMenu
									v-model="settingsForm.bot_log_channel_id"
									class="w-full"
									size="lg"
									value-key="value"
									:items="botLogChannelOptions"
									:disabled="!canEditSettings"
								/>
							</UFormField>

							<UFormField :label="t('groups.discord.settings.member_facing_channel')" :error="settingsForm.errors.member_facing_channel_id">
								<USelectMenu
									v-model="settingsForm.member_facing_channel_id"
									class="w-full"
									size="lg"
									value-key="value"
									:items="memberFacingChannelOptions"
									:disabled="!canEditSettings"
								/>
							</UFormField>

							<UFormField :label="t('groups.discord.settings.moderation_role')" :error="settingsForm.errors.moderation_role_id">
								<USelectMenu
									v-model="settingsForm.moderation_role_id"
									class="w-full"
									size="lg"
									value-key="value"
									:items="moderationRoleMenuOptions"
									:disabled="!canEditSettings"
								>
									<template #default="{ ui }">
										<span
											v-if="selectedModerationRoleOption"
											data-slot="value"
											:class="ui.value({ class: 'min-w-0 truncate' })"
										>
											<span
												class="block min-w-0 truncate font-medium"
												:style="selectedModerationRoleOption.roleTextStyle ?? undefined"
											>
												{{ selectedModerationRoleOption.label }}
											</span>
										</span>
									</template>

									<template #item-label="{ item }">
										<span
											class="block min-w-0 truncate font-medium"
											:style="(item as RoleSelectOption).roleTextStyle ?? undefined"
										>
											{{ (item as RoleSelectOption).label }}
										</span>
									</template>
								</USelectMenu>
							</UFormField>

							<div class="flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
								<div>
									<p class="text-sm font-semibold text-highlighted">{{ t("groups.discord.settings.name_sync") }}</p>
									<p class="text-sm text-muted">{{ t("groups.discord.settings.name_sync_hint") }}</p>
								</div>
								<USwitch
									v-model="settingsForm.name_sync_enabled"
									size="lg"
									:disabled="!canEditSettings"
								/>
							</div>
						</section>

						<section class="min-w-0 flex-1 space-y-4">
							<div class="border-b border-default pb-3">
								<h3 class="text-sm font-semibold uppercase tracking-wide text-highlighted">{{ t("groups.discord.settings.run_title") }}</h3>
								<p class="mt-1 text-sm text-muted">{{ t("groups.discord.settings.run_description") }}</p>
							</div>

							<UFormField
								:label="t('groups.discord.settings.template_role')"
								:description="t('groups.discord.settings.template_role_description')"
								:error="settingsForm.errors.template_role_id"
							>
								<USelectMenu
									v-model="settingsForm.template_role_id"
									class="w-full"
									size="lg"
									value-key="value"
									:items="templateRoleMenuOptions"
									:disabled="!canEditSettings"
								>
									<template #default="{ ui }">
										<span
											v-if="selectedTemplateRoleOption"
											data-slot="value"
											:class="ui.value({ class: 'min-w-0 truncate' })"
										>
											<span
												class="block min-w-0 truncate font-medium"
												:style="selectedTemplateRoleOption.roleTextStyle ?? undefined"
											>
												{{ selectedTemplateRoleOption.label }}
											</span>
										</span>
									</template>

									<template #item-label="{ item }">
										<span
											class="block min-w-0 truncate font-medium"
											:style="(item as RoleSelectOption).roleTextStyle ?? undefined"
										>
											{{ (item as RoleSelectOption).label }}
										</span>
									</template>
								</USelectMenu>
							</UFormField>

							<div class="space-y-3 border-t border-default pt-4">
								<div>
									<h4 class="text-sm font-semibold text-highlighted">{{ t("groups.discord.settings.role_overrides_title") }}</h4>
									<p class="mt-1 text-sm text-muted">{{ t("groups.discord.settings.role_overrides_description") }}</p>
								</div>

								<div class="space-y-2">
									<div
										v-for="(override, index) in settingsForm.run_role_template_overrides"
										:key="`${override.activity_id}-${index}`"
										class="flex flex-col gap-2 border border-default/70 p-3 xl:flex-row xl:items-start"
									>
										<UFormField
											class="min-w-0 flex-1"
											:label="t('groups.discord.settings.override_activity')"
										>
											<USelectMenu
												:model-value="override.activity_id"
												class="w-full"
												size="lg"
												value-key="value"
												:items="activityTypeMenuOptions"
												:disabled="!canEditSettings"
												@update:model-value="(value) => updateOverrideActivity(index, String(value))"
											/>
										</UFormField>

										<UFormField
											class="min-w-0 flex-1"
											:label="t('groups.discord.settings.override_role')"
										>
											<USelectMenu
												:model-value="override.role_id"
												class="w-full"
												size="lg"
												value-key="value"
												:items="templateRoleMenuOptions"
												:disabled="!canEditSettings"
												@update:model-value="(value) => updateOverrideRole(index, String(value))"
											>
												<template #default="{ ui }">
													<span
														v-if="selectedTemplateRoleForValue(override.role_id)"
														data-slot="value"
														:class="ui.value({ class: 'min-w-0 truncate' })"
													>
														<span
															class="block min-w-0 truncate font-medium"
															:style="selectedTemplateRoleForValue(override.role_id)?.roleTextStyle ?? undefined"
														>
															{{ selectedTemplateRoleForValue(override.role_id)?.label }}
														</span>
													</span>
												</template>

												<template #item-label="{ item }">
													<span
														class="block min-w-0 truncate font-medium"
														:style="(item as RoleSelectOption).roleTextStyle ?? undefined"
													>
														{{ (item as RoleSelectOption).label }}
													</span>
												</template>
											</USelectMenu>
										</UFormField>

										<UButton
											type="button"
											class="xl:mt-6"
											color="error"
											variant="ghost"
											icon="i-lucide-trash-2"
											:aria-label="t('groups.discord.settings.remove_override')"
											:disabled="!canEditSettings"
											@click="removeRoleTemplateOverride(override.activity_id)"
										/>
									</div>

									<p
										v-if="settingsForm.run_role_template_overrides.length === 0"
										class="border border-dashed border-default/70 p-3 text-sm text-muted"
									>
										{{ t("groups.discord.settings.no_role_overrides") }}
									</p>
								</div>

								<div class="flex flex-col gap-2 border border-brand-400/20 bg-brand-500/5 p-3 xl:flex-row xl:items-start">
									<UFormField
										class="min-w-0 flex-1"
										:label="t('groups.discord.settings.override_activity')"
									>
										<USelectMenu
											v-model="newOverrideActivityId"
											class="w-full"
											size="lg"
											value-key="value"
											:items="activityTypeMenuOptions"
											:disabled="!canEditSettings"
										/>
									</UFormField>

									<UFormField
										class="min-w-0 flex-1"
										:label="t('groups.discord.settings.override_role')"
									>
										<USelectMenu
											v-model="newOverrideRoleId"
											class="w-full"
											size="lg"
											value-key="value"
											:items="templateRoleMenuOptions"
											:disabled="!canEditSettings"
										>
											<template #default="{ ui }">
												<span
													v-if="selectedTemplateRoleForValue(newOverrideRoleId)"
													data-slot="value"
													:class="ui.value({ class: 'min-w-0 truncate' })"
												>
													<span
														class="block min-w-0 truncate font-medium"
														:style="selectedTemplateRoleForValue(newOverrideRoleId)?.roleTextStyle ?? undefined"
													>
														{{ selectedTemplateRoleForValue(newOverrideRoleId)?.label }}
													</span>
												</span>
											</template>

											<template #item-label="{ item }">
												<span
													class="block min-w-0 truncate font-medium"
													:style="(item as RoleSelectOption).roleTextStyle ?? undefined"
												>
													{{ (item as RoleSelectOption).label }}
												</span>
											</template>
										</USelectMenu>
									</UFormField>

									<UButton
										type="button"
										class="xl:mt-6"
										color="primary"
										variant="soft"
										icon="i-lucide-plus"
										:disabled="!canEditSettings || !canAddOverride"
										@click="addRoleTemplateOverride"
									>
										{{ t("groups.discord.actions.add_override") }}
									</UButton>
								</div>
							</div>
						</section>
					</div>

					<div class="flex justify-end">
						<UButton
							type="submit"
							color="primary"
							icon="i-lucide-save"
							:loading="settingsForm.processing"
							:disabled="!canEditSettings"
						>
							{{ t("groups.discord.actions.save_settings") }}
						</UButton>
					</div>
				</form>
			</UCard>

			<div class="flex w-full flex-col gap-4 xl:w-[420px] xl:shrink-0">
			<UCard>
				<template #header>
					<div class="flex items-center justify-between gap-3">
						<div>
							<h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.status.title") }}</h2>
							<p class="text-sm text-muted">{{ t("groups.discord.status.subtitle") }}</p>
						</div>
						<UBadge
							:color="integration ? 'success' : 'neutral'"
							variant="subtle"
						>
							{{ integration ? t("groups.discord.status.linked") : t("groups.discord.status.not_linked") }}
						</UBadge>
					</div>
				</template>

				<div v-if="integration" class="space-y-3 text-sm">
					<div class="flex items-center gap-3">
						<img
							v-if="integration.icon_url"
							:src="integration.icon_url"
							class="size-12 border border-white/10 object-cover"
							alt=""
						>
						<div v-else class="flex size-12 items-center justify-center border border-white/10 bg-muted text-muted">
							<UIcon name="i-lucide-server" class="size-5" />
						</div>
						<div class="min-w-0">
							<p class="truncate font-semibold text-highlighted">{{ integration.name ?? t("groups.discord.status.unknown_guild") }}</p>
							<p class="truncate text-muted">{{ integration.discord_guild_id }}</p>
						</div>
					</div>
				</div>
			</UCard>

			<UCard>
				<template #header>
					<div class="flex items-center justify-between gap-3">
						<div class="flex min-w-0 items-center gap-3">
							<img
								v-if="snapshotIconUrl"
								:src="snapshotIconUrl"
								class="size-10 border border-white/10 object-cover"
								alt=""
							>
							<div v-else class="flex size-10 items-center justify-center border border-white/10 bg-muted text-muted">
								<UIcon name="i-lucide-server" class="size-5" />
							</div>
							<div class="min-w-0">
								<h2 class="truncate text-base font-semibold text-highlighted">{{ t("groups.discord.stats.title") }}</h2>
								<p class="truncate text-sm text-muted">{{ snapshotGuildName }}</p>
							</div>
						</div>
					</div>
				</template>

				<div
					v-if="!coverageLoaded"
					class="flex flex-col items-center gap-5 py-2"
				>
					<USkeleton class="size-40 rounded-full" />
					<div class="w-full space-y-3">
						<USkeleton class="h-12 w-full" />
						<USkeleton class="h-12 w-full" />
						<USkeleton class="h-5 w-full" />
					</div>
				</div>

				<div
					v-else
					class="flex flex-col items-center gap-5 py-2"
				>
					<div
						class="relative size-40 rounded-full border border-brand-400/20 shadow-[0_0_30px_rgba(168,85,247,0.18)]"
						:style="coveragePieStyle"
					>
						<div class="absolute inset-5 flex flex-col items-center justify-center rounded-full border border-default/70 bg-background text-center">
							<p class="text-3xl font-semibold text-highlighted">{{ linkedCoverageLabel }}</p>
							<p class="mt-1 px-3 text-xs text-muted">{{ t("groups.discord.stats.available_percent") }}</p>
						</div>
					</div>

					<div class="w-full space-y-3">
						<div class="flex items-center justify-between gap-3 border border-default/60 bg-default/30 p-3">
							<div class="flex items-center gap-2">
								<span class="size-2.5 bg-brand-500" />
								<span class="text-sm text-toned">{{ t("groups.discord.stats.app_linked") }}</span>
							</div>
							<span class="font-semibold text-highlighted">{{ formatCount(linkedMemberCount) }}</span>
						</div>
						<div class="flex items-center justify-between gap-3 border border-default/60 bg-default/30 p-3">
							<div class="flex items-center gap-2">
								<span class="size-2.5 bg-white/20" />
								<span class="text-sm text-toned">{{ t("groups.discord.stats.not_linked") }}</span>
							</div>
							<span class="font-semibold text-highlighted">{{ formatCount(unlinkedMemberCount) }}</span>
						</div>
						<div class="flex items-center justify-between gap-3 px-1 text-sm text-muted">
							<span>{{ t("groups.discord.stats.members") }}</span>
							<span>{{ formatCount(coverageTotal) }}</span>
						</div>
					</div>
				</div>

				<UAlert
					v-if="coverageLoaded && !coverageStatsAvailable"
					class="mt-4"
					color="neutral"
					variant="soft"
					icon="i-lucide-info"
					:description="t('groups.discord.stats.coverage_unavailable')"
				/>

				<p class="mt-4 text-sm text-muted">
					{{ t("groups.discord.stats.coverage_hint") }}
				</p>

				<div class="mt-4 border border-default/60 bg-default/30 p-3">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t("groups.discord.stats.bot_permissions") }}</p>
					<p class="mt-2 break-words text-sm text-toned">{{ botPermissionsLabel }}</p>
				</div>
			</UCard>
			</div>
			</div>
		</template>
	</div>
</template>
