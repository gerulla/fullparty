import type { GroupCreateField, GroupCreateFormData } from "@/Types/Groups";
import type { InertiaForm } from "@inertiajs/vue3";
import { isValidDiscordInviteUrl } from "@/utils/discordInviteValidation";
import { validateGroupProfilePictureFile } from "@/utils/groupProfilePictureValidation";
import { groupNameMaxLength, groupSlugMaxLength, groupSlugValidationError, normalizeGroupSlug } from "@/utils/groupIdentity";
import { computed, type ComputedRef, type Ref } from "vue";
import { useI18n } from "vue-i18n";

const groupImageMaxBytes = 5 * 1024 * 1024;

const stepFields: Record<number, GroupCreateField[]> = {
	1: ["name", "slug", "group_type", "description", "datacenter"],
	2: ["profile_picture", "banner_image", "discord_invite_url"],
	3: ["primary_focuses", "experience_expectation", "voice_expectation", "preferred_languages", "tags"],
	4: ["active_timezone", "active_days", "active_start_time", "active_end_time"],
	5: ["join_mode", "is_visible"],
};

type GroupCreateOption = {
	value: string
};

type UseGroupCreateValidationOptions = {
	form: InertiaForm<GroupCreateFormData>
	step: Ref<number>
	datacenterOptions: ComputedRef<GroupCreateOption[]>
	groupTypeOptions: ComputedRef<GroupCreateOption[]>
	maxTagCount: ComputedRef<number>
};

