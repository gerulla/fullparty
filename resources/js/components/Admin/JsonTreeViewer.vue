<script setup lang="ts">
import JsonTreeNode from "@/components/Admin/JsonTreeNode.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

type JsonPrimitive = string | number | boolean | null
type JsonArray = JsonValue[]
type JsonObject = Record<string, JsonValue>
type JsonValue = JsonPrimitive | JsonArray | JsonObject
type TreeAction = "default" | "expand" | "collapse"

const props = defineProps<{
	value: unknown
}>();

const { t } = useI18n();
const expandAllToken = ref(0);
const collapseAllToken = ref(0);
const treeAction = ref<TreeAction>("default");

const normalizeValue = (value: unknown): JsonValue => {
	if (
		value === null
		|| typeof value === "string"
		|| typeof value === "number"
		|| typeof value === "boolean"
	) {
		return value;
	}

	if (Array.isArray(value)) {
		return value.map((item) => normalizeValue(item));
	}

	if (typeof value === "object") {
		return Object.fromEntries(Object.entries(value as Record<string, unknown>)
			.map(([key, item]) => [key, normalizeValue(item)]));
	}

	return String(value);
};

const normalizedValue = computed(() => normalizeValue(props.value));

const expandAll = () => {
	treeAction.value = "expand";
	expandAllToken.value += 1;
};

const collapseAll = () => {
	treeAction.value = "collapse";
	collapseAllToken.value += 1;
};
</script>

<template>
	<div class="overflow-hidden border border-default bg-neutral-950/70">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-elevated/30 px-3 py-2">
			<div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
				<UIcon name="i-lucide-braces" class="size-4 text-brand-200" />
				<span>{{ t("admin.fflogs_playground.response.viewer_label") }}</span>
			</div>

			<div class="flex flex-wrap items-center gap-2">
				<UButton
					size="xs"
					color="neutral"
					variant="ghost"
					icon="i-lucide-list-collapse"
					:label="t('admin.fflogs_playground.response.collapse_all')"
					@click="collapseAll"
				/>
				<UButton
					size="xs"
					color="neutral"
					variant="ghost"
					icon="i-lucide-list-tree"
					:label="t('admin.fflogs_playground.response.expand_all')"
					@click="expandAll"
				/>
			</div>
		</div>

		<div class="json-tree-scroll max-h-[calc(100vh-18rem)] min-h-[36rem] overflow-auto p-4 font-mono">
			<JsonTreeNode
				:value="normalizedValue"
				:expand-all-token="expandAllToken"
				:collapse-all-token="collapseAllToken"
				:tree-action="treeAction"
			/>
		</div>
	</div>
</template>

