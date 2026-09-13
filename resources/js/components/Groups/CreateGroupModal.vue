<script setup lang="ts">
import type { GroupCreateField, GroupCreateFormData, GroupJoinMode } from '@/Types/Groups';
import { useGroupCreateValidation } from '@/composables/useGroupCreateValidation';
import { groupProfilePictureAccept } from '@/utils/groupProfilePictureValidation';
import {
	sanitizeMultilineText,
	sanitizeMultilineTextForInput,
	sanitizeSingleLineText,
	sanitizeSingleLineTextForInput,
} from '@/utils/textInputSanitizer';
import { buildGroupTimeZoneOptions } from '@/utils/groupTimeZoneOptions';
import { uiLocales } from '@/i18n/uiLocales';
import { useToast } from '@nuxt/ui/composables';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { route } from 'ziggy-js';

type DatacenterOption = {
	label: string
	value: string
	region?: string | null
}

type GroupDiscoveryLookups = {
	primary_focuses?: string[]
	experience_expectations?: string[]
	voice_expectations?: string[]
	active_days?: string[]
	preferred_languages?: string[]
	max_tags?: number
}


const { t, tm, locale } = useI18n();
const toast = useToast();
const page = usePage();
const self_open = ref(false);
const step = ref(1);
const max_steps = 5;
const tagSearchTerm = ref('');
const profilePicturePreviewUrl = ref<string | null>(null);
const bannerImagePreviewUrl = ref<string | null>(null);
const availableTags = ref<string[]>([]);

const datacenterOptions = computed<DatacenterOption[]>(() => page.props.lookups?.datacenters ?? []);
const groupDiscoveryLookups = computed<GroupDiscoveryLookups>(() => page.props.lookups?.group_discovery ?? {});
const groupTypeOptions = computed(() => [
	{
		label: t('groups.common.group_types.community'),
		value: 'community',
	},
	{
		label: t('groups.common.group_types.static'),
		value: 'static',
	},
]);
const maxTagCount = computed(() => Number(groupDiscoveryLookups.value.max_tags ?? 12));
const tagSuggestions = computed(() => {
	const suggestions = tm('groups.index.create_modal.fields.tags.suggestions');

	if (!suggestions || typeof suggestions !== 'object') {
		return [];
	}

	return Object.values(suggestions as Record<string, unknown>)
		.map((entry) => sanitizeSingleLineText(String(entry)).trim())
		.filter((entry) => entry !== '');
});
const localeOptions = computed(() => {
	const codes = groupDiscoveryLookups.value.preferred_languages ?? [];

	return codes.map((code) => ({
		value: code,
		label: uiLocales[code as keyof typeof uiLocales]?.name ?? code.toUpperCase(),
	}));
});
const primaryFocusOptions = computed(() => (groupDiscoveryLookups.value.primary_focuses ?? []).map((value) => ({
	value,
	label: t(`groups.index.create_modal.fields.primary_focuses.options.${value}`),
})));
const experienceExpectationOptions = computed(() => (groupDiscoveryLookups.value.experience_expectations ?? []).map((value) => ({
	value,
	label: t(`groups.index.create_modal.fields.experience_expectation.options.${value}`),
})));
const voiceExpectationOptions = computed(() => (groupDiscoveryLookups.value.voice_expectations ?? []).map((value) => ({
	value,
	label: t(`groups.common.voice_expectations.${value}`),
})));
const activeDayOptions = computed(() => (groupDiscoveryLookups.value.active_days ?? []).map((value) => ({
	value,
	label: t(`groups.common.active_days.${value}`),
})));
const timeZoneOptions = computed(() => buildGroupTimeZoneOptions());

