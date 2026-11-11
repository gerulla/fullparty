<script setup lang="ts">
import ResourceWorkspace from '@/components/Groups/Resources/ResourceWorkspace.vue'
import { Head, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useResourceLibraryManagement } from '@/composables/useResourceLibraryManagement'
import type { ResourceCollectionData, ResourceDetailData, ResourceLibrary, ResourceManagementGroup, ResourceWorkspaceData } from '@/Types/GroupResources'

const props = defineProps<{ group: ResourceManagementGroup, library: ResourceLibrary, collections: ResourceCollectionData[], workspace: ResourceWorkspaceData, resource?: ResourceDetailData, create_resource?: boolean }>()

const { t } = useI18n()
const management = useResourceLibraryManagement(() => props.group.slug, () => props.library)
</script>

<template>
	<Head :title="t('groups.resources.manage.title')" />
	<ResourceWorkspace :key="`${group.id}-${resource?.id ?? (create_resource ? 'create' : 'library')}`" :group-slug="group.slug" :collections="collections" :data="workspace" :resource="resource" :library="library" :create-resource="create_resource" @library-changed="router.reload({ only: ['library'] })">
		<template #library-actions>
			<UTooltip v-if="group.permissions.can_update_group_settings" :text="t('groups.resources.library.manage')">
				<UButton
					icon="i-lucide-settings"
					color="neutral"
					variant="ghost"
					size="xs"
					class="shrink-0"
					:aria-label="t('groups.resources.library.manage')"
					@click="management.open"
				/>
			</UTooltip>
		</template>
	</ResourceWorkspace>
</template>
