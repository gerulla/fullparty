<script setup lang="ts">
import { useI18n } from "vue-i18n";
import type { MyRunsToolState } from "@/Types/MyRuns";
const model = defineModel<MyRunsToolState>({ required: true });
const { t } = useI18n();
</script>

<template>
	<div class="space-y-4">
		<USwitch :model-value="model.appliedOnly" :label="t('my_runs.tools.applied_only')" :ui="{ root: 'flex-row-reverse justify-between', wrapper: 'ms-0 me-3', label: 'text-sm text-toned' }" @update:model-value="model = { ...model, appliedOnly: $event }" />
		<USwitch :model-value="model.hideOverlapping" :label="t('my_runs.tools.hide_overlapping')" :ui="{ root: 'flex-row-reverse justify-between', wrapper: 'ms-0 me-3', label: 'text-sm text-toned' }" @update:model-value="model = { ...model, hideOverlapping: $event }" />
	</div>
</template>
