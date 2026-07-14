<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import RaidPositions from "@/components/Admin/RaidPositions.vue";
import BozjaData from "@/components/Admin/BozjaData.vue";
import type { BozjaItemRecord } from "@/Types/Bozja";
import { ref } from "vue";
import { useI18n } from "vue-i18n";

type RaidPositionRecord = {
	id: number
	key: string
	name: string
	icon_url: string | null
	sort_order: number
	is_active: boolean
}

defineProps<{
	raidPositions: RaidPositionRecord[]
	bozjaItems: BozjaItemRecord[]
}>();

const { t } = useI18n();
const selectedSection = ref('raid_positions');
const sectionTabs = [
	{ label: t('admin.system_data.sections.raid_positions'), value: 'raid_positions', icon: 'i-lucide-map-pin' },
	{ label: t('admin.system_data.sections.bozja_data'), value: 'bozja_data', icon: 'i-lucide-package-open' },
];
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.system_data.title')"
			:subtitle="t('admin.system_data.subtitle')"
		>
			<UBadge
				color="warning"
				variant="subtle"
				icon="i-lucide-shield"
				:label="t('admin.system_data.admin_only')"
			/>
		</PageHeader>

		<div class="mt-6">
			<UTabs v-model="selectedSection" :items="sectionTabs" :content="false" variant="link" class="mb-5 w-full" />
			<RaidPositions v-if="selectedSection === 'raid_positions'" :raid-positions="raidPositions" />
			<BozjaData v-else :bozja-items="bozjaItems" />
		</div>
	</div>
</template>
