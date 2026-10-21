<script setup lang="ts">
import type { GroupJoinMode, GroupType } from "@/Types/Groups";
import { groupProfilePictureAccept, validateGroupProfilePictureFile } from "@/utils/groupProfilePictureValidation";
import {
	sanitizeMultilineText,
	sanitizeMultilineTextForInput,
	sanitizeSingleLineText,
	sanitizeSingleLineTextForInput,
} from "@/utils/textInputSanitizer";
import { computed, onBeforeUnmount, ref } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const groupImageMaxBytes = 5 * 1024 * 1024;

const props = defineProps<{
	group: {
		id: number
		name: string
		description: string | null
		profile_picture_url: string | null
		banner_image_url: string | null
		discord_invite_url: string | null
		datacenter: string
		group_type: GroupType
		join_mode: GroupJoinMode
		is_visible: boolean
		slug: string
		permissions: {
			can_update_group_settings: boolean
		}
	}
}>();

const { t } = useI18n();
const toast = useToast();
const page = usePage();
const datacenterOptions = computed(() => page.props.lookups?.datacenters ?? []);

const form = useForm({
	name: props.group.name ?? '',
	description: props.group.description ?? '',
	profile_picture: null as File | null,
	banner_image: null as File | null,
	discord_invite_url: props.group.discord_invite_url ?? '',
	datacenter: props.group.datacenter ?? '',
	join_mode: props.group.join_mode ?? 'invite_only',
	is_visible: props.group.is_visible ?? true,
});

const profilePicturePreviewUrl = ref<string | null>(props.group.profile_picture_url ?? null);
const bannerImagePreviewUrl = ref<string | null>(props.group.banner_image_url ?? null);
const joinModeOptions = computed(() => {
	const values: GroupJoinMode[] = ['open', 'invite_only'];

	return values.map((value) => ({
		value,
		label: t(`groups.common.join_modes.${value}.label`),
		description: t(`groups.common.join_modes.${value}.description`),
	}));
});

const nameFieldValue = computed({
	get: () => form.name,
	set: (value: string | number | undefined) => {
		form.name = sanitizeSingleLineTextForInput(String(value ?? ""));
		form.clearErrors("name");
	},
});

const descriptionFieldValue = computed({
	get: () => form.description,
	set: (value: string | number | undefined) => {
		form.description = sanitizeMultilineTextForInput(String(value ?? ""));
		form.clearErrors("description");
	},
});

const visibilitySummary = computed(() => {
	const displayGroupName = form.name.trim() || t('groups.settings.general.visibility_summary.default_name');

	const joinModeKey = form.join_mode || 'invite_only';
	const visibilityKey = form.is_visible ? 'visible' : 'hidden';

	return t(`groups.settings.general.visibility_summary.${joinModeKey}_${visibilityKey}`, { name: displayGroupName });
});

const revokePreviewUrl = (url: string | null) => {
	if (!url || !url.startsWith('blob:')) {
		return;
	}

	URL.revokeObjectURL(url);
};

const replacePreviewUrl = (target: typeof profilePicturePreviewUrl, url: string | null) => {
	revokePreviewUrl(target.value);
	target.value = url;
};

const updateImage = (event: Event, field: 'profile_picture' | 'banner_image') => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;
	const previewTarget = field === 'profile_picture' ? profilePicturePreviewUrl : bannerImagePreviewUrl;
	const fallbackUrl = field === 'profile_picture'
		? (props.group.profile_picture_url ?? null)
		: (props.group.banner_image_url ?? null);

	form.clearErrors(field);

	form[field] = file;

	if (!file) {
		replacePreviewUrl(previewTarget, fallbackUrl);
		return;
	}

	const imageValidation = validateGroupProfilePictureFile(file);

	if (!imageValidation.isValid) {
		form[field] = null;
		replacePreviewUrl(previewTarget, fallbackUrl);
		form.setError(field, t("groups.settings.general.validation.image_invalid_format"));
		target.value = "";
		return;
	}

	if (file.size > groupImageMaxBytes) {
		form[field] = null;
		replacePreviewUrl(previewTarget, fallbackUrl);
		form.setError(field, t("groups.settings.general.validation.image_too_large"));
		target.value = "";
		return;
	}

	replacePreviewUrl(previewTarget, URL.createObjectURL(file));
};

