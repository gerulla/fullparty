<script setup lang="ts">
import type {
	GroupBannedMemberRecord,
	GroupBannedMembersTableModerationController,
	GroupMemberActivitySummary,
	GroupMemberCharacter,
	GroupMemberNotesController,
	GroupMemberRecord,
	GroupMembersTableModerationController,
	GroupRole,
} from "@/Types/Groups";
import axios from "axios";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import GroupMemberActivitySummaryPanel from "@/components/Groups/GroupMemberActivitySummaryPanel.vue";
import MemberNotesButton from "@/components/Shared/Notes/MemberNotesButton.vue";

const pageSize = 15;
const fallbackProfileBackground = "/default-homepage-bg.jpg";

type ViewMode = "list" | "grid";

type ActiveMemberCard = {
	kind: "active"
	id: string
	member: GroupMemberRecord
	displayName: string
	avatarUrl: string | null
	backgroundUrl: string | null
	characters: GroupMemberCharacter[]
	searchText: string
};

type BannedMemberCard = {
	kind: "banned"
	id: string
	member: GroupBannedMemberRecord
	displayName: string
	avatarUrl: string | null
	backgroundUrl: string | null
	characters: GroupMemberCharacter[]
	searchText: string
};

type MemberCard = ActiveMemberCard | BannedMemberCard;

type MemberActivitySummaryState = {
	loading: boolean
	loaded: boolean
	error: boolean
	data: GroupMemberActivitySummary | null
};

const props = withDefaults(defineProps<{
	groupSlug: string
	members: GroupMemberRecord[]
	bannedMembers?: GroupBannedMemberRecord[]
	canViewBans?: boolean
	canViewActivitySummary: boolean
	notes: GroupMemberNotesController
	moderation: GroupMembersTableModerationController & GroupBannedMembersTableModerationController
}>(), {
	bannedMembers: () => [],
	canViewBans: false,
});

const { locale, t } = useI18n();

const search = ref("");
const viewMode = ref<ViewMode>("list");
const showBannedMembers = ref(false);
const visibleCount = ref(pageSize);
const loadMoreSentinel = ref<HTMLElement | null>(null);
const activitySummaryModalOpen = ref(false);
const activitySummaryModalMember = ref<GroupMemberRecord | null>(null);
const memberActivitySummaries = ref<Record<number, MemberActivitySummaryState>>({});
let observer: IntersectionObserver | null = null;

const relativeTimeFormatter = computed(() => new Intl.RelativeTimeFormat(locale.value, {
	numeric: "auto",
	style: "long",
}));

const roleBadge = (role: string) => ({
	owner: {
		label: t("groups.common.roles.owner"),
		color: "warning",
		icon: "i-lucide-crown",
	},
	moderator: {
		label: t("groups.common.roles.moderator"),
		color: "primary",
		icon: "i-lucide-shield",
	},
	admin: {
		label: t("groups.common.roles.admin"),
		color: "secondary",
		icon: "i-lucide-shield-check",
	},
	member: {
		label: t("groups.common.roles.member"),
		color: "neutral",
		icon: "i-lucide-user",
	},
}[role] ?? {
	label: role,
	color: "neutral",
	icon: "i-lucide-user",
});

const bannedBadge = computed(() => ({
	label: t("groups.members.bans.badge"),
	color: "error",
	icon: "i-lucide-ban",
}));

const currentTitle = computed(() => showBannedMembers.value
	? t("groups.members.bans.title")
	: t("groups.members.roster.title"));
const currentSubtitle = computed(() => showBannedMembers.value
	? t("groups.members.bans.subtitle")
	: t("groups.members.roster.subtitle"));
const currentCountLabel = computed(() => showBannedMembers.value
	? t("groups.members.bans.count", { count: props.bannedMembers.length })
	: t("groups.members.roster.count", { count: props.members.length }));
const currentSearchPlaceholder = computed(() => showBannedMembers.value
	? t("groups.members.bans.search_placeholder")
	: t("groups.members.roster.search_placeholder"));
const emptyLabel = computed(() => showBannedMembers.value
	? t("groups.members.bans.empty")
	: t("groups.members.roster.empty"));

