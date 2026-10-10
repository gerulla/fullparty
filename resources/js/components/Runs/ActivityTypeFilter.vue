<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import type { ActivityListType } from "@/Types/ActivityCore";
import { localizedValue } from "@/utils/localizedValue";

const props = defineProps<{ activityTypes: ActivityListType[] }>();
const model = defineModel<number | null>({ required: true });
const { t, locale } = useI18n();
const options = computed(() => [
	{ label: t('groups.activities.activity_type_filter.all'), value: 'all' },
	...props.activityTypes.map((type) => ({
		label: localizedValue(type.draft_name, locale.value) || type.slug || t('groups.activities.cards.unknown_type'),
		value: type.id,
	})).sort((left, right) => left.label.localeCompare(right.label, locale.value)),
]);
</script>

<template>
	<USelectMenu
		:model-value="model ?? 'all'"
		:items="options"
		value-key="value"
		:search-input="false"
		:aria-label="t('groups.activities.activity_type_filter.label')"
		:ui="{ content: 'min-w-64 max-w-[calc(100vw-2rem)]', itemLabel: 'whitespace-normal wrap-anywhere' }"
		@update:model-value="model = $event === 'all' || $event == null ? null : Number($event)"
	/>
</template>
