<script setup lang="ts">
import AccessBadge from "@/components/Groups/AccessBadge.vue";
import PageHeader from "@/components/PageHeader.vue";
import GroupGeneralSettings from "@/components/Groups/GroupGeneralSettings.vue";
import GroupFeatureSettings from "@/components/Groups/GroupFeatureSettings.vue";
import GroupInviteSettings from "@/components/Groups/GroupInviteSettings.vue";
import GroupOwnershipSettings from "@/components/Groups/GroupOwnershipSettings.vue";
import GroupDangerZoneSettings from "@/components/Groups/GroupDangerZoneSettings.vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: any
}>();

const { t } = useI18n();
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.settings.title')"
			:subtitle="t('groups.settings.subtitle')"
		>
			<AccessBadge
				:role="group.current_user_role"
				fallback-role="moderator"
			/>
		</PageHeader>

		<div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
			<GroupGeneralSettings :group="group" />
			<div class="flex h-full w-full flex-col gap-6">
				<GroupInviteSettings v-if="group.permissions.can_manage_invites" :group="group" />
				<GroupFeatureSettings :group="group" />
				<GroupOwnershipSettings :group="group" />
				<GroupDangerZoneSettings :group="group" />
			</div>
		</div>
	</div>
</template>

<style scoped>

</style>
