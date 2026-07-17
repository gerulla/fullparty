<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { LocalizedText } from "@/Types/Common";
import type { ManualAssignmentCharacter, QueueFilterField } from "@/Types/ActivityQueue";
import type { ActivityFillInPartyOption, ActivitySlot } from "@/Types/ActivityRoster";
import type { ActivitySlotFieldSelection, HolsterPairValue } from "@/Types/ActivityHolsters";
import HolsterPairSelector from "@/components/Groups/Activities/HolsterPairSelector.vue";

const props = defineProps<{
	open: boolean
	slot: ActivitySlot | null
	characters: ManualAssignmentCharacter[]
	slotFieldDefinitions: QueueFilterField[]
	fillInPartyOptions: ActivityFillInPartyOption[]
	isSubmitting?: boolean
	initialCharacterId?: number | null
	lockCharacter?: boolean
}>()

const emit = defineEmits<{
	"update:open": [value: boolean]
	confirm: [payload: { characterId: number, slotId: number, fieldValues: Record<string, ActivitySlotFieldSelection>, filledGroupKey?: string | null, sourceSlotId?: number | null }]
}>()

const { t, locale } = useI18n()
const page = usePage()
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"))
const selectedCharacterId = ref<number | null>(null)
const selections = ref<Record<string, ActivitySlotFieldSelection>>({})
const selectedFilledGroupKey = ref<string | null>(null)

const isOpen = computed({
	get: () => props.open,
	set: (value: boolean) => emit("update:open", value),
})

const localizedTextValue = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
)

const isFillInSlot = computed(() => Boolean(props.slot?.is_fill_in))
const fillInPartyItems = computed(() => props.fillInPartyOptions.map((option) => ({
	label: localizedTextValue(option.label, option.key),
	value: option.key,
})))
const hasFilledPartySelection = computed(() => !isFillInSlot.value || Boolean(selectedFilledGroupKey.value))

const selectedCharacter = computed(() => (
	props.characters.find((character) => character.id === selectedCharacterId.value) ?? null
))

const characterItems = computed(() => props.characters.map((character) => ({
	label: `${character.name}${character.world ? ` (${character.world})` : ""}${character.user?.name ? ` - ${character.user.name}` : ""}`,
	value: character.id,
	avatar_url: character.avatar_url,
})))

const targetFieldDefinitions = computed(() => {
	if (!props.slot || props.slot.is_bench) {
		return []
	}

	return props.slot.field_values
		.map((fieldValue) => props.slotFieldDefinitions.find((field) => field.key === fieldValue.field_key))
		.filter((field): field is QueueFilterField => Boolean(field && field.options.length > 0))
})

const compatibleOptionsByField = computed(() => {
	const map: Record<string, Array<{ label: string, value: string }>> = {}

	for (const field of targetFieldDefinitions.value) {
		if (field.type === "holster_pair") {
			continue
		}

		map[field.key] = field.options.map((option) => ({
			label: localizedTextValue(option.label, option.key),
			value: option.key,
		}))
	}

	return map
})

const firstAvailableHolsterPair = (field: QueueFilterField): HolsterPairValue | null => {
	const prepop = field.options.find((option) => option.meta?.holster_type === "prepop"
		&& field.options.some((refill) => refill.meta?.holster_type === "refill"
			&& String(refill.meta.parent_holster_id ?? "") === option.key))
	const refill = prepop
		? field.options.find((option) => option.meta?.holster_type === "refill"
			&& String(option.meta.parent_holster_id ?? "") === prepop.key)
		: null

	return prepop && refill
		? { prepop_id: prepop.key, refill_id: refill.key }
		: null
}

const hasCompatibleOptions = computed(() => targetFieldDefinitions.value.every((field) => (
	field.type === "holster_pair"
		? firstAvailableHolsterPair(field) !== null
		: (compatibleOptionsByField.value[field.key] ?? []).length > 0
)))

const canSubmit = computed(() => {
	if (!props.slot || !selectedCharacter.value || !hasCompatibleOptions.value || !hasFilledPartySelection.value) {
		return false
	}

	return targetFieldDefinitions.value.every((field) => {
		const selectedValue = selections.value[field.key]
		if (field.type === "holster_pair") {
			const pair = selectedValue as HolsterPairValue | undefined

			return Boolean(pair?.prepop_id && pair?.refill_id)
		}

		if (Array.isArray(selectedValue)) {
			return selectedValue.length > 0
		}

		return Boolean(selectedValue)
	})
})