const formatRelativeTime = (value: string | null) => {
	if (!value) {
		return t("groups.members.roster.not_available");
	}

	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return t("groups.members.roster.not_available");
	}

	const diffSeconds = Math.round((date.getTime() - Date.now()) / 1000);
	const units = [
		{ unit: "year", seconds: 60 * 60 * 24 * 365 },
		{ unit: "month", seconds: 60 * 60 * 24 * 30 },
		{ unit: "week", seconds: 60 * 60 * 24 * 7 },
		{ unit: "day", seconds: 60 * 60 * 24 },
		{ unit: "hour", seconds: 60 * 60 },
	] as const;

	for (const item of units) {
		if (Math.abs(diffSeconds) >= item.seconds) {
			return relativeTimeFormatter.value.format(Math.round(diffSeconds / item.seconds), item.unit);
		}
	}

	return relativeTimeFormatter.value.format(Math.round(diffSeconds / 60), "minute");
};

const joinedLabel = (member: GroupMemberRecord) => t("groups.members.roster.joined_at", {
	date: formatRelativeTime(member.joined_at),
});

const bannedAtLabel = (member: GroupBannedMemberRecord) => t("groups.members.bans.banned_at_relative", {
	date: formatRelativeTime(member.banned_at),
});

const initialsForName = (name: string | null | undefined) => {
	const parts = (name ?? "")
		.trim()
		.split(/\s+/)
		.filter(Boolean);

	if (parts.length === 0) {
		return "?";
	}

	return parts
		.slice(0, 2)
		.map((part) => part[0]?.toUpperCase() ?? "")
		.join("");
};

const characterSubtitle = (character: GroupMemberCharacter) => [character.datacenter, character.world]
	.filter(Boolean)
	.join(" - ") || character.world;

const featuredCharacter = (characters: GroupMemberCharacter[]) => (
	characters.find((character) => character.is_primary) ?? characters[0] ?? null
);

const featuredCharacterSubtitle = (characters: GroupMemberCharacter[]) => {
	const character = featuredCharacter(characters);

	return character ? characterSubtitle(character) : "";
};

const extraCharacterCount = (characters: GroupMemberCharacter[]) => Math.max(characters.length - 1, 0);

const summarizeCharacters = (characters: GroupMemberCharacter[]) => characters
	.map((character) => `${character.name} ${character.world} ${character.datacenter ?? ""} ${character.is_primary ? "primary" : ""}`.trim())
	.join(" ");

const activeCards = computed<ActiveMemberCard[]>(() => props.members.map((member) => ({
	kind: "active",
	id: `active-${member.id}`,
	member,
	displayName: member.name,
	avatarUrl: member.avatar_url,
	backgroundUrl: member.home_background_image_url ?? null,
	characters: member.characters,
	searchText: [
		member.name,
		roleBadge(member.role).label,
		String(member.participated_run_count),
		joinedLabel(member),
		summarizeCharacters(member.characters),
	].join(" ").toLowerCase(),
})));

const bannedCards = computed<BannedMemberCard[]>(() => props.bannedMembers.map((member) => ({
	kind: "banned",
	id: `banned-${member.id}`,
	member,
	displayName: member.name ?? t("groups.members.bans.unknown_member"),
	avatarUrl: member.avatar_url,
	backgroundUrl: member.home_background_image_url ?? null,
	characters: member.characters,
	searchText: [
		member.name ?? t("groups.members.bans.unknown_member"),
		member.reason ?? t("groups.members.bans.no_reason"),
		member.banned_by?.name ?? t("groups.members.bans.system"),
		bannedAtLabel(member),
		summarizeCharacters(member.characters),
	].join(" ").toLowerCase(),
})));

const currentCards = computed<MemberCard[]>(() => showBannedMembers.value ? bannedCards.value : activeCards.value);
const filteredCards = computed<MemberCard[]>(() => {
	const query = search.value.trim().toLowerCase();

	if (!query) {
		return currentCards.value;
	}

	return currentCards.value.filter((card) => card.searchText.includes(query));
});
const visibleCards = computed(() => filteredCards.value.slice(0, visibleCount.value));
const hasMore = computed(() => visibleCount.value < filteredCards.value.length);
const shouldShowNoResults = computed(() => filteredCards.value.length === 0);

const listViewActive = computed(() => viewMode.value === "list");
const gridViewActive = computed(() => viewMode.value === "grid");

const resetVisibleCards = () => {
	visibleCount.value = pageSize;
};

