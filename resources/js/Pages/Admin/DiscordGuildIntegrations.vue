<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { router } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

type DiscordGuildLink = {
	id: number
	discord_guild_id: string
	name: string | null
	icon_url: string | null
	guild_installed_at: string | null
	linked_at: string | null
	group: {
		id: number
		name: string
		slug: string
		datacenter: string | null
	} | null
}

const props = defineProps<{
	links: DiscordGuildLink[]
}>();

const { t } = useI18n();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const search = ref("");

const filteredLinks = computed(() => {
	const term = search.value.trim().toLocaleLowerCase();

	if (!term) {
		return props.links;
	}

	return props.links.filter((link) => [
		link.name,
		link.discord_guild_id,
		link.group?.name,
		link.group?.slug,
		link.group?.datacenter,
	].some((value) => value?.toLocaleLowerCase().includes(term)));
});

const formatDate = (value: string | null) => value
	? new Date(value).toLocaleString()
	: t("admin.discord_guild_links.unknown_date");

const forceUnlink = async (link: DiscordGuildLink) => {
	if (!link.group) {
		return;
	}

	await confirmationModal.open({
		title: t("admin.discord_guild_links.unlink_modal.title"),
		description: t("admin.discord_guild_links.unlink_modal.description", {
			guild: link.name ?? link.discord_guild_id,
			group: link.group.name,
		}),
		warningText: t("admin.discord_guild_links.unlink_modal.warning"),
		severity: "error",
		confirmLabel: t("admin.discord_guild_links.unlink_modal.confirm"),
		confirmIcon: "i-lucide-unplug",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.delete(route("admin.discord-guild-links.destroy", link.id), {
					onSuccess: () => {
						toast.add({
							title: t("admin.discord_guild_links.toasts.unlinked"),
							color: "success",
							icon: "i-lucide-unplug",
						});
						resolve(true);
					},
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.discord_guild_links.title')"
			:subtitle="t('admin.discord_guild_links.subtitle')"
		>
			<UBadge color="primary" variant="subtle" icon="i-lucide-shield-check">
				{{ t("admin.discord_guild_links.admin_only") }}
			</UBadge>
		</PageHeader>

		<div class="mt-4 flex flex-col gap-3 border-b border-default pb-4 sm:flex-row sm:items-center sm:justify-between">
			<p class="text-sm text-muted">
				{{ t("admin.discord_guild_links.summary", { count: links.length }) }}
			</p>
			<UInput
				v-model="search"
				class="w-full sm:max-w-sm"
				icon="i-lucide-search"
				:placeholder="t('admin.discord_guild_links.search_placeholder')"
			/>
		</div>

		<div v-if="filteredLinks.length" class="divide-y divide-default border border-default">
			<div
				v-for="link in filteredLinks"
				:key="link.id"
				class="flex flex-col gap-4 p-4 lg:flex-row lg:items-center"
			>
				<div class="flex min-w-0 flex-1 items-center gap-3">
					<img
						v-if="link.icon_url"
						:src="link.icon_url"
						class="size-12 shrink-0 border border-default object-cover"
						alt=""
					>
					<div v-else class="flex size-12 shrink-0 items-center justify-center border border-default bg-muted text-muted">
						<UIcon name="i-lucide-server" class="size-5" />
					</div>
					<div class="min-w-0">
						<p class="truncate font-semibold text-highlighted">{{ link.name ?? t("admin.discord_guild_links.unknown_guild") }}</p>
						<p class="truncate font-mono text-xs text-muted">{{ link.discord_guild_id }}</p>
					</div>
				</div>

				<div v-if="link.group" class="min-w-0 flex-1 border-l border-default pl-4">
					<p class="truncate font-medium text-highlighted">{{ link.group.name }}</p>
					<p class="truncate text-sm text-muted">
						{{ link.group.slug }}<template v-if="link.group.datacenter"> · {{ link.group.datacenter }}</template>
					</p>
				</div>

				<div class="min-w-48 text-sm text-muted">
					<p>{{ t("admin.discord_guild_links.installed", { date: formatDate(link.guild_installed_at) }) }}</p>
					<p>{{ t("admin.discord_guild_links.linked", { date: formatDate(link.linked_at) }) }}</p>
				</div>

				<div class="flex shrink-0 gap-2">
					<UButton
						color="error"
						variant="soft"
						icon="i-lucide-unplug"
						:label="t('admin.discord_guild_links.actions.force_unlink')"
						:disabled="!link.group"
						@click="forceUnlink(link)"
					/>
				</div>
			</div>
		</div>

		<UAlert
			v-else
			class="mt-4"
			color="neutral"
			variant="soft"
			icon="i-lucide-server-off"
			:title="t('admin.discord_guild_links.empty_title')"
			:description="t('admin.discord_guild_links.empty_description')"
		/>
	</div>
</template>
