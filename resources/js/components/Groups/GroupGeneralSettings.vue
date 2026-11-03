<script setup lang="ts">
import type { GroupSettingsGroup } from "@/Types/GroupSettings";
import GroupSettingsImageField from "./Settings/GroupSettingsImageField.vue";
import type { GroupJoinMode } from "@/Types/Groups";
import { validateGroupProfilePictureFile } from "@/utils/groupProfilePictureValidation";
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

const inputUi = { base: 'rounded-lg' };
const groupImageMaxBytes = 5 * 1024 * 1024;

const props = defineProps<{ group: GroupSettingsGroup }>();

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
    <UCard class="@container w-full min-w-0 dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-6' }">
        <template #header>
            <div class="space-y-1">
                <h3 class="font-semibold text-highlighted">{{ t('groups.settings.general.title') }}</h3>
                <p class="text-sm text-muted">{{ t('groups.settings.general.subtitle') }}</p>
            </div>
        </template>

        <form class="space-y-7" @submit.prevent="submit">
            <UAlert v-if="!group.permissions.can_update_group_settings" color="warning" variant="subtle"
                icon="i-lucide-shield-alert" class="rounded-lg" :title="t('groups.settings.general.owner_only_notice')" />

            <div class="space-y-5">
                <div class="grid min-w-0 gap-5 @xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <UFormField :label="t('general.name')" :error="form.errors.name" required class="min-w-0">
                        <UInput v-model="nameFieldValue" class="w-full" size="lg" :ui="inputUi"
                            :placeholder="t('groups.settings.general.fields.name.placeholder')"
                            :disabled="!group.permissions.can_update_group_settings" />
                    </UFormField>
                    <UFormField :label="t('general.datacenter')" :error="form.errors.datacenter" required class="min-w-0">
                        <USelect v-model="form.datacenter" class="w-full" size="lg" :ui="inputUi"
                            :items="datacenterOptions" value-key="value"
                            :placeholder="t('groups.settings.general.fields.datacenter.placeholder')"
                            :disabled="!group.permissions.can_update_group_settings" />
                    </UFormField>
                </div>
                <UFormField :label="t('general.description')" :error="form.errors.description">
                    <UTextarea v-model="descriptionFieldValue" class="w-full" size="lg" :rows="3" :ui="inputUi"
                        :placeholder="t('groups.settings.general.fields.description.placeholder')"
                        :disabled="!group.permissions.can_update_group_settings" />
                </UFormField>
                <UFormField :label="t('general.discord_invite_url')" :help="t('groups.settings.general.fields.discord_invite_url.help')"
                    :error="form.errors.discord_invite_url">
                    <UInput v-model="form.discord_invite_url" class="w-full" size="lg" icon="i-lucide-link" :ui="inputUi"
                        :placeholder="t('groups.settings.general.fields.discord_invite_url.placeholder')"
                        :disabled="!group.permissions.can_update_group_settings" />
                </UFormField>
            </div>

            <section class="space-y-5 border-t border-default pt-6">
                <div class="space-y-1">
                    <h4 class="font-semibold text-highlighted">{{ t('groups.settings.general.images_title') }}</h4>
                    <p class="text-sm text-muted">{{ t('groups.settings.general.images_help') }}</p>
                </div>
                <div class="grid min-w-0 gap-6 @xl:grid-cols-[12rem_minmax(0,1fr)]">
                    <GroupSettingsImageField :label="t('general.profile_picture')"
                        :help="t('groups.settings.general.fields.profile_picture.help')"
                        :action-label="t('groups.settings.general.fields.profile_picture.placeholder')"
                        :preview-url="profilePicturePreviewUrl" :preview-alt="t('groups.settings.general.fields.profile_picture.preview_alt')"
                        :filename="form.profile_picture?.name" :error="form.errors.profile_picture"
                        :disabled="!group.permissions.can_update_group_settings" @change="updateImage($event, 'profile_picture')" />
                    <GroupSettingsImageField wide :label="t('groups.settings.general.fields.banner_image.label')"
                        :help="t('groups.settings.general.fields.banner_image.help')"
                        :action-label="t('groups.settings.general.fields.banner_image.placeholder')"
                        :preview-url="bannerImagePreviewUrl" :preview-alt="t('groups.settings.general.fields.banner_image.preview_alt')"
                        :filename="form.banner_image?.name" :error="form.errors.banner_image"
                        :disabled="!group.permissions.can_update_group_settings" @change="updateImage($event, 'banner_image')" />
                </div>
            </section>

            <section class="space-y-5 border-t border-default pt-6">
                <div class="space-y-1">
                    <h4 class="font-semibold text-highlighted">{{ t('groups.settings.general.access_title') }}</h4>
                    <p class="text-sm text-muted">{{ t('groups.settings.general.access_help') }}</p>
                </div>
                <UFormField :label="t('groups.settings.general.fields.join_mode.label')"
                    :help="t('groups.settings.general.fields.join_mode.help')" :error="form.errors.join_mode" required>
                    <USelect v-model="form.join_mode" class="w-full @xl:max-w-sm" size="lg" :ui="inputUi"
                        :items="joinModeOptions" value-key="value"
                        :placeholder="t('groups.settings.general.fields.join_mode.placeholder')"
                        :disabled="!group.permissions.can_update_group_settings" />
                </UFormField>
                <div class="flex items-start justify-between gap-4 rounded-lg border border-default p-4">
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium text-highlighted">{{ t('groups.settings.general.fields.is_visible.label') }}</p>
                        <p class="text-sm text-muted">{{ t('groups.settings.general.fields.is_visible.help') }}</p>
                    </div>
                    <USwitch v-model="form.is_visible" color="brand" class="shrink-0"
                        :aria-label="t('groups.settings.general.fields.is_visible.label')" :disabled="!group.permissions.can_update_group_settings" />
                </div>
                <div class="flex items-start gap-3 rounded-lg bg-muted/30 p-4" aria-live="polite">
                    <UIcon name="i-lucide-info" class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium text-highlighted">{{ t('groups.settings.general.visibility_summary_label') }}</p>
                        <p class="text-sm leading-relaxed text-muted">{{ visibilitySummary }}</p>
                    </div>
                </div>
            </section>

            <div class="flex justify-end border-t border-default pt-5">
                <UButton type="submit" color="brand" size="lg" icon="i-lucide-check" class="w-full justify-center rounded-lg @sm:w-auto"
                    :label="t('general.save')" :loading="form.processing" :disabled="!group.permissions.can_update_group_settings" />
            </div>
        </form>
    </UCard>
</template>
