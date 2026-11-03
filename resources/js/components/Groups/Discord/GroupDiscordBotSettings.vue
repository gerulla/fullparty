<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GroupDiscordSettingsData } from '../../../Types/GroupDiscord'
import { useGroupDiscordSettingsForm } from '@/composables/useGroupDiscordSettingsForm'
import GroupDiscordRoleSelectMenu from './GroupDiscordRoleSelectMenu.vue'

const props = defineProps<GroupDiscordSettingsData & { reload: () => Promise<void>; preview?: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const {
    settingsForm, refreshingSnapshot, refreshSnapshot, canEditSettings,
    botLogChannelOptions, memberFacingChannelOptions, moderationRoleMenuOptions,
    templateRoleMenuOptions, activityTypeMenuOptions,
    updateOverrideActivity, updateOverrideRole, removeRoleTemplateOverride, newOverrideActivityId, newOverrideRoleId,
    canAddOverride, addRoleTemplateOverride, saveSettings,
} = useGroupDiscordSettingsForm(props, () => props.reload(), () => emit('changed'))
const selectUi = { base: 'rounded-lg', content: 'max-w-[calc(100vw-2rem)]', itemLabel: 'whitespace-normal break-words' }
</script>

<template>
    <UCard class="@container min-w-0 dark:bg-elevated/25">
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1 basis-48 space-y-1">
                    <h3 class="font-semibold text-highlighted">{{ t('groups.discord.settings.title') }}</h3>
                    <p class="text-sm text-muted">{{ t('groups.discord.settings.subtitle') }}</p>
                </div>
                <UButton type="button" color="neutral" variant="soft" icon="i-lucide-refresh-cw" class="shrink-0 rounded-lg"
                    :loading="refreshingSnapshot" :disabled="!integration || preview" @click="refreshSnapshot">
                    {{ t('groups.discord.actions.refresh_snapshot') }}
                </UButton>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="saveSettings">
            <UAlert v-if="!snapshot" color="warning" variant="soft" icon="i-lucide-rotate-cw" class="rounded-lg"
                :title="t('groups.discord.settings.snapshot_unavailable_title')"
                :description="t('groups.discord.settings.snapshot_unavailable_description')" />

            <section class="space-y-4">
                <div class="space-y-1">
                    <h4 class="text-sm font-semibold text-highlighted">{{ t('groups.discord.settings.general_title') }}</h4>
                    <p class="text-sm text-muted">{{ t('groups.discord.settings.general_description') }}</p>
                </div>
                <div class="grid min-w-0 gap-4 @3xl:grid-cols-3">
                    <UFormField :label="t('groups.discord.settings.bot_log_channel')" :error="settingsForm.errors.bot_log_channel_id" class="min-w-0">
                        <USelectMenu v-model="settingsForm.bot_log_channel_id" :aria-label="t('groups.discord.settings.bot_log_channel')" class="w-full min-w-0" size="lg" value-key="value"
                            :items="botLogChannelOptions" :disabled="!canEditSettings" :ui="selectUi" />
                    </UFormField>
                    <UFormField :label="t('groups.discord.settings.member_facing_channel')" :error="settingsForm.errors.member_facing_channel_id" class="min-w-0">
                        <USelectMenu v-model="settingsForm.member_facing_channel_id" :aria-label="t('groups.discord.settings.member_facing_channel')" class="w-full min-w-0" size="lg" value-key="value"
                            :items="memberFacingChannelOptions" :disabled="!canEditSettings" :ui="selectUi" />
                    </UFormField>
                    <UFormField :label="t('groups.discord.settings.moderation_role')" :error="settingsForm.errors.moderation_role_id" class="min-w-0">
                        <GroupDiscordRoleSelectMenu v-model="settingsForm.moderation_role_id" :aria-label="t('groups.discord.settings.moderation_role')" :items="moderationRoleMenuOptions" :disabled="!canEditSettings" />
                    </UFormField>
                </div>
                <div class="flex items-start justify-between gap-4 rounded-lg border border-default p-4">
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium text-highlighted">{{ t('groups.discord.settings.name_sync') }}</p>
                        <p class="text-sm text-muted">{{ t('groups.discord.settings.name_sync_hint') }}</p>
                    </div>
                    <USwitch v-model="settingsForm.name_sync_enabled" color="brand" class="shrink-0"
                        :aria-label="t('groups.discord.settings.name_sync')" :disabled="!canEditSettings" />
                </div>
            </section>

            <section class="space-y-4 border-t border-default pt-6">
                <div class="space-y-1">
                    <h4 class="text-sm font-semibold text-highlighted">{{ t('groups.discord.settings.run_title') }}</h4>
                    <p class="text-sm text-muted">{{ t('groups.discord.settings.run_description') }}</p>
                </div>
                <UFormField :label="t('groups.discord.settings.template_role')" :help="t('groups.discord.settings.template_role_description')"
                    :error="settingsForm.errors.template_role_id" class="max-w-xl">
                    <GroupDiscordRoleSelectMenu v-model="settingsForm.template_role_id" :aria-label="t('groups.discord.settings.template_role')" :items="templateRoleMenuOptions" :disabled="!canEditSettings" />
                </UFormField>

                <div class="space-y-3 pt-2">
                    <div class="space-y-1">
                        <h5 class="text-sm font-medium text-highlighted">{{ t('groups.discord.settings.role_overrides_title') }}</h5>
                        <p class="text-sm text-muted">{{ t('groups.discord.settings.role_overrides_description') }}</p>
                    </div>
                    <div v-for="(override, index) in settingsForm.run_role_template_overrides" :key="`${override.activity_id}-${index}`"
                        class="grid min-w-0 gap-3 rounded-lg border border-default p-4 @xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] @xl:items-end">
                        <UFormField :label="t('groups.discord.settings.override_activity')" class="min-w-0">
                            <USelectMenu :model-value="override.activity_id" :aria-label="t('groups.discord.settings.override_activity')" class="w-full min-w-0" size="lg" value-key="value"
                                :items="activityTypeMenuOptions" :disabled="!canEditSettings" :ui="selectUi"
                                @update:model-value="updateOverrideActivity(index, String($event))" />
                        </UFormField>
                        <UFormField :label="t('groups.discord.settings.override_role')" class="min-w-0">
                            <GroupDiscordRoleSelectMenu :model-value="override.role_id" :aria-label="t('groups.discord.settings.override_role')" :items="templateRoleMenuOptions" :disabled="!canEditSettings"
                                @update:model-value="updateOverrideRole(index, $event)" />
                        </UFormField>
                        <UButton type="button" color="error" variant="ghost" icon="i-lucide-trash-2" size="lg" class="justify-self-end rounded-lg"
                            :aria-label="t('groups.discord.settings.remove_override')" :disabled="!canEditSettings"
                            @click="removeRoleTemplateOverride(override.activity_id)" />
                    </div>
                    <p v-if="settingsForm.run_role_template_overrides.length === 0" class="rounded-lg border border-dashed border-default p-4 text-sm text-muted">
                        {{ t('groups.discord.settings.no_role_overrides') }}
                    </p>
                    <div class="grid min-w-0 gap-3 rounded-lg border border-default bg-muted/20 p-4 @xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] @xl:items-end">
                        <UFormField :label="t('groups.discord.settings.override_activity')" class="min-w-0">
                            <USelectMenu v-model="newOverrideActivityId" :aria-label="t('groups.discord.settings.override_activity')" class="w-full min-w-0" size="lg" value-key="value"
                                :items="activityTypeMenuOptions" :disabled="!canEditSettings" :ui="selectUi" />
                        </UFormField>
                        <UFormField :label="t('groups.discord.settings.override_role')" class="min-w-0">
                            <GroupDiscordRoleSelectMenu v-model="newOverrideRoleId" :aria-label="t('groups.discord.settings.override_role')" :items="templateRoleMenuOptions" :disabled="!canEditSettings" />
                        </UFormField>
                        <UButton type="button" color="brand" variant="soft" size="lg" icon="i-lucide-plus" class="justify-center rounded-lg"
                            :disabled="!canEditSettings || !canAddOverride" @click="addRoleTemplateOverride">
                            {{ t('groups.discord.actions.add_override') }}
                        </UButton>
                    </div>
                </div>
            </section>

            <div class="flex justify-end border-t border-default pt-4">
                <UButton type="submit" color="brand" icon="i-lucide-save" size="lg" class="w-full justify-center rounded-lg @sm:w-auto"
                    :loading="settingsForm.processing" :disabled="!canEditSettings">
                    {{ t('groups.discord.actions.save_settings') }}
                </UButton>
            </div>
        </form>
    </UCard>
</template>
