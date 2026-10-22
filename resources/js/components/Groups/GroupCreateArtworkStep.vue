<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { DatacenterRegion, GroupCreateArtworkController, GroupCreateArtworkField, GroupCreateIdentity } from '@/Types/Groups'
import { groupImageMaxBytes, groupProfilePictureAccept } from '@/utils/groupProfilePictureValidation'

defineProps<{
    identity: GroupCreateIdentity
    datacenter: string | null
    region: DatacenterRegion | null
    artwork: GroupCreateArtworkController
}>()
const emit = defineEmits<{ continue: [] }>()
const { t } = useI18n()
const fields: GroupCreateArtworkField[] = ['profile_picture', 'banner_image']
const uploadUi = {
    base: 'min-h-36 rounded-lg bg-elevation-1 shadow-sm transition-colors data-[dragging=true]:border-brand-500 data-[dragging=true]:bg-brand-50 data-[dragging=true]:ring-2 data-[dragging=true]:ring-brand-500 dark:bg-elevation-4 dark:shadow-none dark:data-[dragging=true]:border-brand-400 dark:data-[dragging=true]:bg-brand-950/40 dark:data-[dragging=true]:ring-brand-400',
    label: 'text-sm font-medium',
    description: 'text-xs text-muted',
    file: 'min-w-0 rounded-lg bg-elevation-1 dark:bg-elevation-3',
    fileName: 'truncate',
}
</script>

<template>
    <div class="mx-auto w-full max-w-2xl space-y-8 pt-4 sm:pt-6">
        <UCard as="article" :aria-label="t('groups.index.create_flow.artwork.preview')"
            class="mx-auto w-full min-w-0 max-w-128 divide-y-0 overflow-hidden rounded-xl border border-neutral-200 bg-elevation-1 shadow-sm ring-0 dark:border-default dark:bg-elevation-2 dark:shadow-none"
            :ui="{ header: 'relative h-32 p-0 sm:p-0', body: 'space-y-5 px-5 pt-12 pb-5 sm:px-6 sm:pt-12 sm:pb-6' }">
            <template #header>
                <div aria-hidden="true" class="absolute inset-0 overflow-hidden bg-linear-to-br from-brand-950 via-brand-800 to-brand-700">
                    <img v-if="artwork.previewUrls.banner_image" :src="artwork.previewUrls.banner_image" alt="" class="h-full w-full object-cover">
                    <div v-if="artwork.previewUrls.banner_image" class="absolute inset-0 bg-linear-to-t from-neutral-950/30 to-neutral-950/10" />
                </div>
                <UAvatar :src="artwork.previewUrls.profile_picture || undefined" :alt="identity.name" icon="i-lucide-users"
                    aria-hidden="true" class="absolute -bottom-8 left-5 z-10 size-18 rounded-lg bg-elevation-1 ring-4 ring-elevation-1 sm:left-6 dark:bg-elevation-2 dark:ring-elevation-2"
                    :ui="{ icon: 'size-8 text-muted', image: 'object-cover' }" />
            </template>
            <div class="min-w-0 space-y-2">
                <h2 class="text-xl font-semibold leading-6 text-highlighted break-words [overflow-wrap:anywhere]">{{ identity.name }}</h2>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <span v-if="datacenter" class="inline-flex items-center gap-1">
                        <UIcon name="i-lucide-server" class="size-3 shrink-0" aria-hidden="true" />{{ datacenter }}
                    </span>
                    <span v-if="datacenter && region" aria-hidden="true">·</span>
                    <span v-if="region" class="inline-flex items-center gap-1">
                        <UIcon name="i-lucide-globe" class="size-3 shrink-0" aria-hidden="true" />{{ region }}
                    </span>
                </div>
            </div>
            <p class="line-clamp-3 min-h-12 text-sm leading-6 italic text-muted break-words [overflow-wrap:anywhere]">
                {{ identity.description || t('groups.index.table.no_description') }}
            </p>
        </UCard>

        <div class="grid gap-6 md:grid-cols-2">
            <UFormField v-for="field in fields" :key="field" :name="field"
                :label="field === 'profile_picture' ? t('general.profile_picture') : t('groups.index.create_modal.fields.banner_image.label')"
                :hint="t('groups.index.create_flow.identity.optional')"
                :help="t(`groups.index.create_flow.artwork.${field}_help`)"
                :error="artwork.errors[field] ? t(artwork.errors[field]) : undefined">
                <UFileUpload :model-value="artwork.files[field]" :accept="groupProfilePictureAccept"
                    :label="t(`groups.index.create_flow.artwork.${field}_upload`)"
                    :description="t('groups.index.create_flow.artwork.file_requirements', { max: groupImageMaxBytes / 1024 / 1024 })"
                    :icon="field === 'profile_picture' ? 'i-lucide-image-up' : 'i-lucide-panels-top-left'"
                    color="brand" variant="area" layout="list" :dropzone="true" :file-image="false" :ui="uploadUi"
                    @update:model-value="artwork.selectFile(field, $event)" />
            </UFormField>
        </div>

        <div class="flex justify-center">
            <UButton color="brand" size="lg" trailing-icon="i-lucide-arrow-right"
                class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40" :disabled="!artwork.canContinue"
                :loading="artwork.checking.profile_picture || artwork.checking.banner_image" @click="emit('continue')">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </div>
</template>
