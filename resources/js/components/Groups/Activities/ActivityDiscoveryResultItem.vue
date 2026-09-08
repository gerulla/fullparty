<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import RunDiscoveryResultItem from "@/components/Runs/RunDiscoveryResultItem.vue";
import type { ActivityIndexItem } from "@/Types/ActivityCore";
import type { RunDiscoveryResultItemData } from "@/Types/RunDiscovery";
import { localizedValue } from "@/utils/localizedValue";

const props = defineProps<{
	activity: ActivityIndexItem
}>();

const { locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));

const item = computed<RunDiscoveryResultItemData>(() => {
	const activityTypeName = localizedValue(
		props.activity.activity_type.draft_name,
		locale.value,
		fallbackLocale.value,
	);
	const targetProgPointLabel = localizedValue(
		props.activity.target_prog_point_label,
		locale.value,
		fallbackLocale.value,
	);
	const group = props.activity.group;
	const organizer = props.activity.organized_by_character ?? props.activity.organized_by;

	return {
		id: props.activity.id,
		image_url: props.activity.small_image_url ?? props.activity.banner_image_url,
		title: props.activity.title?.trim() || activityTypeName,
		activity_type_name: activityTypeName,
		difficulty: null,
		target_prog_point_key: props.activity.target_prog_point_key,
		target_prog_point_label: targetProgPointLabel || null,
		group_name: group?.name ?? null,
		group_slug: group?.slug ?? null,
		group_profile_picture_url: group?.profile_picture_url ?? null,
		group_discord_invite_url: group?.discord_invite_url ?? null,
		group_type: group?.group_type ?? null,
		organizer: organizer ? {
			name: organizer.name,
			avatar_url: organizer.avatar_url,
			world: props.activity.organized_by_character?.world ?? null,
			datacenter: props.activity.organized_by_character?.datacenter ?? null,
		} : null,
		description: props.activity.notes,
		min_item_level: props.activity.min_item_level,
		run_style: props.activity.run_style,
		intensity: props.activity.intensity,
		voice_expectation: group?.voice_expectation ?? null,
		beginner_friendly: props.activity.beginner_friendly,
		allow_guest_applications: props.activity.allow_guest_applications,
		starts_at: props.activity.starts_at,
		datacenter: props.activity.datacenter,
		world: props.activity.organized_by_character?.world ?? null,
		role_slots: [],
		filled_slots: props.activity.assigned_slot_count,
		total_slots: props.activity.slot_count,
		is_saved: false,
		has_existing_application: props.activity.has_existing_application,
		can_apply: props.activity.links.apply !== null,
		links: props.activity.links,
	};
});
</script>

<template>
	<RunDiscoveryResultItem
		:item="item"
		:show-save="false"
		:show-host="true"
	/>
</template>
