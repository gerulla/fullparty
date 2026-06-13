<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import RaidPositions from "@/components/Admin/RaidPositions.vue";
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
}>();

const { t } = useI18n();
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
			<RaidPositions :raid-positions="raidPositions" />
		</div>
	</div>
</template>