const form = useForm<GroupCreateFormData>({
	name: '',
	description: '',
	profile_picture: null,
	banner_image: null,
	discord_invite_url: '',
	datacenter: '',
	join_mode: 'invite_only',
	is_visible: true,
	slug: '',
	group_type: 'community',
	primary_focuses: [],
	experience_expectation: '',
	voice_expectation: '',
	preferred_languages: [],
	tags: [],
	active_timezone: '',
	active_days: [],
	active_start_time: '',
	active_end_time: '',
});

const joinModeOptions = computed(() => {
	const values: GroupJoinMode[] = form.group_type === 'static'
		? ['invite_only', 'application']
		: ['open', 'invite_only', 'application'];

	return values.map((value) => ({
		value,
		label: t(`groups.common.join_modes.${value}.label`),
		description: t(`groups.common.join_modes.${value}.description`),
	}));
});

const mergeTagOptions = (tags: string[]) => {
	availableTags.value = Array.from(new Set(tags))
		.filter((tag) => tag !== '')
		.sort((left, right) => left.localeCompare(right));
};

watch(tagSuggestions, (suggestions) => {
	mergeTagOptions([...suggestions, ...form.tags]);
}, { immediate: true });

const {
	canContinue,
	clearFieldError,
	goToStepWithErrors,
	groupSlugMaxLength,
	normalizeGroupSlug,
	normalizedSlugHint,
	slugFieldValue,
	validateCurrentStep,
} = useGroupCreateValidation({
	form,
	step,
	datacenterOptions,
	groupTypeOptions,
	maxTagCount,
});

const nameFieldValue = computed({
	get: () => form.name,
	set: (value: string | number | undefined) => {
		form.name = sanitizeSingleLineTextForInput(String(value ?? ''));
		clearFieldError('name');
	},
});

const descriptionFieldValue = computed({
	get: () => form.description,
	set: (value: string | number | undefined) => {
		form.description = sanitizeMultilineTextForInput(String(value ?? ''));
		clearFieldError('description');
	},
});

const discordInviteFieldValue = computed({
	get: () => form.discord_invite_url,
	set: (value: string | number | undefined) => {
		form.discord_invite_url = sanitizeSingleLineText(String(value ?? ''));
		clearFieldError('discord_invite_url');
	},
});

const selectedDatacenterOption = computed(() => datacenterOptions.value.find((option) => option.value === form.datacenter) ?? null);
const inferredRegion = computed(() => selectedDatacenterOption.value?.region ?? null);
const displayGroupName = computed(() => form.name.trim() || t('groups.index.create_modal.visibility_summary.default_name'));

const visibilitySummary = computed(() => {
	const joinModeKey = form.join_mode || 'invite_only';
	const visibilityKey = form.is_visible ? 'visible' : 'hidden';

	return t(`groups.index.create_modal.visibility_summary.${joinModeKey}_${visibilityKey}`, { name: displayGroupName.value });
});

watch(() => form.group_type, (groupType) => {
	if (groupType === 'static' && form.join_mode === 'open') {
		form.join_mode = 'invite_only';
	}

	clearFieldError('group_type');
	clearFieldError('join_mode');
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

const open = () => {
	step.value = 1;
	self_open.value = true;
};

const hide = () => {
	self_open.value = false;
};

const resetForm = () => {
	form.reset();
	form.clearErrors();
	form.join_mode = 'invite_only';
	form.is_visible = true;
	form.group_type = 'community';
	form.profile_picture = null;
	form.banner_image = null;
	form.primary_focuses = [];
	form.experience_expectation = '';
	form.voice_expectation = '';
	form.preferred_languages = [];
	form.tags = [];
	form.active_timezone = '';
	form.active_days = [];
	form.active_start_time = '';
	form.active_end_time = '';
	tagSearchTerm.value = '';
	mergeTagOptions(tagSuggestions.value);
	replacePreviewUrl(profilePicturePreviewUrl, null);
	replacePreviewUrl(bannerImagePreviewUrl, null);
	step.value = 1;
};

const close = () => {
	hide();
	resetForm();
};

const nextStep = () => {
	if (step.value >= max_steps || !canContinue.value || !validateCurrentStep()) {
		return;
	}

	step.value++;
};

const previousStep = () => {
	if (step.value <= 1) {
		return;
	}

	step.value--;
};

const addCreatedTag = (rawTag: string) => {
	const tag = sanitizeSingleLineText(rawTag).trim();

	if (!tag) {
		tagSearchTerm.value = '';
		return;
	}

	if (!form.tags.includes(tag)) {
		form.tags = [...form.tags, tag];
	}

	mergeTagOptions([...availableTags.value, tag]);
	clearFieldError('tags');
	tagSearchTerm.value = '';
};

const submit = () => {
	form.transform((data) => ({
		...data,
		name: sanitizeSingleLineText(data.name),
		description: sanitizeMultilineText(data.description),
		slug: normalizeGroupSlug(data.slug),
	})).post(route('groups.store'), {
		onError: (errors) => {
			goToStepWithErrors(errors as Partial<Record<GroupCreateField, string>>);
		},
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.index.create_modal.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check',
			});
			close();
		},
	});
};

