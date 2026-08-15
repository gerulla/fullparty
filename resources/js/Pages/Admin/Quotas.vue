<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { useForm } from "@inertiajs/vue3";
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type QuotaDefinition = {
	key: string
	scope: "user" | "group"
	label: string
	default_limit: number
}

type QuotaOverride = {
	id: number
	subject_type: QuotaDefinition["scope"]
	subject_id: number
	subject_label: string
	quota_key: string
	quota_label: string
	limit: number | null
	is_unlimited: boolean
	usage: number | null
	starts_at: string | null
	expires_at: string | null
	reason: string
	created_by: string | null
	updated_at: string | null
}

type SubjectOption = {
	id: number
	label: string
	description: string | null
}

type PaginatedOverrides = {
	data: QuotaOverride[]
	links: Array<{ url: string | null, label: string, active: boolean }>
}

const props = defineProps<{
	mode: string
	definitions: QuotaDefinition[]
	overrides: PaginatedOverrides
}>();

const { t } = useI18n();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const editingId = ref<number | null>(null);
const subjectSearch = ref("");
const subjectOptions = ref<SubjectOption[]>([]);
const loadingSubjects = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const form = useForm({
	subject_type: "user" as QuotaDefinition["scope"],
	subject_id: null as number | null,
	quota_key: "",
	limit: 5 as number | null,
	is_unlimited: false,
	starts_at: "",
	expires_at: "",
	reason: "",
});

const scopeOptions = computed(() => [
	{ label: t("admin.quotas.scopes.user"), value: "user" },
	{ label: t("admin.quotas.scopes.group"), value: "group" },
]);
const quotaOptions = computed(() => props.definitions
	.filter((definition) => definition.scope === form.subject_type)
	.map((definition) => ({
		label: `${definition.label} (${t("admin.quotas.default_limit", { limit: definition.default_limit })})`,
		value: definition.key,
	})));
const selectedDefinition = computed(() => props.definitions.find((definition) => definition.key === form.quota_key));
const modeIsObserve = computed(() => props.mode === "observe");

const loadSubjects = async () => {
	if (editingId.value !== null) {
		return;
	}

	loadingSubjects.value = true;

	try {
		const response = await axios.get(route("admin.quotas.subjects"), {
			params: {
				type: form.subject_type,
				search: subjectSearch.value || undefined,
			},
		});
		subjectOptions.value = Array.isArray(response.data?.data) ? response.data.data : [];
	} finally {
		loadingSubjects.value = false;
	}
};

const resetForm = () => {
	editingId.value = null;
	form.reset();
	form.subject_type = "user";
	form.subject_id = null;
	form.quota_key = "";
	form.limit = 5;
	form.is_unlimited = false;
	form.starts_at = "";
	form.expires_at = "";
	form.reason = "";
	form.clearErrors();
	subjectSearch.value = "";
	void loadSubjects();
};

const editOverride = (override: QuotaOverride) => {
	editingId.value = override.id;
	form.subject_type = override.subject_type;
	form.subject_id = override.subject_id;
	form.quota_key = override.quota_key;
	form.limit = override.limit;
	form.is_unlimited = override.is_unlimited;
	form.starts_at = override.starts_at?.slice(0, 16) ?? "";
	form.expires_at = override.expires_at?.slice(0, 16) ?? "";
	form.reason = override.reason;
	form.clearErrors();
	subjectOptions.value = [{ id: override.subject_id, label: override.subject_label, description: null }];
};

const submit = () => {
	const options = {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({ title: t("admin.quotas.saved"), color: "success", icon: "i-lucide-check" });
			resetForm();
		},
	};

	if (editingId.value !== null) {
		form.put(route("admin.quotas.update", editingId.value), options);
		return;
	}

	form.post(route("admin.quotas.store"), options);
};