const normalizeCurrentSlotValue = (
	currentSlotValue: unknown,
	compatibleOptions: Array<{ label: string, value: string }>,
): string | string[] => {
	if (Array.isArray(currentSlotValue)) {
		return currentSlotValue
			.map((entry) => {
				if (typeof entry === "object" && entry !== null) {
					const record = entry as Record<string, unknown>

					if (record.id !== undefined && record.id !== null) {
						return String(record.id)
					}

					if (record.key !== undefined && record.key !== null) {
						return String(record.key)
					}
				}

				return String(entry)
			})
			.filter((value) => value !== "" && compatibleOptions.some((option) => option.value === value))
	}

	if (typeof currentSlotValue === "object" && currentSlotValue !== null) {
		const record = currentSlotValue as Record<string, unknown>

		if (record.id !== undefined && record.id !== null) {
			const normalizedId = String(record.id)

			if (compatibleOptions.some((option) => option.value === normalizedId)) {
				return normalizedId
			}
		}

		if (record.key !== undefined && record.key !== null) {
			const normalizedKey = String(record.key)

			if (compatibleOptions.some((option) => option.value === normalizedKey)) {
				return normalizedKey
			}
		}
	}

	return ""
}

watch(
	() => props.open,
	(value) => {
		if (!value) {
			return
		}

		selectedCharacterId.value = props.initialCharacterId ?? null
	},
)

watch(
	() => [props.open, props.slot?.id, props.fillInPartyOptions.length] as const,
	() => {
		if (!props.open || !props.slot?.is_fill_in) {
			selectedFilledGroupKey.value = null
			return
		}

		selectedFilledGroupKey.value = props.slot.filled_group_key ?? props.fillInPartyOptions[0]?.key ?? null
	},
	{ immediate: true },
)

watch(
	() => [props.open, selectedCharacterId.value, props.slot?.id] as const,
	() => {
		if (!props.open) {
			return
		}

		if (!props.slot || !selectedCharacter.value) {
			selections.value = {}
			return
		}

		const defaults: Record<string, ActivitySlotFieldSelection> = {}

		for (const field of targetFieldDefinitions.value) {
			if (field.type === "holster_pair") {
				const currentValue = props.slot.field_values.find((fieldValue) => fieldValue.field_key === field.key)?.value
				const pair = currentValue && typeof currentValue === "object" && !Array.isArray(currentValue)
					? currentValue as Record<string, unknown>
					: null
				const currentPair = {
					prepop_id: pair?.prepop_id == null ? "" : String(pair.prepop_id),
					refill_id: pair?.refill_id == null ? "" : String(pair.refill_id),
				}
				defaults[field.key] = currentPair.prepop_id && currentPair.refill_id
					? currentPair
					: firstAvailableHolsterPair(field) ?? currentPair
				continue
			}

			const compatibleOptions = compatibleOptionsByField.value[field.key] ?? []

			if (compatibleOptions.length === 0) {
				continue
			}

			const currentSlotValue = props.slot.field_values.find((fieldValue) => fieldValue.field_key === field.key)?.value
			const normalizedCurrentValue = normalizeCurrentSlotValue(currentSlotValue, compatibleOptions)

			if (field.type === "multi_select") {
				defaults[field.key] = Array.isArray(normalizedCurrentValue) && normalizedCurrentValue.length > 0
					? normalizedCurrentValue
					: compatibleOptions.map((option) => option.value)
				continue
			}

			defaults[field.key] = typeof normalizedCurrentValue === "string" && normalizedCurrentValue
				? normalizedCurrentValue
				: compatibleOptions[0].value
		}

		selections.value = defaults
	},
	{ immediate: true },
)

const updateFieldSelection = (fieldKey: string, value: ActivitySlotFieldSelection | undefined) => {
	selections.value = {
		...selections.value,
		[fieldKey]: value ?? "",
	}
}

const updateSelectedCharacter = (value: string | number | null | undefined) => {
	if (value === null || value === undefined || value === "") {
		selectedCharacterId.value = null
		return
	}

	selectedCharacterId.value = typeof value === "number" ? value : Number(value)
}

const updateSelectedFilledGroupKey = (value: string | number | null | undefined) => {
	selectedFilledGroupKey.value = value === null || value === undefined || value === ""
		? null
		: String(value)
}

const submit = () => {
	if (!props.slot || !selectedCharacter.value || !canSubmit.value) {
		return
	}

	emit("confirm", {
		characterId: selectedCharacter.value.id,
		slotId: props.slot.id,
		fieldValues: selections.value,
		filledGroupKey: isFillInSlot.value ? selectedFilledGroupKey.value : null,
	})
}
</script>

