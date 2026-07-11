<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import PageHeader from "@/components/PageHeader.vue";
import GroupAvailabilityOverview from "@/components/Groups/Availability/GroupAvailabilityOverview.vue";
import GroupAvailabilitySelectionDetails from "@/components/Groups/Availability/GroupAvailabilitySelectionDetails.vue";
import MyAvailabilityScheduleModal from "@/components/Groups/Availability/MyAvailabilityScheduleModal.vue";
import type {
	GroupAvailabilityMinimumRole,
	GroupAvailabilityOverviewPayload,
	GroupAvailabilityPageGroup,
	GroupAvailabilitySchedulePayload,
} from "@/Types/Groups";

const props = defineProps<{
	group: GroupAvailabilityPageGroup
	availability_settings: {
		minimum_role: GroupAvailabilityMinimumRole
	}
	schedule: GroupAvailabilitySchedulePayload | null
	overview: GroupAvailabilityOverviewPayload | null
}>();

const { t } = useI18n();
const toast = useToast();
const configurationOpen = ref(false);
const draftAccessLevel = ref<GroupAvailabilityMinimumRole>(props.availability_settings.minimum_role);
const configurationSaving = ref(false);
const selectedRange = ref({
	starts_at: props.overview?.buckets[0]?.starts_at ?? new Date().toISOString(),
	ends_at: props.overview?.buckets[3]
		? new Date(new Date(props.overview.buckets[3].starts_at).getTime() + 3_600_000).toISOString()
		: new Date(Date.now() + 4 * 3_600_000).toISOString(),
});
const canConfigure = computed(() => ["owner", "admin"].includes(props.group.current_user_role));
const accessLevelOptions = computed(() => [
	{ label: t("groups.availability.configuration.access.members"), value: "member" },
	{ label: t("groups.availability.configuration.access.moderators"), value: "moderator" },
]);

const openConfiguration = () => {
	draftAccessLevel.value = props.availability_settings.minimum_role;
	configurationOpen.value = true;
};

const applyConfiguration = () => {
	configurationSaving.value = true;

	router.put(route("groups.dashboard.availability.settings.update", props.group.slug), {
		minimum_role: draftAccessLevel.value,
	}, {
		preserveScroll: true,
		onSuccess: () => {
			configurationOpen.value = false;
			toast.add({
				title: t("groups.availability.configuration.saved"),
				color: "success",
				icon: "i-lucide-check",
			});
		},
		onFinish: () => {
			configurationSaving.value = false;
		},
	});
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.availability.title')"
			:subtitle="t('groups.availability.subtitle')"
		>
			<div class="flex flex-wrap items-center justify-center gap-2 xl:justify-end">
				<MyAvailabilityScheduleModal
					v-if="group.permissions.can_use_availability"
					:group-slug="group.slug"
					:schedule="schedule"
				/>
				<UButton
					v-if="canConfigure"
					color="neutral"
					variant="soft"
					icon="i-lucide-settings-2"
					:label="t('groups.availability.configuration.button')"
					@click="openConfiguration"
				/>
			</div>
		</PageHeader>

		<GroupAvailabilityOverview
			v-if="overview"
			:overview="overview"
			:selected-starts-at="selectedRange.starts_at"
			:selected-ends-at="selectedRange.ends_at"
			class="mt-4"
			@update:selected-range="selectedRange = $event"
		/>

		<GroupAvailabilitySelectionDetails
			v-if="overview"
			:group-slug="group.slug"
			:starts-at="selectedRange.starts_at"
			:ends-at="selectedRange.ends_at"
			class="mt-4"
			@update:range="selectedRange = $event"
		/>

		<UModal v-model:open="configurationOpen">
			<template #header>
				<p class="font-semibold">{{ t('groups.availability.configuration.title') }}</p>
			</template>

			<template #body>
				<div class="space-y-5">
					<UFormField
						:label="t('groups.availability.configuration.access.label')"
						:description="t('groups.availability.configuration.access.description')"
					>
						<USelect
							v-model="draftAccessLevel"
							:items="accessLevelOptions"
							value-key="value"
							class="w-full"
						/>
					</UFormField>

					<div class="flex justify-end gap-2">
						<UButton
							type="button"
							color="neutral"
							variant="ghost"
							:label="t('general.cancel')"
							@click="configurationOpen = false"
						/>
						<UButton
							type="button"
							color="neutral"
							:label="t('general.save')"
							:loading="configurationSaving"
							@click="applyConfiguration"
						/>
					</div>
				</div>
			</template>
		</UModal>
	</div>
</template>
