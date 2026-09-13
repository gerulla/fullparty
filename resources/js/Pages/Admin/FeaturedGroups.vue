<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type GroupOption = {
	label: string
	value: number
	description: string
}

type FeaturedGroupRecord = {
	id: number
	group_id: number
	priority: number
	starts_at: string | null
	ends_at: string | null
	internal_note: string | null
	is_active: boolean
	group: {
		id: number
		name: string
		slug: string
		datacenter: string
		is_visible: boolean
		banner_image_url: string | null
	} | null
}

const props = defineProps<{
	featuredGroups: FeaturedGroupRecord[]
	groupOptions: GroupOption[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const editingId = ref<number | null>(null);

const toDateTimeLocal = (value: string | null) => {
	if (!value) {
		return "";
	}

	const date = new Date(value);
	const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);

	return localDate.toISOString().slice(0, 16);
};

const formatDate = (value: string | null) => value ? new Date(value).toLocaleString(locale.value) : "-";

const form = useForm({
	group_id: null as number | string | null,
	priority: 0,
	starts_at: "",
	ends_at: "",
	internal_note: "",
});

const resetForm = () => {
	editingId.value = null;
	form.defaults({
		group_id: null,
		priority: 0,
		starts_at: "",
		ends_at: "",
		internal_note: "",
	});
	form.reset();
	form.clearErrors();
};

const editFeaturedGroup = (featuredGroup: FeaturedGroupRecord) => {
	editingId.value = featuredGroup.id;
	form.defaults({
		group_id: featuredGroup.group_id,
		priority: featuredGroup.priority,
		starts_at: toDateTimeLocal(featuredGroup.starts_at),
		ends_at: toDateTimeLocal(featuredGroup.ends_at),
		internal_note: featuredGroup.internal_note ?? "",
	});
	form.reset();
	form.clearErrors();
};

const submit = () => {
	const options = {
		onSuccess: () => resetForm(),
	};

	if (editingId.value === null) {
		form.post(route("admin.featured-groups.store"), options);

		return;
	}

	form.put(route("admin.featured-groups.update", editingId.value), options);
};

const deleteFeaturedGroup = async (featuredGroup: FeaturedGroupRecord) => {
	await confirmationModal.open({
		title: t("admin.featured_groups.delete_modal.title"),
		description: t("admin.featured_groups.delete_modal.description"),
		severity: "warning",
		confirmLabel: t("admin.featured_groups.delete_modal.confirm"),
		confirmIcon: "i-lucide-trash-2",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				useForm({}).delete(route("admin.featured-groups.destroy", featuredGroup.id), {
					onSuccess: () => resolve(true),
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const statusFor = (featuredGroup: FeaturedGroupRecord) => {
	const now = Date.now();

	if (!featuredGroup.group?.is_visible) {
		return {
			label: t("admin.featured_groups.status.hidden_group"),
			color: "warning",
		};
	}

	if (featuredGroup.starts_at && new Date(featuredGroup.starts_at).getTime() > now) {
		return {
			label: t("admin.featured_groups.status.scheduled"),
			color: "info",
		};
	}

	if (featuredGroup.ends_at && new Date(featuredGroup.ends_at).getTime() < now) {
		return {
			label: t("admin.featured_groups.status.ended"),
			color: "neutral",
		};
	}

	return {
		label: t("admin.featured_groups.status.active"),
		color: "success",
	};
};

const selectedGroupName = computed(() => props.groupOptions.find((group) => String(group.value) === String(form.group_id))?.label ?? null);

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) {
			return;
		}

		const successKeys = Array.isArray(success) ? success : [success];

		const toastKey = successKeys.find((key) => [
			"featured_group_created",
			"featured_group_updated",
			"featured_group_deleted",
		].includes(String(key)));

		if (!toastKey) {
			return;
		}

		const descriptionKey = toastKey === "featured_group_created"
			? "admin.featured_groups.toasts.created"
			: toastKey === "featured_group_updated"
				? "admin.featured_groups.toasts.updated"
				: "admin.featured_groups.toasts.deleted";

		toast.add({
			title: t("general.success"),
			description: t(descriptionKey),
			color: "success",
			icon: "i-lucide-check",
		});
	},
	{ immediate: true },
);
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.featured_groups.title')"
			:subtitle="t('admin.featured_groups.subtitle')"
		>
			<UBadge
				color="warning"
				variant="subtle"
				icon="i-lucide-shield"
				:label="t('admin.featured_groups.admin_only')"
			/>
		</PageHeader>

		<UAlert
			class="mt-6"
			color="info"
			variant="soft"
			icon="i-lucide-info"
			:description="t('admin.featured_groups.eligibility_hint')"
		/>

		<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[25rem_minmax(0,1fr)]">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="space-y-1">
						<h2 class="text-base font-semibold text-highlighted">
							{{ editingId === null ? t("admin.featured_groups.form.create_title") : t("admin.featured_groups.form.edit_title") }}
						</h2>
						<p v-if="selectedGroupName" class="text-sm text-muted">
							{{ selectedGroupName }}
						</p>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="submit">
					<UFormField :label="t('admin.featured_groups.fields.group')" :error="form.errors.group_id" required>
						<USelect
							v-model="form.group_id"
							class="w-full"
							:items="groupOptions"
							:placeholder="t('admin.featured_groups.placeholders.group')"
						/>
					</UFormField>

					<UFormField :label="t('admin.featured_groups.fields.priority')" :error="form.errors.priority" required>
						<UInput
							v-model.number="form.priority"
							type="number"
							min="0"
							max="1000"
							class="w-full"
						/>
					</UFormField>

					<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-1">
						<UFormField :label="t('admin.featured_groups.fields.starts_at')" :error="form.errors.starts_at">
							<UInput v-model="form.starts_at" type="datetime-local" class="w-full" />
						</UFormField>

						<UFormField :label="t('admin.featured_groups.fields.ends_at')" :error="form.errors.ends_at">
							<UInput v-model="form.ends_at" type="datetime-local" class="w-full" />
						</UFormField>
					</div>

					<UFormField :label="t('admin.featured_groups.fields.internal_note')" :error="form.errors.internal_note">
						<UTextarea
							v-model="form.internal_note"
							:rows="4"
							class="w-full"
							:placeholder="t('admin.featured_groups.placeholders.internal_note')"
						/>
					</UFormField>

					<div class="flex flex-wrap justify-end gap-2">
						<UButton
							v-if="editingId !== null"
							type="button"
							color="neutral"
							variant="soft"
							icon="i-lucide-x"
							:label="t('general.cancel')"
							@click="resetForm"
						/>
						<UButton
							type="submit"
							color="primary"
							icon="i-lucide-save"
							:loading="form.processing"
							:label="editingId === null ? t('admin.featured_groups.form.create') : t('admin.featured_groups.actions.save')"
						/>
					</div>
				</form>
			</UCard>

			<div class="space-y-4">
				<UCard
					v-for="featuredGroup in featuredGroups"
					:key="featuredGroup.id"
					class="overflow-hidden dark:bg-elevated/25"
				>
					<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
						<div class="min-w-0 flex-1">
							<div class="flex min-w-0 gap-4">
								<img
									v-if="featuredGroup.group?.banner_image_url"
									:src="featuredGroup.group.banner_image_url"
									class="hidden h-24 w-36 border border-default object-cover md:block"
									alt=""
								>
								<div class="min-w-0 space-y-3">
									<div class="flex flex-wrap items-center gap-2">
										<h2 class="truncate text-lg font-semibold text-highlighted">
											{{ featuredGroup.group?.name ?? `#${featuredGroup.group_id}` }}
										</h2>
										<UBadge
											:color="statusFor(featuredGroup).color"
											variant="subtle"
											:label="statusFor(featuredGroup).label"
										/>
										<UBadge
											color="primary"
											variant="subtle"
											:label="String(featuredGroup.priority)"
										/>
									</div>

									<p class="text-sm text-muted">
										{{ featuredGroup.group?.slug }} - {{ featuredGroup.group?.datacenter }}
									</p>

									<div class="grid grid-cols-1 gap-3 text-sm text-muted md:grid-cols-2">
										<div>
											<p class="text-xs uppercase tracking-wide text-muted">
												{{ t("admin.featured_groups.table.window") }}
											</p>
											<p class="mt-1 text-toned">
												{{ formatDate(featuredGroup.starts_at) }} / {{ formatDate(featuredGroup.ends_at) }}
											</p>
										</div>
										<div v-if="featuredGroup.internal_note">
											<p class="text-xs uppercase tracking-wide text-muted">
												{{ t("admin.featured_groups.table.note") }}
											</p>
											<p class="mt-1 break-words text-toned">
												{{ featuredGroup.internal_note }}
											</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="flex shrink-0 flex-wrap gap-2">
							<UButton
								color="neutral"
								variant="soft"
								icon="i-lucide-pencil"
								:label="t('admin.featured_groups.actions.edit')"
								@click="editFeaturedGroup(featuredGroup)"
							/>
							<UButton
								color="error"
								variant="soft"
								icon="i-lucide-trash-2"
								:label="t('admin.featured_groups.actions.delete')"
								@click="deleteFeaturedGroup(featuredGroup)"
							/>
						</div>
					</div>
				</UCard>

				<UCard v-if="featuredGroups.length === 0" class="dark:bg-elevated/25">
					<div class="py-12 text-center text-sm text-muted">
						{{ t("admin.featured_groups.empty") }}
					</div>
				</UCard>
			</div>
		</div>
	</div>
</template>