const submit = () => {
	if (!props.group.permissions.can_update_group_settings) {
		return;
	}

	form
		.transform((data) => ({
			...data,
			name: sanitizeSingleLineText(data.name),
			description: sanitizeMultilineText(data.description),
			_method: 'put',
		}))
		.post(route('groups.dashboard.settings.update', props.group.slug), {
			forceFormData: true,
			preserveScroll: true,
			onSuccess: () => {
				toast.add({
					title: t('general.success'),
					description: t('groups.settings.general.toasts.updated'),
					color: 'success',
					icon: 'i-lucide-check',
				});
			},
		});
};

onBeforeUnmount(() => {
	revokePreviewUrl(profilePicturePreviewUrl.value);
	revokePreviewUrl(bannerImagePreviewUrl.value);
});
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.settings.general.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.settings.general.subtitle') }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-4" @submit.prevent="submit">
			<UAlert
				v-if="!group.permissions.can_update_group_settings"
				color="warning"
				variant="subtle"
				icon="i-lucide-shield-alert"
				:title="t('groups.settings.general.owner_only_notice')"
			/>

			<UFormField
				:label="t('general.name')"
				:error="form.errors.name"
				required
			>
				<UInput
					v-model="nameFieldValue"
					class="w-full"
					:placeholder="t('groups.settings.general.fields.name.placeholder')"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</UFormField>

			<UFormField
				:label="t('general.description')"
				:error="form.errors.description"
			>
				<UTextarea
					v-model="descriptionFieldValue"
					class="w-full"
					:rows="4"
					:placeholder="t('groups.settings.general.fields.description.placeholder')"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</UFormField>

			<UFormField
				:label="t('general.profile_picture')"
				:help="t('groups.settings.general.fields.profile_picture.help')"
				:error="form.errors.profile_picture"
			>
				<div class="flex flex-col gap-3">
					<label
						class="file-upload-field"
						:class="{ 'pointer-events-none opacity-60': !group.permissions.can_update_group_settings }"
					>
						<UIcon name="i-lucide-upload" size="16" />
						<span class="text-sm font-medium">
							{{ form.profile_picture?.name || t('groups.settings.general.fields.profile_picture.placeholder') }}
						</span>
						<input
							class="sr-only"
							type="file"
							:accept="groupProfilePictureAccept"
							:disabled="!group.permissions.can_update_group_settings"
							@change="(event) => updateImage(event, 'profile_picture')"
						>
					</label>

					<div v-if="profilePicturePreviewUrl" class="rounded-sm border border-muted p-3">
						<p class="mb-2 text-xs uppercase tracking-wide text-muted">
							{{ t('groups.settings.general.fields.profile_picture.preview_label') }}
						</p>
						<div class="flex items-start gap-3">
							<div class="square-preview-frame">
								<img
									:src="profilePicturePreviewUrl"
									:alt="t('groups.settings.general.fields.profile_picture.preview_alt')"
									class="square-preview-image"
								>
							</div>
							<p class="max-w-xs text-sm text-muted">
								{{ t('groups.settings.general.fields.profile_picture.preview_help') }}
							</p>
						</div>
					</div>
				</div>
			</UFormField>

			<UFormField
				:label="t('groups.settings.general.fields.banner_image.label')"
				:help="t('groups.settings.general.fields.banner_image.help')"
				:error="form.errors.banner_image"
			>
				<div class="flex flex-col gap-3">
					<label
						class="file-upload-field"
						:class="{ 'pointer-events-none opacity-60': !group.permissions.can_update_group_settings }"
					>
						<UIcon name="i-lucide-image-up" size="16" />
						<span class="text-sm font-medium">
							{{ form.banner_image?.name || t('groups.settings.general.fields.banner_image.placeholder') }}
						</span>
						<input
							class="sr-only"
							type="file"
							:accept="groupProfilePictureAccept"
							:disabled="!group.permissions.can_update_group_settings"
							@change="(event) => updateImage(event, 'banner_image')"
						>
					</label>

					<div v-if="bannerImagePreviewUrl" class="rounded-sm border border-muted p-3">
						<p class="mb-2 text-xs uppercase tracking-wide text-muted">
							{{ t('groups.settings.general.fields.banner_image.preview_label') }}
						</p>
						<div class="wide-preview-frame">
							<img
								:src="bannerImagePreviewUrl"
								:alt="t('groups.settings.general.fields.banner_image.preview_alt')"
								class="wide-preview-image"
							>
						</div>
						<p class="mt-2 text-sm text-muted">
							{{ t('groups.settings.general.fields.banner_image.preview_help') }}
						</p>
					</div>
				</div>
			</UFormField>

			<UFormField
				:label="t('general.discord_invite_url')"
				:help="t('groups.settings.general.fields.discord_invite_url.help')"
				:error="form.errors.discord_invite_url"
			>
				<UInput
					v-model="form.discord_invite_url"
					class="w-full"
					:placeholder="t('groups.settings.general.fields.discord_invite_url.placeholder')"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</UFormField>

			<UFormField
				:label="t('general.datacenter')"
				:error="form.errors.datacenter"
				required
			>
				<USelect
					v-model="form.datacenter"
					class="w-full"
					:items="datacenterOptions"
					value-key="value"
					:placeholder="t('groups.settings.general.fields.datacenter.placeholder')"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</UFormField>

			<UFormField
				:label="t('groups.settings.general.fields.join_mode.label')"
				:help="t('groups.settings.general.fields.join_mode.help')"
				:error="form.errors.join_mode"
				required
			>
				<USelect
					v-model="form.join_mode"
					class="w-full"
					:items="joinModeOptions"
					value-key="value"
					:placeholder="t('groups.settings.general.fields.join_mode.placeholder')"
					:disabled="!group.permissions.can_update_group_settings"
				/>
			</UFormField>

			<div class="toggle-block">
				<div class="flex flex-col gap-1">
					<p class="font-medium">{{ t('groups.settings.general.fields.is_visible.label') }}</p>
					<p class="text-sm text-muted">{{ t('groups.settings.general.fields.is_visible.help') }}</p>
				</div>
				<USwitch v-model="form.is_visible" :disabled="!group.permissions.can_update_group_settings" />
			</div>

			<div class="rounded-sm border border-default bg-muted/20 px-3 py-3">
				<p class="mb-1 text-sm font-semibold">{{ t('groups.settings.general.visibility_summary_label') }}</p>
				<p class="text-sm text-muted">{{ visibilitySummary }}</p>
			</div>

			<div class="flex pt-2">
				<UButton
					type="submit"
					color="neutral"
					size="lg"
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

.toggle-block {
	@apply flex items-center justify-between gap-4 rounded-sm border border-muted px-3 py-3;
}

.file-upload-field {
	@apply flex w-full cursor-pointer items-center gap-2 rounded-sm border border-dashed border-muted px-3 py-3 transition hover:border-brand;
}

.square-preview-frame {
	@apply relative aspect-square w-28 overflow-hidden rounded-sm border border-default bg-muted/30;
}

.square-preview-image {
	@apply absolute inset-0 h-full w-full object-cover object-center;
}

.wide-preview-frame {
	@apply relative aspect-[15/4] w-full overflow-hidden rounded-sm border border-default bg-muted/30;
}

.wide-preview-image {
	@apply absolute inset-0 h-full w-full object-cover object-center;
}
</style>