const loadMore = () => {
	if (!hasMore.value) {
		return;
	}

	visibleCount.value = Math.min(visibleCount.value + pageSize, filteredCards.value.length);
};

const observeLoadMoreSentinel = () => {
	observer?.disconnect();

	if (!observer || !loadMoreSentinel.value || !hasMore.value) {
		return;
	}

	observer.observe(loadMoreSentinel.value);
};

onMounted(() => {
	if (typeof window === "undefined" || !("IntersectionObserver" in window)) {
		return;
	}

	observer = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting)) {
			loadMore();
		}
	}, {
		rootMargin: "240px",
	});

	nextTick(observeLoadMoreSentinel);
});

onBeforeUnmount(() => {
	observer?.disconnect();
});

watch([search, showBannedMembers], resetVisibleCards);
watch([hasMore, visibleCards], async () => {
	await nextTick();
	observeLoadMoreSentinel();
});

const nextPromotedRole = (role: GroupRole) => {
	if (role === "member") {
		return "moderator";
	}

	if (role === "moderator") {
		return "admin";
	}

	return "admin";
};

const nextDemotedRole = (role: GroupRole) => {
	if (role === "admin") {
		return "moderator";
	}

	return "member";
};

const canShowActiveActions = (member: GroupMemberRecord) => props.canViewActivitySummary
	|| member.note_summary.can_view
	|| member.permissions.can_promote
	|| member.permissions.can_demote
	|| member.permissions.can_kick
	|| member.permissions.can_ban;

const canShowBannedActions = (member: GroupBannedMemberRecord) => Boolean(member.user_id)
	&& (member.note_summary.can_view || member.permissions.can_unban);

const canShowCardActions = (card: MemberCard) => card.kind === "active"
	? canShowActiveActions(card.member)
	: canShowBannedActions(card.member);

const defaultActivitySummaryState = (): MemberActivitySummaryState => ({
	loading: false,
	loaded: false,
	error: false,
	data: null,
});

const activitySummaryState = (memberId: number): MemberActivitySummaryState => (
	memberActivitySummaries.value[memberId] ?? defaultActivitySummaryState()
);

const setActivitySummaryState = (memberId: number, state: MemberActivitySummaryState) => {
	memberActivitySummaries.value = {
		...memberActivitySummaries.value,
		[memberId]: state,
	};
};

const loadActivitySummary = async (member: GroupMemberRecord, force = false) => {
	const currentState = activitySummaryState(member.id);

	if (!force && (currentState.loading || currentState.loaded)) {
		return;
	}

	setActivitySummaryState(member.id, {
		...currentState,
		loading: true,
		error: false,
	});

	try {
		const response = await axios.get<{ data: GroupMemberActivitySummary }>(route("groups.dashboard.members.activity-summary", {
			group: props.groupSlug,
			user: member.id,
		}));

		setActivitySummaryState(member.id, {
			loading: false,
			loaded: true,
			error: false,
			data: response.data.data,
		});
	} catch {
		setActivitySummaryState(member.id, {
			loading: false,
			loaded: false,
			error: true,
			data: null,
		});
	}
};

const retryActivitySummary = (member: GroupMemberRecord) => {
	void loadActivitySummary(member, true);
};

const openActivitySummaryModal = (member: GroupMemberRecord) => {
	activitySummaryModalMember.value = member;
	activitySummaryModalOpen.value = true;
	void loadActivitySummary(member);
};

const selectedActivitySummaryState = computed(() => activitySummaryModalMember.value
	? activitySummaryState(activitySummaryModalMember.value.id)
	: defaultActivitySummaryState());

const activitySummaryModalTitle = computed(() => t("groups.members.activity_summary.modal_title", {
	name: activitySummaryModalMember.value?.name ?? t("groups.members.bans.unknown_member"),
}));

const activitySummaryModalDescription = computed(() => t("groups.members.activity_summary.modal_description"));

const retrySelectedActivitySummary = () => {
	if (!activitySummaryModalMember.value) {
		return;
	}

	retryActivitySummary(activitySummaryModalMember.value);
};

