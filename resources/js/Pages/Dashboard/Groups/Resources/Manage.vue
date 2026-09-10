<script setup lang="ts">
import ResourceWorkspace from '@/components/Groups/Resources/ResourceWorkspace.vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useResourceLibraryManagement } from '@/composables/useResourceLibraryManagement'
import type { ResourceLibrary, ResourceManagementGroup } from '@/Types/GroupResources'

const props = defineProps<{ group: ResourceManagementGroup, library: ResourceLibrary }>()

const { t } = useI18n()
const management = useResourceLibraryManagement(() => props.group.slug, () => props.library)
</script>

<template>
	<Head :title="t('groups.resources.manage.title')" />
	<ResourceWorkspace>
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
