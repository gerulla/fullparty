<script setup lang="ts">
// Group member management surface: searchable, paginated tables for active members and banned members with moderation actions and history.
import { getPaginationRowModel } from "@tanstack/vue-table";
import { computed, ref, useTemplateRef, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		slug: string
		name: string
		current_user_role: string
		permissions: {
			can_manage_members: boolean
			can_manage_roles: boolean
			can_view_bans: boolean
		}
	}
	members: Array<{
		id: number
		name: string
		avatar_url: string | null
		role: string
		joined_at: string | null
		participated_run_count: number
		characters: Array<{
			id: number
			name: string
			world: string
			datacenter: string
			avatar_url: string | null
			is_primary: boolean
		}>
		permissions: {
			can_promote: boolean
			can_demote: boolean
			can_kick: boolean
			can_ban: boolean
		}
	}>
	bannedMembers: Array<{
		id: number
		user_id: number | null
		name: string | null
		avatar_url: string | null
		characters: Array<{
			id: number
			name: string
			world: string
			avatar_url: string | null
			is_primary: boolean
		}>
		reason: string | null
		banned_at: string | null
		banned_by: {
			id: number
			name: string
			avatar_url: string | null
		} | null
		permissions: {
			can_unban: boolean
		}
	}>
}>();

const { t } = useI18n();
const toast = useToast();
const memberTable = useTemplateRef('memberTable');
const bannedTable = useTemplateRef('bannedTable');

const updateRoleForm = useForm({
	role: '',
});

const removeForm = useForm({});
const banForm = useForm({
	reason: '',
});
const unbanForm = useForm({});

const memberPagination = ref({
	pageIndex: 0,
	pageSize: 8,
});
const bannedPagination = ref({
	pageIndex: 0,
	pageSize: 6,
});

const memberGlobalFilter = ref('');
const bannedGlobalFilter = ref('');
const memberPendingRoleUpdateId = ref<number | null>(null);
const memberPendingRemovalId = ref<number | null>(null);
const memberPendingBanId = ref<number | null>(null);
const memberToRemove = ref<(typeof props.members)[number] | null>(null);
const memberToBan = ref<(typeof props.members)[number] | null>(null);
const isKickModalOpen = ref(false);
const isBanModalOpen = ref(false);
const memberPendingUnbanId = ref<number | null>(null);

const memberCountLabel = computed(() => t('groups.members.roster.count', { count: props.members.length }));
const bannedCountLabel = computed(() => t('groups.members.bans.count', { count: props.bannedMembers.length }));

const roleBadge = (role: string) => ({
	owner: {
		label: t('groups.index.roles.owner'),
		color: 'warning',
		icon: 'i-lucide-crown',
	},
	moderator: {
		label: t('groups.index.roles.moderator'),
		color: 'primary',
		icon: 'i-lucide-shield',
	},
	member: {
		label: t('groups.index.roles.member'),
		color: 'neutral',
		icon: 'i-lucide-user',
	},
}[role] ?? {
	label: role,
	color: 'neutral',
	icon: 'i-lucide-user',
});

const formatShortDate = (value: string | null) => {
	if (!value) {
		return t('groups.members.roster.not_available');
	}

	return new Intl.DateTimeFormat(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	}).format(new Date(value));
};

const summarizeCharacters = (characters: Array<{ name: string; world: string; is_primary: boolean }>) => {
	return characters
		.map((character) => `${character.name} ${character.world} ${character.is_primary ? 'primary' : ''}`.trim())
		.join(' ');
};

const memberTableData = computed(() => props.members.map((member) => ({
	...member,
	character_summary: summarizeCharacters(member.characters),
})));

const bannedTableData = computed(() => props.bannedMembers.map((member) => ({
	...member,
	name: member.name ?? t('groups.members.roster.not_available'),
	reason_display: member.reason || t('groups.members.bans.no_reason'),
	banned_by_name: member.banned_by?.name ?? t('groups.members.bans.system'),
	character_summary: summarizeCharacters(member.characters),
})));

const memberColumns = computed(() => [
	{ accessorKey: 'name', header: t('groups.members.table.columns.member') },
	{ accessorKey: 'role', header: t('groups.members.table.columns.role') },
	{ accessorKey: 'joined_at', header: t('groups.members.table.columns.joined_at') },
	{ accessorKey: 'participated_run_count', header: t('groups.members.table.columns.runs_participated') },
	{ accessorKey: 'character_summary', header: t('groups.members.table.columns.characters') },
	{ id: 'actions', header: t('groups.members.table.columns.actions') },
]);

