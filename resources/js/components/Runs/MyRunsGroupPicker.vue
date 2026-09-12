<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import type { MyRunsGroup } from "@/Types/MyRuns";
defineProps<{ groups: MyRunsGroup[] }>();
const model = defineModel<number[]>({ required: true });
const { t } = useI18n();
const toggleGroup = (id: number, checked: boolean | 'indeterminate') => {
	model.value = checked === true ? [...new Set([...model.value, id])] : model.value.filter((groupId) => groupId !== id);
};
</script>

<template>
	<ul class="space-y-2">
		<li v-for="group in groups" :key="group.id" class="flex min-h-10 items-center gap-2">
			<UCheckbox :model-value="model.includes(group.id)" class="min-w-0 flex-1" :ui="{ root: 'items-center', wrapper: 'min-w-0 flex-1', label: 'flex min-w-0 items-center gap-2.5 text-sm font-normal' }" @update:model-value="toggleGroup(group.id, $event)">
				<template #label>
					<UAvatar :src="group.profile_picture_url ?? undefined" :alt="group.name" icon="i-lucide-shield" size="sm" class="shrink-0" />
					<span class="min-w-0 wrap-anywhere">{{ group.name }}</span>
				</template>
			</UCheckbox>
			<UTooltip :text="t('my_runs.tools.open_group', { name: group.name })">
				<UButton :to="route('groups.dashboard', { group: group.slug })" icon="i-lucide-arrow-up-right" color="neutral" variant="ghost" size="sm" class="shrink-0" :aria-label="t('my_runs.tools.open_group', { name: group.name })" />
			</UTooltip>
		</li>
	</ul>
</template>
