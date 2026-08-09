<script setup lang="ts">
import type { SettingsHomepageGroup, SettingsUser } from "@/Types/Settings";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	user: SettingsUser
	groups: SettingsHomepageGroup[]
}>();

const { t } = useI18n();
const page = usePage();
const toast = useToast();
const profileValue = "profile";
const selectedValue = ref(
	props.user.homepage_group_id === null
		? profileValue
		: `group:${props.user.homepage_group_id}`,
);
const savedValue = ref(selectedValue.value);
const saving = ref(false);

const options = computed(() => [
	{
		label: t("settings.homepage.profile_page"),
		value: profileValue,
		icon: "i-lucide-user",
	},
	...props.groups.map((group) => ({
		label: group.name,
		value: `group:${group.id}`,
		icon: "i-lucide-users",
	})),
]);

const groupIdFromValue = (value: string): number | null => {
	if (value === profileValue) {
		return null;
	}

	const groupId = Number(value.replace("group:", ""));

	return Number.isInteger(groupId) ? groupId : null;
};

const save = async (value: string) => {
	if (saving.value || value === savedValue.value) {
		return;
	}

	const previousValue = savedValue.value;
	saving.value = true;

	try {
		const response = await axios.patch<{ homepage_group_id: number | null }>(
			route("settings.homepage"),
			{ homepage_group_id: groupIdFromValue(value) },
		);
		const homepageGroupId = response.data.homepage_group_id;

		savedValue.value = homepageGroupId === null ? profileValue : `group:${homepageGroupId}`;
		selectedValue.value = savedValue.value;

		if (page.props.auth?.user) {
			(page.props.auth.user as SettingsUser).homepage_group_id = homepageGroupId;
		}

		toast.add({
			title: t("settings.toasts.title"),
			description: t("settings.homepage.saved"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch {
		selectedValue.value = previousValue;
		toast.add({
			title: t("settings.toasts.error_title"),
			description: t("settings.homepage.save_failed"),
			color: "error",
			icon: "i-lucide-triangle-alert",
		});
	} finally {
		saving.value = false;
	}
};

watch(
	() => props.user.homepage_group_id,
	(groupId) => {
		const value = groupId === null ? profileValue : `group:${groupId}`;
		selectedValue.value = value;
		savedValue.value = value;
	},
);
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex items-center text-md font-semibold">
				<UIcon name="i-lucide-house" class="mr-2" size="22" />
				<p>{{ t("settings.homepage.title") }}</p>
			</div>
		</template>

		<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
			<div class="max-w-2xl">
				<p class="font-semibold">{{ t("settings.homepage.label") }}</p>
				<p class="mt-1 text-sm text-muted">{{ t("settings.homepage.description") }}</p>
			</div>

			<UFormField class="w-full lg:max-w-md" :label="t('settings.homepage.destination')">
				<USelectMenu
					v-model="selectedValue"
					:items="options"
					value-key="value"
					size="xl"
					class="w-full"
					:disabled="saving"
					@update:model-value="save"
				/>
			</UFormField>
		</div>
	</UCard>
</template>