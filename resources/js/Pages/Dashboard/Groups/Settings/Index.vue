<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import GroupGeneralSettings from "@/components/Groups/GroupGeneralSettings.vue";
import GroupInviteSettings from "@/components/Groups/GroupInviteSettings.vue";
import GroupOwnershipSettings from "@/components/Groups/GroupOwnershipSettings.vue";
import GroupDangerZoneSettings from "@/components/Groups/GroupDangerZoneSettings.vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: any
}>();

const { t } = useI18n();

const accessBadge = computed(() => {
	if (props.group.current_user_role === 'owner') {
		return {
			label: t('groups.settings.access.owner'),
			color: 'warning',
			icon: 'i-lucide-crown',
		};
	}

	return {
		label: t('groups.settings.access.moderator'),
		color: 'primary',
		icon: 'i-lucide-shield',
	};
});
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.settings.title')"
			:subtitle="t('groups.settings.subtitle')"
		>
			<UBadge
				size="lg"
				variant="subtle"
				class="min-w-44 justify-center py-2"
				:color="accessBadge.color"
				:icon="accessBadge.icon"
				:label="accessBadge.label"
			/>
		</PageHeader>

		<div class="mt-4 grid grid-cols-2 gap-6">
			<GroupGeneralSettings :group="group" />
			<div class="flex h-full w-full flex-col gap-6">
				<GroupInviteSettings :group="group" />
				<GroupOwnershipSettings :group="group" />
				<GroupDangerZoneSettings :group="group" />
			</div>
		</div>
	</div>
</template>

<style scoped>

</style>
