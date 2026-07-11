<script setup lang="ts">
// Group members page: internal roster view for reviewing members, linked characters, and moderation actions.
import type { GroupBannedMemberRecord, GroupMemberManagementGroup, GroupMemberRecord } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AccessBadge from "@/components/Groups/AccessBadge.vue";
import GroupMembersTable from "@/components/Groups/GroupMembersTable.vue";
import PageHeader from "@/components/PageHeader.vue";
import MembersNotesModal from "@/components/Shared/Notes/MembersNotesModal.vue";
import { useGroupMemberModeration } from "@/composables/useGroupMemberModeration";
import { useMemberNotes } from "@/composables/useMemberNotes";

const props = defineProps<{
	group: GroupMemberManagementGroup
	members: GroupMemberRecord[]
	bannedMembers: GroupBannedMemberRecord[]
}>();

const { t } = useI18n();
const memberNotes = useMemberNotes({
	groupSlug: computed(() => props.group.slug),
});
const memberModeration = useGroupMemberModeration({
	groupSlug: computed(() => props.group.slug),
	groupName: computed(() => props.group.name),
});
</script>

<template>
	<div class="w-full">
		<MembersNotesModal :notes="memberNotes" />

		<PageHeader
			:title="t('groups.members.title')"
			:subtitle="t('groups.members.subtitle')"
		>
			<AccessBadge :role="group.current_user_role" />
		</PageHeader>

		<div class="mt-4">
			<div class="flex flex-col gap-6">
				<GroupMembersTable
					:group-slug="group.slug"
					:members="members"
					:banned-members="bannedMembers"
					:can-view-bans="group.permissions.can_view_bans"
					:can-view-activity-summary="group.permissions.can_view_member_activity_summary"
					:notes="memberNotes"
					:moderation="memberModeration"
				/>
			</div>
		</div>
	</div>
</template>

<style scoped>

</style>
