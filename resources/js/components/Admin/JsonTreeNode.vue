<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type JsonPrimitive = string | number | boolean | null
type JsonArray = JsonValue[]
type JsonObject = Record<string, JsonValue>
type JsonValue = JsonPrimitive | JsonArray | JsonObject
type TreeAction = "default" | "expand" | "collapse"

defineOptions({
	name: "JsonTreeNode",
});

const props = withDefaults(defineProps<{
	name?: string
	value: JsonValue
	depth?: number
	trailing?: boolean
	expandAllToken?: number
	collapseAllToken?: number
	treeAction?: TreeAction
}>(), {
	depth: 0,
	trailing: false,
	expandAllToken: 0,
	collapseAllToken: 0,
	treeAction: "default",
});

const { t } = useI18n();
const isArray = computed(() => Array.isArray(props.value));
const isObject = computed(() => typeof props.value === "object" && props.value !== null && !Array.isArray(props.value));
const isExpandable = computed(() => isArray.value || isObject.value);
const resolveInitialExpanded = () => {
	if (props.treeAction === "expand") {
		return true;
	}

	if (props.treeAction === "collapse") {
		return false;
	}

	return props.depth < 2;
};
const isExpanded = ref(resolveInitialExpanded());

const entries = computed(() => {
	if (Array.isArray(props.value)) {
		return props.value.map((value, index) => ({
			key: String(index),
			value,
			showKey: false,
		}));
	}

	if (isObject.value) {
		return Object.entries(props.value as JsonObject).map(([key, value]) => ({
			key,
			value,
			showKey: true,
		}));
	}

	return [];
});

const itemCount = computed(() => entries.value.length);
const openingToken = computed(() => isArray.value ? "[" : "{");
const closingToken = computed(() => isArray.value ? "]" : "}");
const summaryLabel = computed(() => isArray.value
	? t("admin.fflogs_playground.response.array_summary", { count: itemCount.value })
	: t("admin.fflogs_playground.response.object_summary", { count: itemCount.value }));

const primitiveClass = computed(() => {
	if (props.value === null) {
		return "text-neutral-400";
	}

	if (typeof props.value === "string") {
		return "text-emerald-300";
	}

	if (typeof props.value === "number") {
		return "text-sky-300";
	}

	if (typeof props.value === "boolean") {
		return "text-amber-300";
	}

	return "text-brand-100";
});

const primitiveValue = computed(() => {
	if (typeof props.value === "string") {
		return JSON.stringify(props.value);
	}

	if (props.value === null) {
		return "null";
	}

	return String(props.value);
});

const toggle = () => {
	isExpanded.value = !isExpanded.value;
};

watch(() => props.expandAllToken, () => {
	isExpanded.value = true;
});

watch(() => props.collapseAllToken, () => {
	isExpanded.value = false;
});
</script>

<template>
	<div class="text-xs leading-5">
		<div class="flex min-w-max items-start gap-1">
			<button
				v-if="isExpandable"
				type="button"
				class="mt-0.5 flex size-4 shrink-0 items-center justify-center text-brand-200/70 hover:text-brand-100"
				@click="toggle"
			>
				<UIcon
					:name="isExpanded ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
					class="size-3.5"
				/>
			</button>
			<span v-else class="size-4 shrink-0" />

			<span v-if="name !== undefined" class="text-violet-300">{{ JSON.stringify(name) }}<span class="text-brand-100/50">:</span></span>

			<template v-if="isExpandable">
				<span class="text-brand-100/70">{{ openingToken }}</span>
				<span v-if="!isExpanded" class="text-neutral-500">
					{{ summaryLabel }}
				</span>
				<span v-if="!isExpanded" class="text-brand-100/70">{{ closingToken }}</span>
				<span v-if="!isExpanded && trailing" class="text-brand-100/40">,</span>
			</template>

			<template v-else>
				<span :class="primitiveClass">{{ primitiveValue }}</span>
				<span v-if="trailing" class="text-brand-100/40">,</span>
			</template>
		</div>

		<div
			v-if="isExpandable && isExpanded"
			class="ml-2 border-l border-brand-700/50 pl-4"
		>
			<JsonTreeNode
				v-for="(entry, index) in entries"
				:key="`${entry.key}-${index}`"
				:name="entry.showKey ? entry.key : undefined"
				:value="entry.value"
				:depth="depth + 1"
				:trailing="index < entries.length - 1"
				:expand-all-token="expandAllToken"
				:collapse-all-token="collapseAllToken"
				:tree-action="treeAction"
			/>
		</div>

		<div
			v-if="isExpandable && isExpanded"
			class="flex min-w-max items-start gap-1"
		>
			<span class="size-4 shrink-0" />
			<span class="text-brand-100/70">{{ closingToken }}</span>
			<span v-if="trailing" class="text-brand-100/40">,</span>
		</div>
	</div>
</template>
