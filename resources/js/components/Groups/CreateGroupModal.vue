<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from "vue-i18n";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";

const { t } = useI18n();
const toast = useToast();
const self_open = ref(false);
const step = ref(1);
const max_steps = 3;

const datacenterOptions = [
	{ label: 'Aether', value: 'Aether' },
	{ label: 'Crystal', value: 'Crystal' },
	{ label: 'Dynamis', value: 'Dynamis' },
	{ label: 'Primal', value: 'Primal' },
	{ label: 'Chaos', value: 'Chaos' },
	{ label: 'Light', value: 'Light' },
	{ label: 'Elemental', value: 'Elemental' },
	{ label: 'Gaia', value: 'Gaia' },
	{ label: 'Mana', value: 'Mana' },
	{ label: 'Meteor', value: 'Meteor' },
	{ label: 'Materia', value: 'Materia' },
];

const form = useForm({
	name: '',
	description: '',
	profile_picture: null as File | null,
	discord_invite_url: '',
	datacenter: '',
	is_public: false,
	is_visible: true,
	slug: '',
});
const profilePicturePreviewUrl = ref<string | null>(null);

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
	form.is_public = false;
	form.is_visible = true;
	form.profile_picture = null;
	profilePicturePreviewUrl.value = null;
	step.value = 1;
};

const close = () => {
	hide();
	resetForm();
};

const normalizedSlugHint = computed(() => form.slug.toLowerCase().replace(/[^a-z]/g, '').slice(0, 8));
const displayGroupName = computed(() => form.name.trim() || t('groups.index.create_modal.visibility_summary.default_name'));
const visibilitySummary = computed(() => {
	if (form.is_public && form.is_visible) {
		return t('groups.index.create_modal.visibility_summary.public_visible', { name: displayGroupName.value });
	}

	if (form.is_public && !form.is_visible) {
		return t('groups.index.create_modal.visibility_summary.public_hidden', { name: displayGroupName.value });
	}

	if (!form.is_public && form.is_visible) {
		return t('groups.index.create_modal.visibility_summary.private_visible', { name: displayGroupName.value });
	}

	return t('groups.index.create_modal.visibility_summary.private_hidden', { name: displayGroupName.value });
});

const canContinue = computed(() => {
	if (step.value === 1) {
		return !!form.name && !!form.slug && !!form.datacenter;
	}

	return true;
});

const nextStep = () => {
	if (step.value >= max_steps || !canContinue.value) {
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

const submit = () => {
	form.transform((data) => ({
		...data,
		slug: data.slug.toLowerCase().replace(/[^a-z]/g, '').slice(0, 8),
	})).post(route('groups.store'), {
		preserveScroll: true,
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

const updateProfilePicture = (event: Event) => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;

	form.profile_picture = file;

	if (!file) {
		profilePicturePreviewUrl.value = null;
		return;
	}

	profilePicturePreviewUrl.value = URL.createObjectURL(file);
};

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
						:label="t('groups.index.create_modal.fields.name.label')"
						:error="form.errors.name"
						required
					>
						<UInput
							v-model="form.name"
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
							v-model="form.slug"
							class="w-full"
							:placeholder="t('groups.index.create_modal.fields.slug.placeholder')"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>

					<div class="rounded-sm border border-muted px-3 py-3 text-sm text-muted">
						<p>{{ t('groups.index.create_modal.slug_preview', { slug: normalizedSlugHint || t('groups.index.create_modal.slug_fallback') }) }}</p>
						<p class="mt-1 font-medium text-toned">{{ t('groups.index.create_modal.slug_warning') }}</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.description.label')"
						:error="form.errors.description"
					>
						<UTextarea
							v-model="form.description"
							class="w-full"
							:rows="4"
							:placeholder="t('groups.index.create_modal.fields.description.placeholder')"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.datacenter.label')"
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
						/>
					</UFormField>
				</div>

				<div v-if="step === 2" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.presence.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.presence.subtitle') }}</p>
					</div>

					<UFormField
						:label="t('groups.index.create_modal.fields.profile_picture.label')"
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
									accept="image/*"
									@change="updateProfilePicture"
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
						:label="t('groups.index.create_modal.fields.discord_invite_url.label')"
						:help="t('groups.index.create_modal.fields.discord_invite_url.help')"
						:error="form.errors.discord_invite_url"
					>
						<UInput
							v-model="form.discord_invite_url"
							class="w-full"
							:placeholder="t('groups.index.create_modal.fields.discord_invite_url.placeholder')"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>
				</div>

				<div v-if="step === 3" class="section-block">
					<div class="flex flex-col gap-1">
						<p class="font-bold">{{ t('groups.index.create_modal.sections.visibility.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.index.create_modal.sections.visibility.subtitle') }}</p>
					</div>

					<div class="toggle-block">
						<div class="flex flex-col gap-1">
							<p class="font-medium">{{ t('groups.index.create_modal.fields.is_public.label') }}</p>
							<p class="text-sm text-muted">{{ t('groups.index.create_modal.fields.is_public.help') }}</p>
						</div>
						<USwitch v-model="form.is_public" />
					</div>

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
</style>