export const useGroupCreateValidation = ({
	form,
	step,
	datacenterOptions,
	groupTypeOptions,
	maxTagCount,
}: UseGroupCreateValidationOptions) => {
	const { t } = useI18n();

	const slugFieldValue = computed({
		get: () => form.slug,
		set: (value: string | number | undefined) => {
			form.slug = normalizeGroupSlug(String(value ?? ""));
			form.clearErrors("slug");
		},
	});

	const normalizedSlugHint = computed(() => normalizeGroupSlug(form.slug));

	const canContinue = computed(() => {
		if (step.value === 1) {
			return !!form.name && !!form.slug && !!form.group_type && !!form.datacenter;
		}

		return true;
	});

	const clearFieldError = (field: GroupCreateField) => {
		form.clearErrors(field);
	};

	const setFieldError = (field: GroupCreateField, message: string) => {
		form.setError(field, message);
	};

	const validateStepOne = () => {
		form.clearErrors(...stepFields[1]);

		let isValid = true;

		if (!form.name.trim()) {
			setFieldError("name", t("groups.index.create_modal.validation.required", {
				field: t("general.name"),
			}));
			isValid = false;
		} else if (form.name.length > groupNameMaxLength) {
			setFieldError("name", t("groups.index.create_modal.validation.max_characters", {
				field: t("general.name"),
				max: groupNameMaxLength,
			}));
			isValid = false;
		}

		const slugError = groupSlugValidationError(form.slug);
		if (slugError) {
			setFieldError("slug", t(`groups.index.create_modal.validation.${slugError}`, {
				field: t("groups.index.create_modal.fields.slug.label"),
			}));
			isValid = false;
		}

		if (!form.group_type) {
			setFieldError("group_type", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.group_type.label"),
			}));
			isValid = false;
		} else if (!groupTypeOptions.value.some((option) => option.value === form.group_type)) {
			setFieldError("group_type", t("groups.index.create_modal.validation.invalid_option", {
				field: t("groups.index.create_modal.fields.group_type.label"),
			}));
			isValid = false;
		}

		if (!form.datacenter) {
			setFieldError("datacenter", t("groups.index.create_modal.validation.required", {
				field: t("general.datacenter"),
			}));
			isValid = false;
		} else if (!datacenterOptions.value.some((option) => option.value === form.datacenter)) {
			setFieldError("datacenter", t("groups.index.create_modal.validation.invalid_option", {
				field: t("general.datacenter"),
			}));
			isValid = false;
		}

		return isValid;
	};

	const validateStepTwo = () => {
		form.clearErrors(...stepFields[2]);

		let isValid = true;
		const discordInviteUrl = form.discord_invite_url.trim();

		if (discordInviteUrl.length > 500) {
			setFieldError("discord_invite_url", t("groups.index.create_modal.validation.max_characters", {
				field: t("general.discord_invite_url"),
				max: 500,
			}));
			isValid = false;
		} else if (discordInviteUrl) {
			if (!isValidDiscordInviteUrl(discordInviteUrl)) {
				setFieldError("discord_invite_url", t("groups.index.create_modal.validation.invalid_discord_invite"));
				isValid = false;
			}
		}

		if (form.profile_picture) {
			const profilePictureValidation = validateGroupProfilePictureFile(form.profile_picture);

			if (!profilePictureValidation.isValid) {
				setFieldError("profile_picture", t("groups.index.create_modal.validation.image_invalid_format"));
				isValid = false;
			} else if (form.profile_picture.size > groupImageMaxBytes) {
				setFieldError("profile_picture", t("groups.index.create_modal.validation.image_too_large"));
				isValid = false;
			}
		}

		if (form.banner_image) {
			const bannerImageValidation = validateGroupProfilePictureFile(form.banner_image);

			if (!bannerImageValidation.isValid) {
				setFieldError("banner_image", t("groups.index.create_modal.validation.image_invalid_format"));
				isValid = false;
			} else if (form.banner_image.size > groupImageMaxBytes) {
				setFieldError("banner_image", t("groups.index.create_modal.validation.image_too_large"));
				isValid = false;
			}
		}

		return isValid;
	};

	const validateStepThree = () => {
		form.clearErrors(...stepFields[3]);

		let isValid = true;

		if (form.primary_focuses.length === 0) {
			setFieldError("primary_focuses", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.primary_focuses.label"),
			}));
			isValid = false;
		}

		if (!form.experience_expectation) {
			setFieldError("experience_expectation", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.experience_expectation.label"),
			}));
			isValid = false;
		}

		if (!form.voice_expectation) {
			setFieldError("voice_expectation", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.voice_expectation.label"),
			}));
			isValid = false;
		}

		if (form.preferred_languages.length === 0) {
			setFieldError("preferred_languages", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.preferred_languages.label"),
			}));
			isValid = false;
		}

		if (form.tags.length > maxTagCount.value) {
			setFieldError("tags", t("groups.index.create_modal.validation.max_tags", {
				max: maxTagCount.value,
			}));
			isValid = false;
		}

		if (form.tags.some((tag) => tag.length > 50)) {
			setFieldError("tags", t("groups.index.create_modal.validation.tag_too_long", {
				max: 50,
			}));
			isValid = false;
		}

		return isValid;
	};

	const validateStepFour = () => {
		form.clearErrors(...stepFields[4]);

		let isValid = true;
		const hasActiveDays = form.active_days.length > 0;
		const hasActiveStart = Boolean(form.active_start_time);
		const hasActiveEnd = Boolean(form.active_end_time);
		const hasTimezone = Boolean(form.active_timezone);

		if ((hasActiveStart && !hasActiveEnd) || (!hasActiveStart && hasActiveEnd)) {
			setFieldError(hasActiveStart ? "active_end_time" : "active_start_time", t("groups.common.validation.active_time_pair_required"));
			isValid = false;
		}

		if ((hasActiveDays || hasActiveStart || hasActiveEnd) && !hasTimezone) {
			setFieldError("active_timezone", t("groups.common.validation.active_timezone_required"));
			isValid = false;
		}

		return isValid;
	};

	const validateStepFive = () => {
		form.clearErrors(...stepFields[5]);

		if (!form.join_mode) {
			setFieldError("join_mode", t("groups.index.create_modal.validation.required", {
				field: t("groups.index.create_modal.fields.join_mode.label"),
			}));

			return false;
		}

		return true;
	};

	const validateCurrentStep = () => {
		if (step.value === 1) {
			return validateStepOne();
		}

		if (step.value === 2) {
			return validateStepTwo();
		}

		if (step.value === 3) {
			return validateStepThree();
		}

		if (step.value === 4) {
			return validateStepFour();
		}

		if (step.value === 5) {
			return validateStepFive();
		}

		return true;
	};

	const goToStepWithErrors = (errors: Partial<Record<GroupCreateField, string>>) => {
		for (const [stepNumber, fields] of Object.entries(stepFields)) {
			if (fields.some((field) => Object.keys(errors).some((errorField) => errorField === field || errorField.startsWith(`${field}.`)))) {
				step.value = Number(stepNumber);
				return;
			}
		}
	};

	return {
		canContinue,
		clearFieldError,
		goToStepWithErrors,
		groupSlugMaxLength,
		normalizeGroupSlug,
		normalizedSlugHint,
		slugFieldValue,
		validateCurrentStep,
	};
};