watch(activitySummaryModalOpen, (open) => {
	if (!open) {
		activitySummaryModalMember.value = null;
	}
});
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-4">
				<div class="flex flex-col items-start gap-3 lg:flex-row lg:items-center lg:justify-between">
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ currentTitle }}</p>
						<p class="text-sm text-muted">{{ currentSubtitle }}</p>
					</div>

					<UBadge :label="currentCountLabel" color="neutral" variant="subtle" />
				</div>

				<div class="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
					<UInput
						v-model="search"
						class="w-full xl:max-w-md"
						icon="i-lucide-search"
						:placeholder="currentSearchPlaceholder"
					/>

					<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
						<div class="flex w-full gap-1 sm:w-auto">
							<UButton
								class="flex-1 sm:flex-none"
								:color="listViewActive ? 'primary' : 'neutral'"
								:variant="listViewActive ? 'solid' : 'subtle'"
								icon="i-lucide-list"
								:label="t('groups.members.view.list')"
								@click="viewMode = 'list'"
							/>
							<UButton
								class="flex-1 sm:flex-none"
								:color="gridViewActive ? 'primary' : 'neutral'"
								:variant="gridViewActive ? 'solid' : 'subtle'"
								icon="i-lucide-layout-grid"
								:label="t('groups.members.view.blocks')"
								@click="viewMode = 'grid'"
							/>
						</div>

						<UButton
							v-if="props.canViewBans"
							color="neutral"
							variant="subtle"
							:icon="showBannedMembers ? 'i-lucide-users' : 'i-lucide-ban'"
							:label="showBannedMembers ? t('groups.members.view.members') : t('groups.members.view.banned')"
							@click="showBannedMembers = !showBannedMembers"
						/>
					</div>
				</div>
			</div>
		</template>

		<div class="space-y-4">
			<div v-if="shouldShowNoResults" class="flex min-h-52 flex-col items-center justify-center gap-3 border border-dashed border-default bg-muted/10 px-6 py-12 text-center">
				<UIcon :name="showBannedMembers ? 'i-lucide-ban' : 'i-lucide-users'" class="size-8 text-muted" />
				<p class="font-medium text-toned">{{ emptyLabel }}</p>
				<p v-if="search" class="max-w-md text-sm text-muted">
					{{ t('groups.members.view.no_search_results') }}
				</p>
			</div>

			<div v-else-if="listViewActive" class="space-y-4">
				<div
					v-for="card in visibleCards"
					:key="card.id"
					class="overflow-hidden border border-default bg-default/60 shadow-sm transition hover:border-primary/35 hover:bg-elevated/60"
				>
					<div class="flex items-center gap-2 p-3 sm:gap-3 sm:p-4 xl:gap-4 xl:p-5">
						<div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
							<div v-if="card.avatarUrl" class="h-10 w-10 shrink-0 overflow-hidden rounded-full border border-primary/40 bg-muted/30 shadow-sm shadow-primary/10 sm:h-12 sm:w-12 xl:h-16 xl:w-16">
								<img
									:src="card.avatarUrl"
									:alt="`${card.displayName} avatar`"
									class="h-full w-full object-cover"
									loading="lazy"
								>
							</div>
							<div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/40 bg-primary/10 text-base font-semibold text-toned shadow-sm shadow-primary/10 sm:h-12 sm:w-12 sm:text-lg xl:h-16 xl:w-16 xl:text-2xl">
								{{ initialsForName(card.displayName) }}
							</div>

							<div class="min-w-0 space-y-1">
								<div class="min-w-0">
									<p class="truncate text-sm font-semibold leading-tight text-toned sm:text-base lg:text-lg xl:text-2xl" :title="card.displayName">
										{{ card.displayName }}
									</p>
									<p v-if="card.kind === 'active'" class="truncate text-xs text-muted sm:text-sm">
										{{ joinedLabel(card.member) }}
									</p>
									<div v-else class="space-y-0.5 text-xs text-muted sm:text-sm">
										<p class="truncate">{{ bannedAtLabel(card.member) }}</p>
										<p class="truncate">
											{{ t('groups.members.bans.reason_inline', { reason: card.member.reason || t('groups.members.bans.no_reason') }) }}
										</p>
									</div>
								</div>

								<div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
									<template v-if="card.kind === 'active'">
									<UBadge
										:label="roleBadge(card.member.role).label"
										:color="roleBadge(card.member.role).color"
										:icon="roleBadge(card.member.role).icon"
										variant="subtle"
										size="sm"
									/>
									<UBadge
										:label="t('groups.members.roster.runs_badge', { count: card.member.participated_run_count })"
										color="neutral"
										variant="subtle"
										icon="i-lucide-swords"
										size="sm"
									/>
								</template>
								<UBadge
									v-else
									:label="bannedBadge.label"
									:color="bannedBadge.color"
									:icon="bannedBadge.icon"
									variant="subtle"
									size="sm"
								/>
								</div>
							</div>
						</div>

						<div class="hidden h-12 shrink-0 border-l border-default sm:block xl:h-16"></div>

						<div
							class="min-w-0 w-28 shrink-0 sm:w-40 md:w-48 xl:w-72"
							:class="card.kind === 'active' ? 'hidden sm:block' : ''"
						>
							<div
								v-if="featuredCharacter(card.characters)"
								class="flex min-w-0 items-center gap-2 border border-default bg-muted/15 p-1.5 sm:p-2 xl:gap-2.5 xl:p-2.5"
								:title="`${featuredCharacter(card.characters)?.name} - ${featuredCharacter(card.characters)?.world}`"
							>
								<div v-if="featuredCharacter(card.characters)?.avatar_url" class="h-7 w-7 shrink-0 overflow-hidden border border-default bg-muted/30 sm:h-8 sm:w-8 xl:h-10 xl:w-10">
									<img
										:src="featuredCharacter(card.characters)?.avatar_url || undefined"
										:alt="`${featuredCharacter(card.characters)?.name} avatar`"
										class="h-full w-full object-cover"
										loading="lazy"
									>
								</div>
								<div v-else class="flex h-7 w-7 shrink-0 items-center justify-center border border-default bg-primary/10 text-xs font-semibold text-toned sm:h-8 sm:w-8 xl:h-10 xl:w-10 xl:text-sm">
									{{ initialsForName(featuredCharacter(card.characters)?.name) }}
								</div>

								<div class="min-w-0 flex-1">
									<p class="truncate text-xs font-semibold text-toned sm:text-sm">
										{{ featuredCharacter(card.characters)?.name }}
									</p>
									<p class="hidden truncate text-xs leading-tight text-muted sm:block">
										{{ featuredCharacterSubtitle(card.characters) }}
									</p>
								</div>

								<UIcon
									v-if="featuredCharacter(card.characters)?.is_primary"
									name="i-lucide-star"
									class="hidden size-4 shrink-0 text-warning sm:block"
								/>
								<UBadge
									v-if="extraCharacterCount(card.characters) > 0"
									class="hidden sm:inline-flex"
									color="neutral"
									variant="subtle"
									:label="`+${extraCharacterCount(card.characters)}`"
								/>
							</div>

							<div v-else class="flex min-h-10 items-center border border-dashed border-default bg-muted/10 px-2 py-1.5 text-xs text-muted sm:px-3 sm:py-2 sm:text-sm">
								{{ t('groups.members.roster.no_characters') }}
							</div>
						</div>

						<div
							v-if="canShowCardActions(card)"
							class="hidden w-56 shrink-0 grid-cols-3 gap-1 sm:grid sm:w-64 md:w-72 lg:w-80 xl:gap-2"
						>
							<template v-if="card.kind === 'active'">
									<UButton
										v-if="props.canViewActivitySummary"
										class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										color="neutral"
										variant="outline"
										icon="i-lucide-activity"
										size="sm"
										:label="t('groups.members.activity_summary.toggle')"
										@click="openActivitySummaryModal(card.member)"
									/>
									<MemberNotesButton
										class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										:user-id="card.member.id"
										:note-summary="card.member.note_summary"
										color="info"
										variant="outline"
										size="sm"
										@open="props.notes.openMemberNotes"
									/>
									<UButton
										v-if="card.member.permissions.can_promote"
										class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										color="primary"
										variant="outline"
										icon="i-lucide-arrow-up"
										size="sm"
										:label="t('groups.members.actions.promote')"
										:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
										@click="props.moderation.updateMemberRole(card.member, nextPromotedRole(card.member.role))"
									/>
									<UButton
										v-if="card.member.permissions.can_demote"
										class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										color="neutral"
										variant="outline"
										icon="i-lucide-arrow-down"
										size="sm"
										:label="t('groups.members.actions.demote')"
										:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
										@click="props.moderation.updateMemberRole(card.member, nextDemotedRole(card.member.role))"
									/>
									<UButton
										v-if="card.member.permissions.can_kick"
										class="min-h-8 justify-center px-2 sm:col-start-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										color="error"
										variant="outline"
										icon="i-lucide-user-round-x"
										size="sm"
										:label="t('groups.members.actions.kick')"
										:loading="props.moderation.removeForm.processing && props.moderation.memberPendingRemovalId === card.member.id"
										@click="props.moderation.openKickConfirmation(card.member)"
									/>
									<UButton
										v-if="card.member.permissions.can_ban"
										class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
										color="error"
										variant="outline"
										icon="i-lucide-ban"
										size="sm"
										:label="t('groups.members.actions.ban')"
										:loading="props.moderation.banForm.processing && props.moderation.memberPendingBanId === card.member.id"
										@click="props.moderation.openBanConfirmation(card.member)"
									/>
							</template>

							<template v-else>
								<MemberNotesButton
									v-if="card.member.user_id"
									class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
									:user-id="card.member.user_id"
									:note-summary="card.member.note_summary"
									color="info"
									variant="outline"
									size="sm"
									@open="props.notes.openMemberNotes"
								/>
								<UButton
									v-if="card.member.permissions.can_unban && card.member.user_id"
									class="min-h-8 justify-center px-2 sm:min-h-9 xl:min-h-10 xl:px-3"
									color="success"
									variant="outline"
									icon="i-lucide-undo-2"
									size="sm"
									:label="t('groups.members.actions.unban')"
									:loading="props.moderation.unbanForm.processing && props.moderation.memberPendingUnbanId === card.member.user_id"
									@click="props.moderation.unbanMember(card.member)"
								/>
							</template>
						</div>
					</div>

					<div
						v-if="card.kind === 'active' && canShowActiveActions(card.member)"
						class="grid grid-cols-2 gap-2 border-t border-default p-3 sm:hidden"
					>
						<UButton
							v-if="props.canViewActivitySummary"
							class="min-h-10 justify-center"
							color="neutral"
							variant="subtle"
							icon="i-lucide-activity"
							size="sm"
							:label="t('groups.members.activity_summary.toggle')"
							@click="openActivitySummaryModal(card.member)"
						/>
						<MemberNotesButton
							class="min-h-10 justify-center"
							:user-id="card.member.id"
							:note-summary="card.member.note_summary"
							color="info"
							variant="outline"
							size="sm"
							@open="props.notes.openMemberNotes"
						/>
						<UButton
							v-if="card.member.permissions.can_promote"
							class="min-h-10 justify-center"
							color="primary"
							variant="outline"
							icon="i-lucide-arrow-up"
							size="sm"
							:label="t('groups.members.actions.promote')"
							:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
							@click="props.moderation.updateMemberRole(card.member, nextPromotedRole(card.member.role))"
						/>
						<UButton
							v-if="card.member.permissions.can_demote"
							class="min-h-10 justify-center"
							color="neutral"
							variant="outline"
							icon="i-lucide-arrow-down"
							size="sm"
							:label="t('groups.members.actions.demote')"
							:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
							@click="props.moderation.updateMemberRole(card.member, nextDemotedRole(card.member.role))"
						/>
						<UButton
							v-if="card.member.permissions.can_kick"
							class="min-h-10 justify-center"
							color="error"
							variant="outline"
							icon="i-lucide-user-round-x"
							size="sm"
							:label="t('groups.members.actions.kick')"
							:loading="props.moderation.removeForm.processing && props.moderation.memberPendingRemovalId === card.member.id"
							@click="props.moderation.openKickConfirmation(card.member)"
						/>
						<UButton
							v-if="card.member.permissions.can_ban"
							class="min-h-10 justify-center"
							color="error"
							variant="outline"
							icon="i-lucide-ban"
							size="sm"
							:label="t('groups.members.actions.ban')"
							:loading="props.moderation.banForm.processing && props.moderation.memberPendingBanId === card.member.id"
							@click="props.moderation.openBanConfirmation(card.member)"
						/>
					</div>

					<div
						v-if="card.kind === 'banned' && canShowBannedActions(card.member)"
						class="grid grid-cols-2 gap-2 border-t border-default p-3 sm:hidden"
					>
						<MemberNotesButton
							v-if="card.member.user_id"
							class="min-h-10 justify-center"
							:user-id="card.member.user_id"
							:note-summary="card.member.note_summary"
							color="info"
							variant="outline"
							size="sm"
							@open="props.notes.openMemberNotes"
						/>
						<UButton
							v-if="card.member.permissions.can_unban && card.member.user_id"
							class="min-h-10 justify-center"
							color="success"
							variant="outline"
							icon="i-lucide-undo-2"
							size="sm"
							:label="t('groups.members.actions.unban')"
							:loading="props.moderation.unbanForm.processing && props.moderation.memberPendingUnbanId === card.member.user_id"
							@click="props.moderation.unbanMember(card.member)"
						/>
					</div>
				</div>
			</div>

			<div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
				<div
					v-for="card in visibleCards"
					:key="card.id"
					class="overflow-hidden border border-default bg-default/60 shadow-sm transition hover:border-primary/35 hover:bg-elevated/60"
				>
					<div
						class="h-24 bg-cover bg-center bg-muted/30"
						:style="{ backgroundImage: `url('${card.backgroundUrl || fallbackProfileBackground}')` }"
					/>

					<div class="px-4 pb-4">
						<div class="-mt-10 flex justify-center">
							<div v-if="card.avatarUrl" class="h-20 w-20 overflow-hidden rounded-full border-4 border-default bg-muted/30 shadow-sm">
								<img
									:src="card.avatarUrl"
									:alt="`${card.displayName} avatar`"
									class="h-full w-full object-cover"
									loading="lazy"
								>
							</div>
							<div v-else class="flex h-20 w-20 items-center justify-center rounded-full border-4 border-default bg-muted/20 shadow-sm">
								<UIcon name="i-lucide-user" size="26" class="text-muted" />
							</div>
						</div>

						<div class="mt-3 space-y-3 text-center">
							<p class="break-words text-lg font-semibold leading-tight text-toned [overflow-wrap:anywhere]">{{ card.displayName }}</p>

							<div class="flex flex-wrap items-center justify-center gap-2">
								<template v-if="card.kind === 'active'">
									<UBadge
										:label="roleBadge(card.member.role).label"
										:color="roleBadge(card.member.role).color"
										:icon="roleBadge(card.member.role).icon"
										variant="subtle"
										size="sm"
									/>
									<UBadge
										:label="t('groups.members.roster.runs_badge', { count: card.member.participated_run_count })"
										color="neutral"
										variant="subtle"
										icon="i-lucide-swords"
										size="sm"
									/>
									<UBadge
										:label="joinedLabel(card.member)"
										color="neutral"
										variant="outline"
										icon="i-lucide-calendar-days"
										size="sm"
									/>
								</template>
								<template v-else>
									<UBadge
										:label="bannedBadge.label"
										:color="bannedBadge.color"
										:icon="bannedBadge.icon"
										variant="subtle"
										size="sm"
									/>
									<UBadge
										:label="bannedAtLabel(card.member)"
										color="neutral"
										variant="outline"
										icon="i-lucide-calendar-x"
										size="sm"
									/>
								</template>
							</div>
						</div>

						<div class="mt-4">
							<div v-if="card.characters.length > 0" class="flex flex-wrap justify-center gap-2">
								<div
									v-for="character in card.characters"
									:key="character.id"
									class="inline-flex max-w-full items-center gap-2 rounded-sm border border-default bg-muted/20 px-2.5 py-2 text-left"
									:title="`${character.name} - ${character.world}`"
								>
									<div v-if="character.avatar_url" class="h-8 w-8 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
										<img
											:src="character.avatar_url"
											:alt="`${character.name} avatar`"
											class="h-full w-full object-cover"
											loading="lazy"
										>
									</div>
									<div v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
										<UIcon name="i-lucide-user-round" size="13" class="text-muted" />
									</div>

									<div class="min-w-0">
										<p class="truncate text-sm font-medium text-toned">{{ character.name }}</p>
										<p class="truncate text-xs leading-tight text-muted">{{ characterSubtitle(character) }}</p>
									</div>

									<UIcon
										v-if="character.is_primary"
										name="i-lucide-star"
										class="size-3.5 shrink-0 text-warning"
									/>
								</div>
							</div>

							<p v-else class="text-sm text-muted">
								{{ t('groups.members.roster.no_characters') }}
							</p>
						</div>

						<div v-if="canShowCardActions(card)" class="mt-4 border-t border-default pt-4">
							<template v-if="card.kind === 'active'">
								<div v-if="canShowActiveActions(card.member)" class="flex flex-col items-center gap-2">
									<div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-center">
										<UButton
											v-if="props.canViewActivitySummary"
											color="neutral"
											variant="ghost"
											icon="i-lucide-activity"
											size="sm"
											:label="t('groups.members.activity_summary.toggle')"
											@click="openActivitySummaryModal(card.member)"
										/>
										<MemberNotesButton
											:user-id="card.member.id"
											:note-summary="card.member.note_summary"
											size="sm"
											@open="props.notes.openMemberNotes"
										/>
										<UButton
											v-if="card.member.permissions.can_promote"
											color="primary"
											variant="subtle"
											icon="i-lucide-arrow-up"
											size="sm"
											:label="t('groups.members.actions.promote')"
											:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
											@click="props.moderation.updateMemberRole(card.member, nextPromotedRole(card.member.role))"
										/>
										<UButton
											v-if="card.member.permissions.can_demote"
											color="neutral"
											variant="subtle"
											icon="i-lucide-arrow-down"
											size="sm"
											:label="t('groups.members.actions.demote')"
											:loading="props.moderation.updateRoleForm.processing && props.moderation.memberPendingRoleUpdateId === card.member.id"
											@click="props.moderation.updateMemberRole(card.member, nextDemotedRole(card.member.role))"
										/>
									</div>

									<div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-center">
										<UButton
											v-if="card.member.permissions.can_kick"
											color="error"
											variant="ghost"
											icon="i-lucide-user-round-x"
											size="sm"
											:label="t('groups.members.actions.kick')"
											:loading="props.moderation.removeForm.processing && props.moderation.memberPendingRemovalId === card.member.id"
											@click="props.moderation.openKickConfirmation(card.member)"
										/>
										<UButton
											v-if="card.member.permissions.can_ban"
											color="error"
											variant="subtle"
											icon="i-lucide-ban"
											size="sm"
											:label="t('groups.members.actions.ban')"
											:loading="props.moderation.banForm.processing && props.moderation.memberPendingBanId === card.member.id"
											@click="props.moderation.openBanConfirmation(card.member)"
										/>
									</div>
								</div>

								<div v-else-if="props.canViewActivitySummary" class="flex justify-center">
									<UButton
										color="neutral"
										variant="ghost"
										icon="i-lucide-activity"
										size="sm"
										:label="t('groups.members.activity_summary.toggle')"
										@click="openActivitySummaryModal(card.member)"
									/>
								</div>
							</template>

							<template v-else>
								<div v-if="canShowBannedActions(card.member)" class="flex flex-wrap justify-center gap-2">
									<MemberNotesButton
										v-if="card.member.user_id"
										:user-id="card.member.user_id"
										:note-summary="card.member.note_summary"
										size="sm"
										@open="props.notes.openMemberNotes"
									/>
									<UButton
										v-if="card.member.permissions.can_unban && card.member.user_id"
										color="success"
										variant="subtle"
										icon="i-lucide-undo-2"
										size="sm"
										:label="t('groups.members.actions.unban')"
										:loading="props.moderation.unbanForm.processing && props.moderation.memberPendingUnbanId === card.member.user_id"
										@click="props.moderation.unbanMember(card.member)"
									/>
								</div>

								<p v-else class="text-center text-sm text-muted">-</p>
							</template>
						</div>

					</div>
				</div>
			</div>

			<div v-if="hasMore" ref="loadMoreSentinel" class="flex justify-center pt-2">
				<UButton
					color="neutral"
					variant="ghost"
					icon="i-lucide-chevrons-down"
					:label="t('groups.members.view.load_more')"
					@click="loadMore"
				/>
			</div>
		</div>
	</UCard>

	<UModal
		v-model:open="activitySummaryModalOpen"
		:title="activitySummaryModalTitle"
		:description="activitySummaryModalDescription"
		:ui="{ content: 'sm:max-w-5xl' }"
	>
		<template #body>
			<GroupMemberActivitySummaryPanel
				:summary="selectedActivitySummaryState.data"
				:loading="selectedActivitySummaryState.loading"
				:error="selectedActivitySummaryState.error"
				@retry="retrySelectedActivitySummary"
			/>
		</template>
	</UModal>
</template>