const bannedColumns = computed(() => [
	{ accessorKey: 'name', header: t('groups.members.bans.columns.member') },
	{ accessorKey: 'character_summary', header: t('groups.members.bans.columns.characters') },
	{ accessorKey: 'reason_display', header: t('groups.members.bans.columns.reason') },
	{ accessorKey: 'banned_by_name', header: t('groups.members.bans.columns.banned_by') },
	{ accessorKey: 'banned_at', header: t('groups.members.bans.columns.banned_at') },
	{ id: 'actions', header: t('groups.members.table.columns.actions') },
]);

const shouldFixTableHeight = (tableRef: any, pageSize: number) => {
	return (tableRef?.tableApi?.getFilteredRowModel().rows.length ?? 0) > pageSize;
};

const openKickModal = (member: (typeof props.members)[number]) => {
	memberToRemove.value = member;
	isKickModalOpen.value = true;
};

const closeKickModal = () => {
	isKickModalOpen.value = false;
	memberToRemove.value = null;
};

const openBanModal = (member: (typeof props.members)[number]) => {
	memberToBan.value = member;
	banForm.reason = '';
	isBanModalOpen.value = true;
};

const closeBanModal = () => {
	isBanModalOpen.value = false;
	memberToBan.value = null;
	banForm.reset();
};

const updateMemberRole = (member: (typeof props.members)[number], role: 'moderator' | 'member') => {
	memberPendingRoleUpdateId.value = member.id;
	updateRoleForm.role = role;

	updateRoleForm.put(route('groups.members.update', [props.group.slug, member.id]), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: role === 'moderator'
					? t('groups.members.toasts.promoted')
					: t('groups.members.toasts.demoted'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		},
		onFinish: () => {
			memberPendingRoleUpdateId.value = null;
			updateRoleForm.reset();
		},
	});
};

const removeMember = () => {
	if (!memberToRemove.value) {
		return;
	}

	memberPendingRemovalId.value = memberToRemove.value.id;

	removeForm.delete(route('groups.members.destroy', [props.group.slug, memberToRemove.value.id]), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.members.toasts.removed'),
				color: 'success',
				icon: 'i-lucide-check',
			});
			closeKickModal();
		},
		onFinish: () => {
			memberPendingRemovalId.value = null;
		},
	});
};

const banMember = () => {
	if (!memberToBan.value) {
		return;
	}

	memberPendingBanId.value = memberToBan.value.id;

	banForm.post(route('groups.members.ban', [props.group.slug, memberToBan.value.id]), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.members.toasts.banned'),
				color: 'success',
				icon: 'i-lucide-check',
			});
			closeBanModal();
		},
		onFinish: () => {
			memberPendingBanId.value = null;
		},
	});
};

const unbanMember = (member: (typeof props.bannedMembers)[number]) => {
	memberPendingUnbanId.value = member.user_id;

	unbanForm.delete(route('groups.members.unban', [props.group.slug, member.user_id]), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.members.toasts.unbanned'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		},
		onFinish: () => {
			memberPendingUnbanId.value = null;
		},
	});
};

watch(memberGlobalFilter, () => {
	memberPagination.value.pageIndex = 0;
});

watch(bannedGlobalFilter, () => {
	bannedPagination.value.pageIndex = 0;
});
</script>

