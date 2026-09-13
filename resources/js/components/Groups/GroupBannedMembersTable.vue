<script setup lang="ts">
import type {
	GroupBannedMemberRecord,
	GroupBannedMembersTableModerationController,
	GroupBannedMemberTableRow,
	GroupMemberCharacter,
	GroupMemberNotesController,
} from "@/Types/Groups";
import { getPaginationRowModel } from "@tanstack/vue-table";
import { computed, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import MemberNotesButton from "@/components/Shared/Notes/MemberNotesButton.vue";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const props = defineProps<{
	bannedMembers: GroupBannedMemberRecord[]
	notes: GroupMemberNotesController
	moderation: GroupBannedMembersTableModerationController
}>();

const { t } = useI18n();
const bannedTable = useTemplateRef('bannedTable');

const bannedPagination = ref({
	pageIndex: 0,
	pageSize: 6,
});
const bannedGlobalFilter = ref('');

const bannedCountLabel = computed(() => t('groups.members.bans.count', { count: props.bannedMembers.length }));
const notAvailableLabel = computed(() => t('groups.members.roster.not_available'));

const formatShortDate = (value: string | null) => {
	if (!value) {
		return notAvailableLabel.value;
	}

	return createDateTimeFormatter(undefined, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).format(new Date(value));
};

const summarizeCharacters = (characters: GroupMemberCharacter[]) => {
	return characters
		.map((character) => `${character.name} ${character.world} ${character.is_primary ? 'primary' : ''}`.trim())
		.join(' ');
};

const bannedTableData = computed<GroupBannedMemberTableRow[]>(() => props.bannedMembers.map((member) => ({
	...member,
	name_display: member.name ?? notAvailableLabel.value,
	reason_display: member.reason || t('groups.members.bans.no_reason'),
	banned_by_name: member.banned_by?.name ?? t('groups.members.bans.system'),
	character_summary: summarizeCharacters(member.characters),
})));

const bannedColumns = computed(() => [
	{ accessorKey: 'name_display', header: t('groups.members.bans.columns.member') },
	{ accessorKey: 'character_summary', header: t('groups.members.bans.columns.characters') },
	{ accessorKey: 'reason_display', header: t('groups.members.bans.columns.reason') },
	{ accessorKey: 'banned_by_name', header: t('groups.members.bans.columns.banned_by') },
	{ accessorKey: 'banned_at', header: t('groups.members.bans.columns.banned_at') },
	{ id: 'actions', header: t('general.actions') },
]);

const filteredRowCount = computed(() => bannedTable.value?.tableApi?.getFilteredRowModel().rows.length ?? 0);
const shouldFixTableHeight = computed(() => filteredRowCount.value > bannedPagination.value.pageSize);

watch(bannedGlobalFilter, () => {
	bannedPagination.value.pageIndex = 0;
});
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
				<div class="flex flex-col gap-1">
					<p class="font-semibold text-md">{{ t('groups.members.bans.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.members.bans.subtitle') }}</p>
				</div>
				<div class="flex w-full items-center gap-2 sm:w-auto">
					<UInput
						v-model="bannedGlobalFilter"
						class="min-w-0 flex-1 sm:w-72 sm:flex-none"
						icon="i-lucide-search"
						:placeholder="t('groups.members.bans.search_placeholder')"
					/>
					<UBadge :label="bannedCountLabel" color="neutral" variant="subtle" />
				</div>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div :class="shouldFixTableHeight ? 'h-[26rem] overflow-auto' : 'overflow-auto'">
				<UTable
					ref="bannedTable"
					v-model:pagination="bannedPagination"
					v-model:global-filter="bannedGlobalFilter"
					:data="bannedTableData"
					:columns="bannedColumns"
					:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
					class="w-full"
				>
					<template #name_display-cell="{ row }">
						<div class="flex items-center gap-3">
							<div v-if="row.original.avatar_url" class="h-10 w-10 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
								<img
									:src="row.original.avatar_url"
									:alt="t('general.image_alt.avatar', { name: row.original.name_display })"
									class="h-full w-full object-cover"
								>
							</div>
							<div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
								<UIcon name="i-lucide-user" size="16" class="text-muted" />
							</div>

							<div class="min-w-0">
								<p class="font-semibold">{{ row.original.name_display }}</p>
							</div>
						</div>
					</template>

					<template #character_summary-cell="{ row }">
						<div v-if="row.original.characters.length > 0" class="flex flex-wrap gap-2">
							<div
								v-for="character in row.original.characters"
								:key="character.id"
								class="min-w-36 rounded-sm border border-default bg-muted/20 px-3 py-2"
							>
								<div class="flex min-w-0 items-center gap-2">
									<div v-if="character.avatar_url" class="h-8 w-8 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
										<img
											:src="character.avatar_url"
											:alt="t('general.image_alt.avatar', { name: character.name })"
											class="h-full w-full object-cover"
										>
									</div>
									<div v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
										<UIcon name="i-lucide-user-round" size="12" class="text-muted" />
									</div>
									<p class="truncate font-medium text-sm">{{ character.name }}</p>
									<UBadge
										v-if="character.is_primary"
										:label="t('general.primary')"
										color="warning"
										variant="subtle"
										size="xs"
									/>
								</div>
								<p class="text-xs text-muted">{{ character.world }}</p>
							</div>
						</div>

						<p v-else class="text-sm text-muted">
							{{ t('groups.members.roster.no_characters') }}
						</p>
					</template>

					<template #reason_display-cell="{ row }">
						<p class="max-w-md text-sm text-toned">
							{{ row.original.reason_display }}
						</p>
					</template>

					<template #banned_by_name-cell="{ row }">
						<div class="flex items-center gap-3">
							<div v-if="row.original.banned_by?.avatar_url" class="h-8 w-8 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
								<img
									:src="row.original.banned_by.avatar_url"
									:alt="t('general.image_alt.avatar', { name: row.original.banned_by.name })"
									class="h-full w-full object-cover"
								>
							</div>
							<div v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
								<UIcon name="i-lucide-shield-ban" size="14" class="text-muted" />
							</div>
							<p class="text-sm">{{ row.original.banned_by_name }}</p>
						</div>
					</template>

					<template #banned_at-cell="{ row }">
						<span class="text-sm">{{ formatShortDate(row.original.banned_at) }}</span>
					</template>

					<template #actions-cell="{ row }">
						<div v-if="(row.original.note_summary.can_view && row.original.user_id) || (row.original.permissions.can_unban && row.original.user_id)" class="flex flex-wrap items-center gap-2">
							<MemberNotesButton
								v-if="row.original.user_id"
								:user-id="row.original.user_id"
								:note-summary="row.original.note_summary"
								@open="props.notes.openMemberNotes"
							/>
							<UButton
								v-if="row.original.permissions.can_unban && row.original.user_id"
								color="success"
								variant="subtle"
								icon="i-lucide-undo-2"
								:label="t('groups.members.actions.unban')"
								:loading="props.moderation.unbanForm.processing && props.moderation.memberPendingUnbanId === row.original.user_id"
								@click="props.moderation.unbanMember(row.original)"
							/>
						</div>

						<span v-else class="text-sm text-muted">-</span>
					</template>
				</UTable>
			</div>

			<div class="flex justify-end border-t border-default px-4 pt-4">
				<UPagination
					:page="(bannedTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
					:items-per-page="bannedTable?.tableApi?.getState().pagination.pageSize"
					:total="filteredRowCount"
					@update:page="(page) => bannedTable?.tableApi?.setPageIndex(page - 1)"
				/>
			</div>
		</div>
	</UCard>
</template>