<template>
	<UModal
		v-model:open="isOpen"
		:title="t('groups.activities.management.manual_assignment.title')"
		:description="slot ? localizedTextValue(slot.slot_label, slot.slot_key) : undefined"
		:ui="{ content: 'sm:max-w-2xl', body: 'max-h-[calc(100dvh-12rem)] overflow-y-auto' }"
	>
		<template #body>
			<div class="space-y-5">
				<div class="grid gap-4 md:grid-cols-2">
					<div class="border border-default bg-default/60 p-4">
						<p class="text-xs uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.manual_assignment.member_character') }}
						</p>
						<p class="mt-2 font-medium text-toned">
							{{ selectedCharacter?.name || t('groups.activities.management.roster.empty_slot') }}
						</p>
						<p class="text-sm text-muted">
							{{ selectedCharacter?.user?.name || "—" }}
						</p>
					</div>

					<div class="border border-default bg-default/60 p-4">
						<p class="text-xs uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.roster.title') }}
						</p>
						<p class="mt-2 font-medium text-toned">
							{{ slot ? localizedTextValue(slot.group_label, slot.group_key) : "—" }}
						</p>
						<p class="text-sm text-muted">
							{{ slot ? localizedTextValue(slot.slot_label, slot.slot_key) : "—" }}
						</p>
					</div>
				</div>

				<UFormField :label="t('groups.activities.management.manual_assignment.member_character')">
					<USelectMenu
						:model-value="selectedCharacterId"
						:items="characterItems"
						value-key="value"
						label-key="label"
						size="lg"
						class="w-full"
						searchable
						:disabled="lockCharacter"
						:placeholder="t('groups.activities.management.manual_assignment.select_character')"
						@update:model-value="updateSelectedCharacter"
					/>
				</UFormField>

				<UFormField
					v-if="isFillInSlot"
					:label="t('groups.activities.management.roster.fill_ins.filled_party')"
				>
					<USelectMenu
						:model-value="selectedFilledGroupKey ?? ''"
						:items="fillInPartyItems"
						value-key="value"
						label-key="label"
						size="lg"
						class="w-full"
						:disabled="isSubmitting"
						@update:model-value="updateSelectedFilledGroupKey"
					/>
				</UFormField>

				<UAlert
					v-if="slot?.assigned_character_id && slot.assignment_source === 'manual'"
					color="warning"
					variant="soft"
					:title="t('groups.activities.management.manual_assignment.editing_assignment_title')"
					:description="t('groups.activities.management.manual_assignment.editing_assignment_body')"
				/>

				<UAlert
					v-if="!selectedCharacter"
					color="warning"
					variant="soft"
					:title="t('groups.activities.management.messages.warning_title')"
					:description="t('groups.activities.management.manual_assignment.select_character_warning')"
				/>

				<UAlert
					v-else-if="!hasCompatibleOptions"
					color="error"
					variant="soft"
					:title="t('general.error')"
					:description="t('groups.activities.management.manual_assignment.incompatible_fields')"
				/>

				<div v-else-if="targetFieldDefinitions.length > 0" class="space-y-4">
					<UFormField
						v-for="field in targetFieldDefinitions"
						:key="field.key"
						:label="localizedTextValue(field.label, field.key)"
					>
						<HolsterPairSelector
							v-if="field.type === 'holster_pair'"
							:model-value="selections[field.key]"
							:options="field.options"
							@update:model-value="(value) => updateFieldSelection(field.key, value)"
						/>
						<USelectMenu
							v-else
							:model-value="selections[field.key]"
							:multiple="field.type === 'multi_select'"
							size="lg"
							class="w-full"
							:items="compatibleOptionsByField[field.key] ?? []"
							value-key="value"
							label-key="label"
							:placeholder="t('groups.activities.management.queue.filter_any')"
							@update:model-value="(value) => updateFieldSelection(field.key, value)"
						/>
					</UFormField>
				</div>
			</div>
		</template>

		<template #footer>
			<div class="flex w-full items-center justify-end gap-3">
				<UButton
					color="neutral"
					variant="ghost"
					:label="t('general.cancel')"
					@click="isOpen = false"
				/>
				<UButton
					color="primary"
					icon="i-lucide-user-plus"
					:loading="isSubmitting"
					:disabled="!canSubmit"
					:label="t('groups.activities.management.manual_assignment.confirm')"
					@click="submit"
				/>
			</div>
		</template>
	</UModal>
</template>
