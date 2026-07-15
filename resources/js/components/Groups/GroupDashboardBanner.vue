<script setup lang="ts">
import type { GroupDashboardGroup } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import GroupDashboardActionButtons from "@/components/Groups/GroupDashboardActionButtons.vue";

const props = defineProps<{
	group: GroupDashboardGroup
}>();

const { t } = useI18n();
const groupTypeLabel = computed(() => t(`groups.common.group_types.${props.group.group_type}`));
const showsMembershipAction = computed(() => Boolean(
	props.group.permissions.can_join
	|| props.group.permissions.can_apply
	|| props.group.membership_application.pending,
));
const titleStyle = computed(() => {
	const length = Array.from(props.group.name.trim()).length;
	const excessLength = Math.max(0, length - 18);
	const mobileSize = Math.max(1.5, 2.25 - (excessLength * 0.025));
	const desktopSize = Math.max(2, 3 - (excessLength * 0.035));

	return {
		"--group-name-size-mobile": `${mobileSize.toFixed(3)}rem`,
		"--group-name-size-desktop": `${desktopSize.toFixed(3)}rem`,
	};
});
</script>

<template>
	<div class="relative h-[14rem] overflow-hidden border border-default bg-neutral-950 sm:h-[14rem] lg:h-[16rem]">
		<img
			v-if="group.banner_image_url"
			:src="group.banner_image_url"
			:alt="group.name"
			class="absolute inset-0 size-full object-cover"
		>
		<div
			v-else
			class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.46),transparent_42%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.34),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)]"
		/>

		<div class="absolute inset-0 bg-gradient-to-r from-neutral-950 via-neutral-950/72 to-neutral-950/18" />
		<div class="absolute inset-0 bg-gradient-to-t from-neutral-950 via-transparent to-neutral-950/18" />

		<div class="relative flex h-full flex-col items-start justify-between gap-4 p-4 sm:p-6 lg:flex-row lg:gap-6 lg:p-8">
			<div class="max-w-3xl pb-4 sm:pb-5 lg:pb-6">
				<p class="text-[11px] uppercase tracking-[0.22em] text-brand-200/80">
					{{ groupTypeLabel }}
				</p>
				<h1
					class="group-name-title"
					:style="titleStyle"
				>
					{{ group.name }}
				</h1>
			</div>

			<div
				class="w-full max-w-md shrink-0 self-end pb-4"
				:class="showsMembershipAction ? 'block' : 'hidden lg:block'"
			>
				<GroupDashboardActionButtons :group="group" />
			</div>
		</div>
	</div>
</template>

<style scoped>
.group-name-title {
	margin-top: 0.75rem;
	overflow-wrap: anywhere;
	font-size: var(--group-name-size-mobile);
	font-weight: 600;
	line-height: 1.15;
	color: white;
}

@media (min-width: 640px) {
	.group-name-title {
		font-size: var(--group-name-size-desktop);
	}
}
</style>
