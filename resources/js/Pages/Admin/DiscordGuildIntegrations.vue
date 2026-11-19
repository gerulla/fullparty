<script setup lang="ts">
import AdminPageHeader from "@/components/Admin/AdminPageHeader.vue";
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

const { t, locale } = useI18n();
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
	? new Date(value).toLocaleString(locale.value)
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
	<div class="min-w-0">
		<AdminPageHeader
			:title="t('admin.discord_guild_links.title')"
			:subtitle="t('admin.discord_guild_links.subtitle')"
		>
			<UBadge color="primary" variant="subtle" icon="i-lucide-shield-check">
				{{ t("admin.discord_guild_links.admin_only") }}
			</UBadge>
		</AdminPageHeader>

		<section class="admin-panel mt-5">
			<div class="flex flex-col gap-3 border-b border-default/60 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
				<p class="text-sm font-medium text-toned">
					{{ t("admin.discord_guild_links.summary", { count: links.length }) }}
				</p>
				<UInput
					v-model="search"
					class="w-full sm:max-w-sm"
					icon="i-lucide-search"
					:aria-label="t('admin.discord_guild_links.search_placeholder')"
					:placeholder="t('admin.discord_guild_links.search_placeholder')"
				/>
			</div>

			<div v-if="filteredLinks.length" class="divide-y divide-default/60">
				<div
					v-for="link in filteredLinks"
					:key="link.id"
					class="grid min-w-0 gap-4 p-4 sm:grid-cols-2 sm:px-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-center"
				>
					<div class="flex min-w-0 flex-1 items-center gap-3">
						<img
							v-if="link.icon_url"
							:src="link.icon_url"
							class="size-11 shrink-0 rounded-xl object-cover"
							alt=""
						>
						<div v-else class="admin-inset flex size-11 shrink-0 items-center justify-center text-muted">
							<UIcon name="i-lucide-server" class="size-5" />
						</div>
						<div class="min-w-0">
							<p class="truncate font-semibold text-highlighted">{{ link.name ?? t("admin.discord_guild_links.unknown_guild") }}</p>
							<p class="truncate font-mono text-xs text-muted">{{ link.discord_guild_id }}</p>
						</div>
					</div>

					<div v-if="link.group" class="min-w-0 self-center">
						<p class="truncate font-medium text-highlighted">{{ link.group.name }}</p>
						<p class="truncate text-sm text-muted">
							{{ link.group.slug }}<template v-if="link.group.datacenter"> · {{ link.group.datacenter }}</template>
						</p>
					</div>

					<div class="min-w-0 space-y-1 text-xs text-muted xl:col-start-3">
						<p>{{ t("admin.discord_guild_links.installed", { date: formatDate(link.guild_installed_at) }) }}</p>
						<p>{{ t("admin.discord_guild_links.linked", { date: formatDate(link.linked_at) }) }}</p>
					</div>

					<div class="flex items-center gap-2 sm:justify-end">
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
				class="m-4"
				color="neutral"
				variant="soft"
				icon="i-lucide-server-off"
				:title="t('admin.discord_guild_links.empty_title')"
				:description="t('admin.discord_guild_links.empty_description')"
			/>
		</section>
	</div>
</template>
