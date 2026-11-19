<script setup lang="ts">
import AdminPageHeader from "@/components/Admin/AdminPageHeader.vue";
import RaidPositions from "@/components/Admin/RaidPositions.vue";
import BozjaData from "@/components/Admin/BozjaData.vue";
import type { BozjaItemRecord } from "@/Types/Bozja";
import { computed, ref } from "vue";
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
const sectionTabs = computed(() => [
	{ label: t('admin.system_data.sections.raid_positions'), value: 'raid_positions', icon: 'i-lucide-map-pin' },
	{ label: t('admin.system_data.sections.bozja_data'), value: 'bozja_data', icon: 'i-lucide-package-open' },
]);
</script>

<template>
	<div class="min-w-0">
		<AdminPageHeader
			:title="t('admin.system_data.title')"
			:subtitle="t('admin.system_data.subtitle')"
		>
			<UBadge
				color="primary"
				variant="subtle"
				icon="i-lucide-shield-check"
				:label="t('admin.system_data.admin_only')"
			/>
		</AdminPageHeader>

		<div class="mt-6">
			<UTabs v-model="selectedSection" :items="sectionTabs" :content="false" variant="link" class="mb-5 w-full" :ui="{ list: 'max-w-full justify-start overflow-x-auto', trigger: 'shrink-0 grow-0' }" />
			<RaidPositions v-if="selectedSection === 'raid_positions'" :raid-positions="raidPositions" />
			<BozjaData v-else :bozja-items="bozjaItems" />
		</div>
	</div>
</template>
