import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDiscordSettingsData, SnapshotOption, SelectOption, RoleSelectOption, DiscordGuildSettings, RoleTemplateOverride, RoleTemplateOverrideForm } from '@/Types/GroupDiscord'

export function useGroupDiscordSettingsForm(props: GroupDiscordSettingsData & { preview?: boolean }, reload: () => Promise<void>, onSaved: () => void) {
    const { t } = useI18n();
    const toast = useToast();
    const refreshingSnapshot = ref(false);
    const NONE_VALUE = "__none";
    const newOverrideActivityId = ref<string>(NONE_VALUE);
    const newOverrideRoleId = ref<string>(NONE_VALUE);
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
    const canEditSettings = computed(() => Boolean(props.integration && props.snapshot));
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
    const canAddOverride = computed(() => newOverrideActivityId.value !== NONE_VALUE && newOverrideRoleId.value !== NONE_VALUE);

    const refreshSnapshot = async () => {
        refreshingSnapshot.value = true;
        try { await reload(); }
        finally { refreshingSnapshot.value = false; }
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
        if (props.preview) {
            toast.add({ title: t('groups.discord.preview.saved'), color: 'info', icon: 'i-lucide-flask-conical' });
            return;
        }
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
                preserveScroll: true,
                onSuccess: () => {
                    onSaved();
                    toast.add({
                        title: t("groups.discord.toasts.settings_saved"),
                        color: "success",
                        icon: "i-lucide-check",
                    });
                },
            });
    };

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
    return {
        settingsForm, refreshingSnapshot, refreshSnapshot, canEditSettings,
        botLogChannelOptions, memberFacingChannelOptions, moderationRoleMenuOptions,
        templateRoleMenuOptions, activityTypeMenuOptions,
        updateOverrideActivity, updateOverrideRole, removeRoleTemplateOverride, newOverrideActivityId, newOverrideRoleId,
        canAddOverride, addRoleTemplateOverride, saveSettings,
    }
}
