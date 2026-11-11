<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupSettingsGroup } from '@/Types/GroupSettings'
import { useGroupSettingsNavigation } from '@/composables/useGroupSettingsNavigation'
import GroupGeneralSettings from '@/components/Groups/GroupGeneralSettings.vue'
import GroupInviteSettings from '@/components/Groups/GroupInviteSettings.vue'
import GroupFeatureSettings from '@/components/Groups/GroupFeatureSettings.vue'
import GroupDiscoverySettings from '@/components/Groups/GroupDiscoverySettings.vue'
import GroupShortcutSettings from '@/components/Groups/Settings/GroupShortcutSettings.vue'
import GroupAuditLogPanel from '@/components/Groups/Settings/GroupAuditLogPanel.vue'
import GroupDiscordSettingsPanel from '@/components/Groups/Settings/GroupDiscordSettingsPanel.vue'
import GroupDiscordPreviewControls from '@/components/Groups/Discord/GroupDiscordPreviewControls.vue'
import GroupOwnershipSettings from '@/components/Groups/GroupOwnershipSettings.vue'
import GroupDangerZoneSettings from '@/components/Groups/GroupDangerZoneSettings.vue'

const props = defineProps<{ group: GroupSettingsGroup }>()
const { t } = useI18n()

const categories = computed(() => ([
    { key: 'general', group: 'general', icon: 'i-lucide-settings-2', component: GroupGeneralSettings },
    { key: 'invites', group: 'general', icon: 'i-lucide-link', component: GroupInviteSettings },
    { key: 'features', group: 'general', icon: 'i-lucide-blocks', component: GroupFeatureSettings },
    { key: 'discovery', group: 'management', icon: 'i-lucide-compass', component: GroupDiscoverySettings },
    { key: 'shortcuts', group: 'management', icon: 'i-lucide-mouse-pointer-click', component: GroupShortcutSettings },
    { key: 'audit_log', group: 'management', icon: 'i-lucide-scroll-text', component: GroupAuditLogPanel },
    { key: 'discord', group: 'integrations', icon: 'ic:baseline-discord', component: GroupDiscordSettingsPanel },
    { key: 'ownership', group: 'danger_zone', icon: 'i-lucide-arrow-right-left', component: GroupOwnershipSettings },
    { key: 'danger_zone', group: 'danger_zone', icon: 'i-lucide-trash-2', component: GroupDangerZoneSettings },
] as const).filter(category => {
    if (category.key === 'invites') return props.group.permissions.can_manage_invites
    if (category.key === 'discovery') return props.group.permissions.can_manage_discovery
    if (category.key === 'shortcuts') return props.group.permissions.can_update_group_settings
    if (category.key === 'discord') return props.group.permissions.can_manage_group
    return true
}))
const categoryGroups = computed(() => (['general', 'management', 'integrations', 'danger_zone'] as const).map(key => ({
    key,
    categories: categories.value.filter(category => category.group === key),
})).filter(group => group.categories.length > 0))

const { navigation, isMobileOrTablet, navigationElement, sectionElement, sectionHeading, openSection, showAllSettings } =
    useGroupSettingsNavigation(props.group.id, () => categories.value.map(category => category.key))
const auditLogOpen = computed(() => navigation.activeSection === 'audit_log' && (navigation.sectionOpen || !isMobileOrTablet.value))
const discordOpened = ref(false)
watch(() => navigation.activeSection === 'discord' && (navigation.sectionOpen || !isMobileOrTablet.value), open => {
    if (open) discordOpened.value = true
}, { immediate: true })
</script>

<template>
    <div class="grid w-full items-start gap-6 xl:grid-cols-[20rem_minmax(0,1fr)] xl:gap-8">
        <GroupDiscordPreviewControls v-if="group.permissions.can_manage_group" />
        <aside class="min-w-0 xl:sticky xl:top-[calc(var(--ui-header-height)+1rem)] xl:max-h-[calc(100dvh-var(--ui-header-height)-2rem)] xl:overflow-y-auto xl:p-1"
            :class="navigation.sectionOpen ? 'hidden xl:block' : 'block'">
            <nav ref="navigationElement" :aria-label="t('groups.settings.title')" class="space-y-6 xl:space-y-5">
                <div v-for="categoryGroup in categoryGroups" :key="categoryGroup.key" class="space-y-2">
                    <h2 class="px-1 text-sm font-semibold text-muted">
                        {{ t(`groups.settings.navigation.groups.${categoryGroup.key}`) }}
                    </h2>
                    <UButton v-for="category in categoryGroup.categories" :key="category.key"
                        :data-settings-section="category.key"
                        :aria-current="!isMobileOrTablet && navigation.activeSection === category.key ? 'true' : undefined"
                        aria-controls="group-settings-section"
                        :color="category.group === 'danger_zone' ? 'error' : 'brand'" variant="ghost"
                        class="w-full items-center gap-3 rounded-lg border p-4 text-left whitespace-normal transition-colors"
                        :class="!isMobileOrTablet && navigation.activeSection === category.key
                            ? (category.group === 'danger_zone' ? 'border-error/50 bg-elevation-1 dark:bg-error/10' : 'border-brand/50 bg-elevation-1 dark:bg-brand/10')
                            : 'border-default bg-elevation-1 dark:bg-elevation-2 hover:bg-elevation-3'"
                        @click="openSection(category.key)">
                        <UIcon :name="category.icon" class="size-5 shrink-0"
                            :class="category.group === 'danger_zone' ? 'text-error' : !isMobileOrTablet && navigation.activeSection === category.key ? 'text-brand' : 'text-toned'" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold" :class="category.group === 'danger_zone' ? 'text-error' : 'text-highlighted'">
                                {{ t(`groups.settings.navigation.categories.${category.key}`) }}
                            </span>
                            <span class="mt-1 block truncate text-xs leading-relaxed font-normal text-muted">
                                {{ t(`groups.settings.navigation.descriptions.${category.key}`) }}
                            </span>
                        </span>
                        <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted xl:hidden" />
                    </UButton>
                </div>
            </nav>
        </aside>

        <section id="group-settings-section" ref="sectionElement" aria-labelledby="group-settings-section-title"
            class="min-w-0 scroll-mt-24" :class="navigation.sectionOpen ? 'block' : 'hidden xl:block'">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="group-settings-section-title" ref="sectionHeading" tabindex="-1"
                    class="min-w-0 scroll-mt-24 break-words text-xl font-semibold focus:outline-none">
                    {{ t(`groups.settings.navigation.categories.${navigation.activeSection}`) }}
                </h2>
                <UButton class="shrink-0 xl:hidden" color="neutral" variant="ghost" icon="i-lucide-arrow-left"
                    :label="t('groups.settings.navigation.back')" @click="showAllSettings" />
            </div>

            <!-- Keep forms mounted for unsaved edits. Discord mounts on first open; audit only while visible. -->
            <div v-for="category in categories" v-show="navigation.activeSection === category.key" :key="category.key">
                <component :is="category.component"
                    v-if="category.key === 'audit_log' ? auditLogOpen : category.key === 'discord' ? discordOpened : true" :group="group" />
            </div>
        </section>
    </div>
</template>
