<script setup lang="ts">
import { computed, ref } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		id: number
		name: string
		description: string | null
		profile_picture_url: string | null
		discord_invite_url: string | null
		datacenter: string
		is_public: boolean
		is_visible: boolean
		slug: string
		permissions: {
			can_manage_group: boolean
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
	discord_invite_url: props.group.discord_invite_url ?? '',
	datacenter: props.group.datacenter ?? '',
	is_public: props.group.is_public ?? false,
	is_visible: props.group.is_visible ?? true,
});

const profilePicturePreviewUrl = ref<string | null>(props.group.profile_picture_url ?? null);

const visibilitySummary = computed(() => {
	const displayGroupName = form.name.trim() || t('groups.settings.general.visibility_summary.default_name');

	if (form.is_public && form.is_visible) {
		return t('groups.settings.general.visibility_summary.public_visible', { name: displayGroupName });
	}

	if (form.is_public && !form.is_visible) {
		return t('groups.settings.general.visibility_summary.public_hidden', { name: displayGroupName });
	}

	if (!form.is_public && form.is_visible) {
		return t('groups.settings.general.visibility_summary.private_visible', { name: displayGroupName });
	}

	return t('groups.settings.general.visibility_summary.private_hidden', { name: displayGroupName });
});

const updateProfilePicture = (event: Event) => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;

	form.profile_picture = file;

	if (!file) {
		profilePicturePreviewUrl.value = props.group.profile_picture_url ?? null;
		return;
	}

	profilePicturePreviewUrl.value = URL.createObjectURL(file);
};

const submit = () => {
	if (!props.group.permissions.can_manage_group) {
		return;
	}

	form
		.transform((data) => ({
			...data,
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
				v-if="!group.permissions.can_manage_group"
				color="warning"
				variant="subtle"
				icon="i-lucide-shield-alert"
				:title="t('groups.settings.general.owner_only_notice')"
			/>

			<UFormField
				:label="t('groups.settings.general.fields.name.label')"
				:error="form.errors.name"
				required
			>
				<UInput
					v-model="form.name"
					class="w-full"
					:placeholder="t('groups.settings.general.fields.name.placeholder')"
					:disabled="!group.permissions.can_manage_group"
				/>
			</UFormField>

			<UFormField
				:label="t('groups.settings.general.fields.description.label')"
				:error="form.errors.description"
			>
				<UTextarea
					v-model="form.description"
					class="w-full"
					:rows="4"
					:placeholder="t('groups.settings.general.fields.description.placeholder')"
					:disabled="!group.permissions.can_manage_group"
				/>
			</UFormField>

			<UFormField
				:label="t('groups.settings.general.fields.profile_picture.label')"
				:help="t('groups.settings.general.fields.profile_picture.help')"
				:error="form.errors.profile_picture"
			>
				<div class="flex flex-col gap-3">
					<label
						class="file-upload-field"
						:class="{ 'pointer-events-none opacity-60': !group.permissions.can_manage_group }"
					>
						<UIcon name="i-lucide-upload" size="16" />
						<span class="text-sm font-medium">
							{{ form.profile_picture?.name || t('groups.settings.general.fields.profile_picture.placeholder') }}
						</span>
						<input
							class="sr-only"
							type="file"
							accept="image/*"
							:disabled="!group.permissions.can_manage_group"
							@change="updateProfilePicture"
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
				:label="t('groups.settings.general.fields.discord_invite_url.label')"
				:help="t('groups.settings.general.fields.discord_invite_url.help')"
				:error="form.errors.discord_invite_url"
			>
				<UInput
					v-model="form.discord_invite_url"
					class="w-full"
					:placeholder="t('groups.settings.general.fields.discord_invite_url.placeholder')"
					:disabled="!group.permissions.can_manage_group"
				/>
			</UFormField>

			<UFormField
				:label="t('groups.settings.general.fields.datacenter.label')"
				:error="form.errors.datacenter"
				required
			>
				<USelect
					v-model="form.datacenter"
					class="w-full"
					:items="datacenterOptions"
					value-key="value"
					:placeholder="t('groups.settings.general.fields.datacenter.placeholder')"
					:disabled="!group.permissions.can_manage_group"
				/>
			</UFormField>

			<div class="toggle-block">
				<div class="flex flex-col gap-1">
					<p class="font-medium">{{ t('groups.settings.general.fields.is_public.label') }}</p>
					<p class="text-sm text-muted">{{ t('groups.settings.general.fields.is_public.help') }}</p>
				</div>
				<USwitch v-model="form.is_public" :disabled="!group.permissions.can_manage_group" />
			</div>

			<div class="toggle-block">
				<div class="flex flex-col gap-1">
					<p class="font-medium">{{ t('groups.settings.general.fields.is_visible.label') }}</p>
					<p class="text-sm text-muted">{{ t('groups.settings.general.fields.is_visible.help') }}</p>
				</div>
				<USwitch v-model="form.is_visible" :disabled="!group.permissions.can_manage_group" />
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
					:disabled="!group.permissions.can_manage_group"
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
</style>
