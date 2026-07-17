<script setup lang="ts">
import type { HolsterPairOption, HolsterPairValue } from '@/Types/ActivityHolsters'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import HolsterSelectMenu from '@/components/Groups/Activities/HolsterSelectMenu.vue'

const props = defineProps<{
	modelValue: unknown
	options: HolsterPairOption[]
	multiple?: boolean
	disabled?: boolean
	allowedPairs?: HolsterPairValue[]
}>()

const emit = defineEmits<{
	'update:modelValue': [value: HolsterPairValue | HolsterPairValue[]]
}>()

const { t } = useI18n()
const emptyPair = (): HolsterPairValue => ({ prepop_id: '', refill_id: '' })

const normalizePair = (value: unknown): HolsterPairValue | null => {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return null
	}

	const pair = value as Record<string, unknown>

	return {
		prepop_id: pair.prepop_id == null ? '' : String(pair.prepop_id),
		refill_id: pair.refill_id == null ? '' : String(pair.refill_id),
	}
}

const selectedPairs = computed(() => {
	if (props.multiple) {
		return Array.isArray(props.modelValue)
			? props.modelValue.map(normalizePair).filter((pair): pair is HolsterPairValue => pair !== null)
			: []
	}

	const pair = normalizePair(props.modelValue)

	return pair ? [pair] : []
})

const rows = computed(() => selectedPairs.value.length > 0 ? selectedPairs.value : [emptyPair()])
const allowedPairKeys = computed(() => props.allowedPairs
	? new Set(props.allowedPairs.map(pair => `${pair.prepop_id}:${pair.refill_id}`))
	: null)
const prepopOptions = computed(() => props.options
	.filter(option => option.meta?.holster_type === 'prepop'
		&& props.options.some(refill => refill.meta?.holster_type === 'refill'
			&& String(refill.meta.parent_holster_id ?? '') === option.key
			&& (!allowedPairKeys.value || allowedPairKeys.value.has(`${option.key}:${refill.key}`)))))

const refillOptions = (prepopId: string) => props.options
	.filter(option => option.meta?.holster_type === 'refill'
		&& String(option.meta.parent_holster_id ?? '') === prepopId
		&& (!allowedPairKeys.value || allowedPairKeys.value.has(`${prepopId}:${option.key}`)))

const emitPairs = (pairs: HolsterPairValue[]) => {
	emit('update:modelValue', props.multiple ? pairs : (pairs[0] ?? emptyPair()))
}

const updatePair = (index: number, field: keyof HolsterPairValue, value: unknown) => {
	const pairs = rows.value.map(pair => ({ ...pair }))
	pairs[index][field] = value == null ? '' : String(value)

	if (field === 'prepop_id') {
		pairs[index].refill_id = ''
	}

	emitPairs(pairs)
}

const addPair = () => emitPairs([...rows.value.map(pair => ({ ...pair })), emptyPair()])
const removePair = (index: number) => emitPairs(rows.value.filter((_, pairIndex) => pairIndex !== index))
</script>

<template>
	<div class="space-y-3">
		<div
			v-for="(pair, index) in rows"
			:key="index"
			class="grid gap-3 border-b border-default pb-3 last:border-b-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
		>
			<UFormField :label="t('groups.activities.application.holsters.prepop')">
				<HolsterSelectMenu
					:model-value="pair.prepop_id || undefined"
					:options="prepopOptions"
					:disabled="disabled"
					:placeholder="t('groups.activities.application.holsters.prepop_placeholder')"
					@update:model-value="updatePair(index, 'prepop_id', $event)"
				/>
			</UFormField>

			<UFormField :label="t('groups.activities.application.holsters.refill')">
				<HolsterSelectMenu
					:model-value="pair.refill_id || undefined"
					:options="refillOptions(pair.prepop_id)"
					:disabled="disabled || !pair.prepop_id"
					:placeholder="t('groups.activities.application.holsters.refill_placeholder')"
					@update:model-value="updatePair(index, 'refill_id', $event)"
				/>
			</UFormField>

			<UTooltip v-if="multiple" :text="t('groups.activities.application.holsters.remove_pair')">
				<UButton
					color="error"
					variant="ghost"
					icon="i-lucide-trash-2"
					class="self-end"
					:disabled="disabled"
					:aria-label="t('groups.activities.application.holsters.remove_pair')"
					@click="removePair(index)"
				/>
			</UTooltip>
		</div>

		<UButton
			v-if="multiple"
			color="neutral"
			variant="soft"
			icon="i-lucide-plus"
			:label="t('groups.activities.application.holsters.add_pair')"
			:disabled="disabled"
			@click="addPair"
		/>
	</div>
</template>