const updateImage = (event: Event, field: 'profile_picture' | 'banner_image') => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;

	clearFieldError(field);
	form[field] = file;

	const nextUrl = file ? URL.createObjectURL(file) : null;

	if (field === 'profile_picture') {
		replacePreviewUrl(profilePicturePreviewUrl, nextUrl);
		return;
	}

	replacePreviewUrl(bannerImagePreviewUrl, nextUrl);
};

onBeforeUnmount(() => {
	revokePreviewUrl(profilePicturePreviewUrl.value);
	revokePreviewUrl(bannerImagePreviewUrl.value);
});

defineExpose({
	open,
	hide,
});
</script>

<template>
	<UModal
		v-model:open="self_open"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<UButton
			:label="t('groups.index.create_modal.open_button')"
			color="neutral"
			class="w-full cursor-pointer rounded-none"
			icon="i-lucide-plus"
		/>

		<template #header>
			<div class="w-full flex flex-col items-stretch">
				<div class="flex flex-col gap-1 mb-3">
					<p class="font-bold">{{ t('groups.index.create_modal.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.index.create_modal.subtitle') }}</p>
				</div>

				<div class="w-full flex flex-row items-stretch justify-between mb-1">
					<p class="text-xs text-muted uppercase">
						{{ t('groups.index.create_modal.progress', { current: step, total: max_steps }) }}
					</p>
					<p class="text-xs text-muted uppercase">
						{{ t(`groups.index.create_modal.steps.${step}`) }}
					</p>
				</div>
				<UProgress v-model="step" :max="max_steps" :ui="{ base: 'rounded-none', indicator: 'rounded-none' }" />
			</div>
		</template>

		<template #body>
			<form class="flex flex-col gap-4" @submit.prevent="submit">
				<div v-if="step === 1" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.identity.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.identity.subtitle') }}</p>
					</div>

					<UFormField
						:label="t('general.name')"
						:error="form.errors.name"
						required
					>
						<UInput
							v-model="nameFieldValue"
							class="w-full"
							:placeholder="t('groups.index.create_modal.fields.name.placeholder')"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.slug.label')"
						:help="t('groups.index.create_modal.fields.slug.help')"
						:error="form.errors.slug"
						required
					>
						<UInput
							v-model="slugFieldValue"
							class="w-full"
							:maxlength="groupSlugMaxLength"
							:placeholder="t('groups.index.create_modal.fields.slug.placeholder')"
							:ui="{ base: 'rounded-none' }"
							autocapitalize="off"
							autocomplete="off"
							spellcheck="false"
						/>
					</UFormField>

					<div class="rounded-sm border border-muted px-3 py-3 text-sm text-muted">
						<p>{{ t('groups.index.create_modal.slug_preview', { slug: normalizedSlugHint || t('groups.index.create_modal.slug_fallback') }) }}</p>
						<p class="mt-1 font-medium text-toned">{{ t('groups.index.create_modal.slug_warning') }}</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.group_type.label')"
						:error="form.errors.group_type"
						required
					>
						<USelect
							v-model="form.group_type"
							class="w-full"
							:items="groupTypeOptions"
							value-key="value"
							:placeholder="t('groups.index.create_modal.fields.group_type.placeholder')"
							:ui="{ base: 'rounded-none' }"
							@update:model-value="clearFieldError('group_type')"
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
							:placeholder="t('groups.index.create_modal.fields.description.placeholder')"
							:ui="{ base: 'rounded-none' }"
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
							:placeholder="t('groups.index.create_modal.fields.datacenter.placeholder')"
							:ui="{ base: 'rounded-none' }"
							@update:model-value="clearFieldError('datacenter')"
						/>
					</UFormField>
				</div>

				<div v-if="step === 2" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.presence.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.presence.subtitle') }}</p>
					</div>

					<UFormField
						:label="t('general.profile_picture')"
						:help="t('groups.index.create_modal.fields.profile_picture.help')"
						:error="form.errors.profile_picture"
					>
						<div class="flex flex-col gap-3">
							<label class="file-upload-field">
								<UIcon name="i-lucide-upload" size="16" />
								<span class="text-sm font-medium">
									{{ form.profile_picture?.name || t('groups.index.create_modal.fields.profile_picture.placeholder') }}
								</span>
								<input
									class="sr-only"
									type="file"
									:accept="groupProfilePictureAccept"
									@change="(event) => updateImage(event, 'profile_picture')"
								>
							</label>

							<div v-if="profilePicturePreviewUrl" class="rounded-sm border border-muted p-3">
								<p class="mb-2 text-xs uppercase tracking-wide text-muted">
									{{ t('groups.index.create_modal.fields.profile_picture.preview_label') }}
								</p>
								<div class="flex items-start gap-3">
									<div class="square-preview-frame">
										<img
											:src="profilePicturePreviewUrl"
											:alt="t('groups.index.create_modal.fields.profile_picture.preview_alt')"
											class="square-preview-image"
										>
									</div>
									<p class="max-w-xs text-sm text-muted">
										{{ t('groups.index.create_modal.fields.profile_picture.preview_help') }}
									</p>
								</div>
							</div>
						</div>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.banner_image.label')"
						:help="t('groups.index.create_modal.fields.banner_image.help')"
						:error="form.errors.banner_image"
					>
						<div class="flex flex-col gap-3">
							<label class="file-upload-field">
								<UIcon name="i-lucide-image-up" size="16" />
								<span class="text-sm font-medium">
									{{ form.banner_image?.name || t('groups.index.create_modal.fields.banner_image.placeholder') }}
								</span>
								<input
									class="sr-only"
									type="file"
									:accept="groupProfilePictureAccept"
									@change="(event) => updateImage(event, 'banner_image')"
								>
							</label>

							<div v-if="bannerImagePreviewUrl" class="rounded-sm border border-muted p-3">
								<p class="mb-2 text-xs uppercase tracking-wide text-muted">
									{{ t('groups.index.create_modal.fields.banner_image.preview_label') }}
								</p>
								<div class="wide-preview-frame">
									<img
										:src="bannerImagePreviewUrl"
										:alt="t('groups.index.create_modal.fields.banner_image.preview_alt')"
										class="wide-preview-image"
									>
								</div>
								<p class="mt-2 text-sm text-muted">
									{{ t('groups.index.create_modal.fields.banner_image.preview_help') }}
								</p>
							</div>
						</div>
					</UFormField>

					<UFormField
						:label="t('general.discord_invite_url')"
						:help="t('groups.index.create_modal.fields.discord_invite_url.help')"
						:error="form.errors.discord_invite_url"
					>
						<UInput
							v-model="discordInviteFieldValue"
							class="w-full"
							:placeholder="t('groups.index.create_modal.fields.discord_invite_url.placeholder')"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>
				</div>

				<div v-if="step === 3" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.discovery.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.discovery.subtitle') }}</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.primary_focuses.label')"
						:help="t('groups.index.create_modal.fields.primary_focuses.help')"
						:error="form.errors.primary_focuses"
						required
					>
						<USelectMenu
							v-model="form.primary_focuses"
							class="w-full"
							:items="primaryFocusOptions"
							value-key="value"
							multiple
							:placeholder="t('groups.index.create_modal.fields.primary_focuses.placeholder')"
							@update:model-value="clearFieldError('primary_focuses')"
						/>
					</UFormField>

					<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
						<UFormField
							:label="t('groups.index.create_modal.fields.experience_expectation.label')"
							:help="t('groups.index.create_modal.fields.experience_expectation.help')"
							:error="form.errors.experience_expectation"
							required
						>
							<USelect
								v-model="form.experience_expectation"
								class="w-full"
								:items="experienceExpectationOptions"
								value-key="value"
								:placeholder="t('groups.index.create_modal.fields.experience_expectation.placeholder')"
								:ui="{ base: 'rounded-none' }"
								@update:model-value="clearFieldError('experience_expectation')"
							/>
						</UFormField>

						<UFormField
							:label="t('groups.index.create_modal.fields.voice_expectation.label')"
							:help="t('groups.index.create_modal.fields.voice_expectation.help')"
							:error="form.errors.voice_expectation"
							required
						>
							<USelect
								v-model="form.voice_expectation"
								class="w-full"
								:items="voiceExpectationOptions"
								value-key="value"
								:placeholder="t('groups.index.create_modal.fields.voice_expectation.placeholder')"
								:ui="{ base: 'rounded-none' }"
								@update:model-value="clearFieldError('voice_expectation')"
							/>
						</UFormField>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.preferred_languages.label')"
						:help="t('groups.index.create_modal.fields.preferred_languages.help')"
						:error="form.errors.preferred_languages"
						required
					>
						<USelectMenu
							v-model="form.preferred_languages"
							class="w-full"
							:items="localeOptions"
							value-key="value"
							multiple
							:placeholder="t('groups.index.create_modal.fields.preferred_languages.placeholder')"
							@update:model-value="clearFieldError('preferred_languages')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.tags.label')"
						:help="t('groups.index.create_modal.fields.tags.help', { max: maxTagCount })"
						:error="form.errors.tags"
					>
						<UInputMenu
							v-model="form.tags"
							v-model:search-term="tagSearchTerm"
							class="w-full"
							:items="availableTags"
							multiple
							create-item="always"
							:placeholder="t('groups.index.create_modal.fields.tags.placeholder')"
							@create="addCreatedTag"
							@update:model-value="clearFieldError('tags')"
						/>
					</UFormField>
				</div>

				<div v-if="step === 4" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.schedule.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.schedule.subtitle') }}</p>
					</div>

					<div class="rounded-sm border border-default bg-muted/20 px-3 py-3">
						<p class="text-sm font-semibold mb-1">{{ t('groups.index.create_modal.fields.region.label') }}</p>
						<p class="text-sm text-muted">
							<span v-if="form.datacenter && inferredRegion">
								{{ t('groups.index.create_modal.fields.region.inferred', { datacenter: form.datacenter, region: inferredRegion }) }}
							</span>
							<span v-else>
								{{ t('groups.index.create_modal.fields.region.pending') }}
							</span>
						</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.active_timezone.label')"
						:help="t('groups.index.create_modal.fields.active_timezone.help')"
						:error="form.errors.active_timezone"
					>
						<USelectMenu
							v-model="form.active_timezone"
							class="w-full"
							:items="timeZoneOptions"
							value-key="value"
							:placeholder="t('groups.index.create_modal.fields.active_timezone.placeholder')"
							@update:model-value="clearFieldError('active_timezone')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.active_days.label')"
						:help="t('groups.index.create_modal.fields.active_days.help')"
						:error="form.errors.active_days"
					>
						<USelectMenu
							v-model="form.active_days"
							class="w-full"
							:items="activeDayOptions"
							value-key="value"
							multiple
							:placeholder="t('groups.index.create_modal.fields.active_days.placeholder')"
							@update:model-value="clearFieldError('active_days')"
						/>
					</UFormField>

					<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
						<UFormField
							:label="t('groups.index.create_modal.fields.active_start_time.label')"
							:help="t('groups.index.create_modal.fields.active_start_time.help')"
							:error="form.errors.active_start_time"
						>
							<UInput
								v-model="form.active_start_time"
								class="w-full"
								type="time"
								:lang="locale"
								step="60"
								:ui="{ base: 'rounded-none' }"
								@update:model-value="clearFieldError('active_start_time')"
							/>
						</UFormField>

						<UFormField
							:label="t('groups.index.create_modal.fields.active_end_time.label')"
							:help="t('groups.index.create_modal.fields.active_end_time.help')"
							:error="form.errors.active_end_time"
						>
							<UInput
								v-model="form.active_end_time"
								class="w-full"
								type="time"
								:lang="locale"
								step="60"
								:ui="{ base: 'rounded-none' }"
								@update:model-value="clearFieldError('active_end_time')"
							/>
						</UFormField>
					</div>
				</div>

				<div v-if="step === 5" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.visibility.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.visibility.subtitle') }}</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.join_mode.label')"
						:help="t('groups.index.create_modal.fields.join_mode.help')"
						:error="form.errors.join_mode"
						required
					>
						<USelect
							v-model="form.join_mode"
							class="w-full"
							:items="joinModeOptions"
							value-key="value"
							:placeholder="t('groups.index.create_modal.fields.join_mode.placeholder')"
							:ui="{ base: 'rounded-none' }"
							@update:model-value="clearFieldError('join_mode')"
						/>
					</UFormField>

					<div class="toggle-block">
						<div class="flex flex-col gap-1">
							<p class="font-medium">{{ t('groups.index.create_modal.fields.is_visible.label') }}</p>
							<p class="text-sm text-muted">{{ t('groups.index.create_modal.fields.is_visible.help') }}</p>
						</div>
						<USwitch v-model="form.is_visible" />
					</div>

					<div class="rounded-sm border border-default bg-muted/20 px-3 py-3">
						<p class="text-sm font-semibold mb-1">{{ t('groups.index.create_modal.visibility_summary.label') }}</p>
						<p class="text-sm text-muted">{{ visibilitySummary }}</p>
					</div>
				</div>

				<div class="flex items-center gap-2 pt-2">
					<UButton
						type="button"
						color="neutral"
						variant="outline"
						class="w-full"
						size="lg"
						:ui="{ base: 'rounded-none' }"
						:label="step === 1 ? t('general.cancel') : t('general.back')"
						@click.prevent="step === 1 ? close() : previousStep()"
					/>
					<UButton
						v-if="step < max_steps"
						type="button"
						color="primary"
						class="w-full"
						size="lg"
						:ui="{ base: 'rounded-none' }"
						:label="t('general.continue')"
						:disabled="!canContinue"
						@click.prevent="nextStep"
					/>
					<UButton
						v-else
						type="submit"
						color="primary"
						class="w-full"
						size="lg"
						:ui="{ base: 'rounded-none' }"
						:label="t('general.create')"
						:loading="form.processing"
					/>
				</div>
			</form>
		</template>
	</UModal>
</template>

<style scoped>
@reference '../../../css/app.css';

.section-block {
	@apply flex flex-col gap-4 rounded-sm;
}

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
