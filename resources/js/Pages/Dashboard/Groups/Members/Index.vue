<script setup lang="ts">
// Group members page: internal roster view for reviewing members, linked characters, and moderation actions.
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import GroupMembersManagement from "@/components/Groups/GroupMembersManagement.vue";

const props = defineProps<{
	group: any
	members: Array<any>
	bannedMembers: Array<any>
}>();

const { t } = useI18n();

const accessBadge = computed(() => {
	if (props.group.current_user_role === 'owner') {
		return {
			label: t('groups.members.access.owner'),
			color: 'warning',
			icon: 'i-lucide-crown',
		};
	}

	if (props.group.current_user_role === 'moderator') {
		return {
			label: t('groups.members.access.moderator'),
			color: 'primary',
			icon: 'i-lucide-shield',
		};
	}

	return {
		label: t('groups.members.access.member'),
		color: 'neutral',
		icon: 'i-lucide-user',
	};
});
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.members.title')"
			:subtitle="t('groups.members.subtitle')"
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

		<div class="mt-4">
			<GroupMembersManagement
				:group="group"
				:members="members"
				:banned-members="bannedMembers"
			/>
		</div>
	</div>
</template>

<style scoped>

</style>