<template>
	<div class="flex flex-col gap-6">
		<UCard class="w-full dark:bg-elevated/25">
			<template #header>
				<div class="flex flex-row items-center justify-between gap-4">
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.members.roster.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.members.roster.subtitle') }}</p>
					</div>
					<div class="flex items-center gap-2">
						<UInput
							v-model="memberGlobalFilter"
							class="w-72"
							icon="i-lucide-search"
							:placeholder="t('groups.members.roster.search_placeholder')"
						/>
						<UBadge :label="memberCountLabel" color="neutral" variant="subtle" />
					</div>
				</div>
			</template>

			<div class="flex flex-col gap-4">
				<div :class="shouldFixTableHeight(memberTable, memberPagination.pageSize) ? 'h-[34rem] overflow-auto' : 'overflow-auto'">
					<UTable
						ref="memberTable"
						v-model:pagination="memberPagination"
						v-model:global-filter="memberGlobalFilter"
						:data="memberTableData"
						:columns="memberColumns"
						:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
						class="w-full"
					>
						<template #name-cell="{ row }">
							<div class="flex items-center gap-3">
								<div v-if="row.original.avatar_url" class="h-10 w-10 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
									<img
										:src="row.original.avatar_url"
										:alt="`${row.original.name} avatar`"
										class="h-full w-full object-cover"
									>
								</div>
								<div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
									<UIcon name="i-lucide-user" size="16" class="text-muted" />
								</div>

								<div class="min-w-0">
									<p class="font-semibold">{{ row.original.name }}</p>
								</div>
							</div>
						</template>

						<template #role-cell="{ row }">
							<UBadge
								:label="roleBadge(row.original.role).label"
								:color="roleBadge(row.original.role).color"
								:icon="roleBadge(row.original.role).icon"
								variant="subtle"
								size="sm"
							/>
						</template>

						<template #joined_at-cell="{ row }">
							<span class="text-sm">{{ formatShortDate(row.original.joined_at) }}</span>
						</template>

						<template #participated_run_count-cell="{ row }">
							<UBadge :label="`${row.original.participated_run_count}`" color="neutral" variant="subtle" />
						</template>

						<template #character_summary-cell="{ row }">
							<div v-if="row.original.characters.length > 0" class="flex flex-wrap gap-2">
								<div
									v-for="character in row.original.characters"
									:key="character.id"
									class="character-pill"
								>
									<UUser
										:name="character.name"
										:description="character.datacenter + ' - ' + character.world"
										:avatar="{
										  src: character.avatar_url,
										  loading: 'lazy',
										  icon: 'i-lucide-image'
										}"
									/>
								</div>
							</div>

							<p v-else class="text-sm text-muted">
								{{ t('groups.members.roster.no_characters') }}
							</p>
						</template>

						<template #actions-cell="{ row }">
							<div v-if="row.original.permissions.can_promote || row.original.permissions.can_demote || row.original.permissions.can_kick || row.original.permissions.can_ban" class="flex flex-wrap items-center gap-2">
								<UButton
									v-if="row.original.permissions.can_promote"
									color="primary"
									variant="subtle"
									icon="i-lucide-arrow-up"
									:label="t('groups.members.actions.promote')"
									:loading="updateRoleForm.processing && memberPendingRoleUpdateId === row.original.id"
									@click="updateMemberRole(row.original, 'moderator')"
								/>
								<UButton
									v-if="row.original.permissions.can_demote"
									color="neutral"
									variant="subtle"
									icon="i-lucide-arrow-down"
									:label="t('groups.members.actions.demote')"
									:loading="updateRoleForm.processing && memberPendingRoleUpdateId === row.original.id"
									@click="updateMemberRole(row.original, 'member')"
								/>
								<UButton
									v-if="row.original.permissions.can_kick"
									color="error"
									variant="ghost"
									icon="i-lucide-user-round-x"
									:label="t('groups.members.actions.kick')"
									:loading="removeForm.processing && memberPendingRemovalId === row.original.id"
									@click="openKickModal(row.original)"
								/>
								<UButton
									v-if="row.original.permissions.can_ban"
									color="error"
									variant="subtle"
									icon="i-lucide-ban"
									:label="t('groups.members.actions.ban')"
									:loading="banForm.processing && memberPendingBanId === row.original.id"
									@click="openBanModal(row.original)"
								/>
							</div>

							<span v-else class="text-sm text-muted">-</span>
						</template>
					</UTable>
				</div>

				<div class="flex justify-end border-t border-default px-4 pt-4">
					<UPagination
						:page="(memberTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
						:items-per-page="memberTable?.tableApi?.getState().pagination.pageSize"
						:total="memberTable?.tableApi?.getFilteredRowModel().rows.length"
						@update:page="(page) => memberTable?.tableApi?.setPageIndex(page - 1)"
					/>
				</div>
			</div>
		</UCard>

		<UCard v-if="group.permissions.can_view_bans" class="w-full dark:bg-elevated/25">
			<template #header>
				<div class="flex flex-row items-center justify-between gap-4">
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.members.bans.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.members.bans.subtitle') }}</p>
					</div>
					<div class="flex items-center gap-2">
						<UInput
							v-model="bannedGlobalFilter"
							class="w-72"
							icon="i-lucide-search"
							:placeholder="t('groups.members.bans.search_placeholder')"
						/>
						<UBadge :label="bannedCountLabel" color="neutral" variant="subtle" />
					</div>
				</div>
			</template>

			<div class="flex flex-col gap-4">
				<div :class="shouldFixTableHeight(bannedTable, bannedPagination.pageSize) ? 'h-[26rem] overflow-auto' : 'overflow-auto'">
					<UTable
						ref="bannedTable"
						v-model:pagination="bannedPagination"
						v-model:global-filter="bannedGlobalFilter"
						:data="bannedTableData"
						:columns="bannedColumns"
						:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
						class="w-full"
					>
						<template #name-cell="{ row }">
							<div class="flex items-center gap-3">
								<div v-if="row.original.avatar_url" class="h-10 w-10 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
									<img
										:src="row.original.avatar_url"
										:alt="`${row.original.name} avatar`"
										class="h-full w-full object-cover"
									>
								</div>
								<div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
									<UIcon name="i-lucide-user" size="16" class="text-muted" />
								</div>

								<div class="min-w-0">
									<p class="font-semibold">{{ row.original.name }}</p>
								</div>
							</div>
						</template>

						<template #character_summary-cell="{ row }">
							<div v-if="row.original.characters.length > 0" class="flex flex-wrap gap-2">
								<div
									v-for="character in row.original.characters"
									:key="character.id"
									class="character-pill"
								>
									<div class="flex min-w-0 items-center gap-2">
										<div v-if="character.avatar_url" class="h-8 w-8 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
											<img
												:src="character.avatar_url"
												:alt="`${character.name} avatar`"
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
										:alt="`${row.original.banned_by.name} avatar`"
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
							<div v-if="row.original.permissions.can_unban && row.original.user_id" class="flex flex-wrap items-center gap-2">
								<UButton
									color="success"
									variant="subtle"
									icon="i-lucide-undo-2"
									:label="t('groups.members.actions.unban')"
									:loading="unbanForm.processing && memberPendingUnbanId === row.original.user_id"
									@click="unbanMember(row.original)"
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
						:total="bannedTable?.tableApi?.getFilteredRowModel().rows.length"
						@update:page="(page) => bannedTable?.tableApi?.setPageIndex(page - 1)"
					/>
				</div>
			</div>
		</UCard>
	</div>

	<UModal
		v-model:open="isKickModalOpen"
		:title="t('groups.members.kick_modal.title', { name: memberToRemove?.name ?? '' })"
		:description="t('groups.members.kick_modal.subtitle', { group: group.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<UAlert
					color="error"
					variant="subtle"
					icon="i-lucide-triangle-alert"
					:title="t('groups.members.kick_modal.warning')"
				/>

				<div class="flex justify-end gap-2">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="closeKickModal"
					/>
					<UButton
						color="error"
						icon="i-lucide-user-round-x"
						:label="t('groups.members.actions.kick')"
						:loading="removeForm.processing"
						@click="removeMember"
					/>
				</div>
			</div>
		</template>
	</UModal>

	<UModal
		v-model:open="isBanModalOpen"
		:title="t('groups.members.ban_modal.title', { name: memberToBan?.name ?? '' })"
		:description="t('groups.members.ban_modal.subtitle', { group: group.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<UAlert
					color="error"
					variant="subtle"
					icon="i-lucide-triangle-alert"
					:title="t('groups.members.ban_modal.warning')"
				/>

				<UFormField
					:label="t('groups.members.ban_modal.reason.label')"
					:help="t('groups.members.ban_modal.reason.help')"
					:error="banForm.errors.reason"
				>
					<UTextarea
						v-model="banForm.reason"
						class="w-full"
						:rows="4"
						:placeholder="t('groups.members.ban_modal.reason.placeholder')"
					/>
				</UFormField>

				<div class="flex justify-end gap-2">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="closeBanModal"
					/>
					<UButton
						color="error"
						icon="i-lucide-ban"
						:label="t('groups.members.actions.ban')"
						:loading="banForm.processing"
						@click="banMember"
					/>
				</div>
			</div>
		</template>
	</UModal>
</template>

<style scoped>
@reference '../../../css/app.css';

.character-pill {
	@apply min-w-36 rounded-sm border border-default bg-muted/20 px-3 py-2;
}
</style>