const removeOverride = async (override: QuotaOverride) => {
	await confirmationModal.open({
		title: t("admin.quotas.delete_modal.title"),
		description: t("admin.quotas.delete_modal.description", { subject: override.subject_label }),
		warningText: t("admin.quotas.delete_modal.warning"),
		severity: "error",
		confirmLabel: t("admin.quotas.delete_modal.confirm"),
		confirmIcon: "i-lucide-trash-2",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				form.delete(route("admin.quotas.destroy", override.id), {
					preserveScroll: true,
					onSuccess: () => {
						toast.add({ title: t("admin.quotas.deleted"), color: "success", icon: "i-lucide-check" });
						resolve(true);
					},
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const formatDate = (value: string | null) => value ? new Date(value).toLocaleString() : t("admin.quotas.no_expiry");
const paginationLabel = (label: string) => label
	.replace("&laquo;", "‹")
	.replace("&raquo;", "›");

watch(() => form.subject_type, () => {
	if (editingId.value !== null) {
		return;
	}

	form.subject_id = null;
	form.quota_key = "";
	void loadSubjects();
});

watch(subjectSearch, () => {
	if (searchTimer !== null) {
		clearTimeout(searchTimer);
	}

	searchTimer = setTimeout(() => void loadSubjects(), 250);
});

watch(selectedDefinition, (definition) => {
	if (definition && editingId.value === null) {
		form.limit = definition.default_limit;
	}
});

onMounted(() => void loadSubjects());
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.quotas.title')"
			:subtitle="t('admin.quotas.subtitle')"
		>
			<UBadge color="primary" variant="subtle" icon="i-lucide-shield-check">
				{{ t("admin.quotas.admin_only") }}
			</UBadge>
		</PageHeader>

		<UAlert
			class="mt-4"
			:color="modeIsObserve ? 'warning' : 'success'"
			variant="soft"
			:icon="modeIsObserve ? 'i-lucide-eye' : 'i-lucide-shield-check'"
			:title="t(`admin.quotas.mode.${modeIsObserve ? 'observe_title' : 'enforce_title'}`)"
			:description="t(`admin.quotas.mode.${modeIsObserve ? 'observe_description' : 'enforce_description'}`)"
		/>

		<section class="mt-6 border border-default bg-elevated/30">
			<div class="border-b border-default px-4 py-3">
				<h2 class="font-semibold text-highlighted">
					{{ editingId === null ? t("admin.quotas.form.create_title") : t("admin.quotas.form.edit_title") }}
				</h2>
			</div>

			<form class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="submit">
				<UFormField :label="t('admin.quotas.fields.scope')" :error="form.errors.subject_type" required>
					<USelect v-model="form.subject_type" :items="scopeOptions" value-key="value" class="w-full" :disabled="editingId !== null" />
				</UFormField>

				<UFormField :label="t('admin.quotas.fields.subject')" :error="form.errors.subject_id" required>
					<div class="space-y-2">
						<UInput
							v-if="editingId === null"
							v-model="subjectSearch"
							class="w-full"
							icon="i-lucide-search"
							:placeholder="t('admin.quotas.search_subjects')"
						/>
						<USelectMenu
							v-model="form.subject_id"
							:items="subjectOptions"
							value-key="id"
							label-key="label"
							class="w-full"
							:loading="loadingSubjects"
							:disabled="editingId !== null"
						/>
					</div>
				</UFormField>

				<UFormField :label="t('admin.quotas.fields.quota')" :error="form.errors.quota_key" required>
					<USelect v-model="form.quota_key" :items="quotaOptions" value-key="value" class="w-full" :disabled="editingId !== null" />
				</UFormField>

				<div class="grid grid-cols-[1fr_auto] gap-3">
					<UFormField :label="t('admin.quotas.fields.limit')" :error="form.errors.limit" :required="!form.is_unlimited">
						<UInput v-model.number="form.limit" type="number" min="1" max="1000000" class="w-full" :disabled="form.is_unlimited" />
					</UFormField>
					<UFormField :label="t('admin.quotas.fields.unlimited')" :error="form.errors.is_unlimited">
						<UToggle v-model="form.is_unlimited" class="mt-2" />
					</UFormField>
				</div>

				<UFormField :label="t('admin.quotas.fields.starts_at')" :error="form.errors.starts_at">
					<UInput v-model="form.starts_at" type="datetime-local" class="w-full" />
				</UFormField>

				<UFormField :label="t('admin.quotas.fields.expires_at')" :error="form.errors.expires_at">
					<UInput v-model="form.expires_at" type="datetime-local" class="w-full" />
				</UFormField>

				<UFormField class="md:col-span-2" :label="t('admin.quotas.fields.reason')" :error="form.errors.reason" required>
					<UTextarea v-model="form.reason" :rows="2" class="w-full" :placeholder="t('admin.quotas.reason_placeholder')" />
				</UFormField>

				<div class="flex items-end justify-end gap-2 md:col-span-2 xl:col-span-4">
					<UButton v-if="editingId !== null" type="button" color="neutral" variant="ghost" :label="t('general.cancel')" @click="resetForm" />
					<UButton type="submit" icon="i-lucide-save" :label="t('general.save')" :loading="form.processing" />
				</div>
			</form>
		</section>

		<section class="mt-6">
			<div class="mb-3 flex items-center justify-between border-b border-default pb-3">
				<div>
					<h2 class="font-semibold text-highlighted">{{ t("admin.quotas.overrides_title") }}</h2>
					<p class="text-sm text-muted">{{ t("admin.quotas.overrides_description") }}</p>
				</div>
				<UBadge color="neutral" variant="subtle">{{ overrides.data.length }}</UBadge>
			</div>

			<div v-if="overrides.data.length" class="divide-y divide-default border border-default">
				<div v-for="override in overrides.data" :key="override.id" class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] lg:items-center">
					<div class="min-w-0">
						<div class="flex flex-wrap items-center gap-2">
							<p class="truncate font-semibold text-highlighted">{{ override.subject_label }}</p>
							<UBadge color="neutral" variant="subtle">{{ t(`admin.quotas.scopes.${override.subject_type}`) }}</UBadge>
						</div>
						<p class="mt-1 text-sm text-toned">{{ override.quota_label }}</p>
						<p class="mt-1 text-xs text-muted">{{ override.reason }}</p>
					</div>

					<div class="grid grid-cols-2 gap-3 text-sm">
						<div>
							<p class="text-xs uppercase text-muted">{{ t("admin.quotas.usage") }}</p>
							<p class="font-semibold text-highlighted">
								{{ override.usage ?? t("admin.quotas.contextual") }} / {{ override.is_unlimited ? t("admin.quotas.unlimited") : override.limit }}
							</p>
						</div>
						<div>
							<p class="text-xs uppercase text-muted">{{ t("admin.quotas.expiry") }}</p>
							<p class="text-toned">{{ formatDate(override.expires_at) }}</p>
						</div>
					</div>

					<div class="flex gap-2 lg:justify-end">
						<UButton color="neutral" variant="soft" icon="i-lucide-pencil" :label="t('general.edit')" @click="editOverride(override)" />
						<UButton color="error" variant="soft" icon="i-lucide-trash-2" :label="t('general.delete')" @click="removeOverride(override)" />
					</div>
				</div>
			</div>

			<UAlert v-else color="neutral" variant="soft" icon="i-lucide-gauge" :title="t('admin.quotas.empty_title')" :description="t('admin.quotas.empty_description')" />

			<div v-if="overrides.links.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
				<UButton
					v-for="link in overrides.links"
					:key="link.label"
					:to="link.url ?? undefined"
					:disabled="link.url === null"
					:variant="link.active ? 'solid' : 'ghost'"
					color="neutral"
				>
					{{ paginationLabel(link.label) }}
				</UButton>
			</div>
		</section>
	</div>
</template>
