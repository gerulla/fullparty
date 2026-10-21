<script setup lang="ts">
import type { SettingsUser } from "@/Types/Settings";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { computed, onBeforeUnmount, ref, watch } from "vue";

const profilePictureMaxBytes = 5 * 1024 * 1024;
const profilePictureAccept = ".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp";
const allowedProfilePictureMimeTypes = new Set(["image/jpeg", "image/png", "image/webp"]);
const allowedProfilePictureExtensions = new Set(["jpg", "jpeg", "png", "webp"]);

const props = defineProps<{
	user: SettingsUser
}>();

const { t } = useI18n();
const form = useForm({
	username: props.user.name ?? '',
});
const profilePictureForm = useForm({
	profile_picture: null as File | null,
});
const profilePicturePreviewUrl = ref<string | null>(props.user.avatar_url ?? null);
const profilePictureInput = ref<HTMLInputElement | null>(null);

const userInitials = computed(() => {
	const name = props.user.name?.trim() ?? '';

	if (!name) {
		return '?';
	}

	return name
		.split(/\s+/)
		.slice(0, 2)
		.map((part) => part.charAt(0).toUpperCase())
		.join('');
});

const submit = () => {
	form.post(route('settings.username'));
};

const revokePreviewUrl = (url: string | null) => {
	if (!url || !url.startsWith('blob:')) {
		return;
	}

	URL.revokeObjectURL(url);
};

const replacePreviewUrl = (url: string | null) => {
	revokePreviewUrl(profilePicturePreviewUrl.value);
	profilePicturePreviewUrl.value = url;
};

const isSupportedProfilePictureFile = (file: File) => {
	const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
	const mimeType = file.type.toLowerCase();

	return allowedProfilePictureExtensions.has(extension)
		&& (mimeType === "" || allowedProfilePictureMimeTypes.has(mimeType));
};

const updateProfilePicture = (event: Event) => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;
	const fallbackUrl = props.user.avatar_url ?? null;

	profilePictureForm.clearErrors("profile_picture");
	profilePictureForm.profile_picture = file;

	if (!file) {
		replacePreviewUrl(fallbackUrl);
		return;
	}

	if (!isSupportedProfilePictureFile(file)) {
		profilePictureForm.profile_picture = null;
		replacePreviewUrl(fallbackUrl);
		profilePictureForm.setError("profile_picture", t("settings.account.profile_picture_invalid_format"));
		target.value = "";
		return;
	}

	if (file.size > profilePictureMaxBytes) {
		profilePictureForm.profile_picture = null;
		replacePreviewUrl(fallbackUrl);
		profilePictureForm.setError("profile_picture", t("settings.account.profile_picture_too_large"));
		target.value = "";
		return;
	}

	replacePreviewUrl(URL.createObjectURL(file));
};

const submitProfilePicture = () => {
	if (!profilePictureForm.profile_picture) {
		return;
	}

	profilePictureForm.post(route("settings.profile-picture"), {
		forceFormData: true,
		preserveScroll: true,
		onSuccess: () => {
			profilePictureForm.reset("profile_picture");
			profilePictureForm.clearErrors();

			if (profilePictureInput.value) {
				profilePictureInput.value.value = "";
			}
		},
	});
};

watch(
	() => props.user.avatar_url,
	(avatarUrl) => {
		replacePreviewUrl(avatarUrl ?? null);
	},
);

onBeforeUnmount(() => {
	revokePreviewUrl(profilePicturePreviewUrl.value);
});
</script>

<template>
	<UCard class="h-full w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-user" class="mr-2" size="22" />
				<p>{{ t('settings.account.title') }}</p>
			</div>
		</template>

		<div class="w-full flex flex-col items-start gap-4">
			<form class="w-full flex flex-col gap-4" @submit.prevent="submitProfilePicture">
				<div class="flex flex-col gap-4 sm:flex-row sm:items-center">
					<div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-full border border-default bg-muted/40 text-xl font-semibold text-muted">
						<img
							v-if="profilePicturePreviewUrl"
							:src="profilePicturePreviewUrl"
							:alt="t('settings.account.profile_picture_preview_alt')"
							class="h-full w-full object-cover"
						>
						<span v-else>{{ userInitials }}</span>
					</div>

					<div class="flex min-w-0 flex-1 flex-col gap-3">
						<div>
							<p class="font-semibold">{{ t('settings.account.profile_picture') }}</p>
							<p class="text-sm text-muted">{{ t('settings.account.profile_picture_help') }}</p>
						</div>

						<UFormField
							:help="t('settings.account.profile_picture_max_size')"
							:error="profilePictureForm.errors.profile_picture"
						>
							<label class="file-upload-field">
								<UIcon name="i-lucide-image-up" size="16" />
								<span class="truncate text-sm font-medium">
									{{ profilePictureForm.profile_picture?.name || t('settings.account.profile_picture_placeholder') }}
								</span>
								<input
									ref="profilePictureInput"
									class="sr-only"
									type="file"
									:accept="profilePictureAccept"
									@change="updateProfilePicture"
								>
							</label>
						</UFormField>
					</div>
				</div>

				<div class="flex justify-end">
					<UButton
						type="submit"
						:label="t('settings.account.update_profile_picture')"
						size="lg"
						color="neutral"
						:loading="profilePictureForm.processing"
						:disabled="!profilePictureForm.profile_picture"
					/>
				</div>
			</form>

			<form @submit.prevent="submit" class="w-full flex flex-col items-start gap-4">
				<UFormField class="w-full" :label="t('general.username')">
					<UInput
						v-model="form.username"
						:placeholder="t('general.username')"
						size="xl"
						class="w-full"
						autocomplete="username"
					/>
				</UFormField>

				<UFormField class="w-full" :label="t('general.email')">
					<UInput
						:model-value="props.user.email"
						:placeholder="t('general.email')"
						size="xl"
						class="w-full"
						disabled
					/>
				</UFormField>

				<UButton
					type="submit"
					:label="t('settings.account.save')"
					size="lg"
					color="neutral"
					:loading="form.processing"
				/>
			</form>


		</div>
	</UCard>
</template>

<style scoped>
@reference '../../../css/app.css';

.file-upload-field {
	@apply flex w-full cursor-pointer items-center gap-2 rounded-sm border border-dashed border-muted px-3 py-3 transition hover:border-brand;
}
</style>
