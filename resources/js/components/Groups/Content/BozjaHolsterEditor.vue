<script setup lang="ts">
import LocalizedTextFields from '@/components/Admin/ActivityTypes/LocalizedTextFields.vue'
import type { BozjaHolsterItem, BozjaHolsterSummary, BozjaItemOption } from '@/Types/Bozja'
import axios from 'axios'
import { MdEditor, type ToolbarNames } from 'md-editor-v3'
import { useToast } from '@nuxt/ui/composables'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{
	groupSlug: string
	holster: BozjaHolsterSummary | null
	holsters: BozjaHolsterSummary[]
	bozjaItems: BozjaItemOption[]
	isCreating: boolean
}>()

const emit = defineEmits<{
	saved: [holster: BozjaHolsterSummary]
}>()

const locales = ['en', 'de', 'fr', 'ja']
const guideToolbars: ToolbarNames[] = [
	'bold',
	'italic',
	'strikeThrough',
	'-',
	'title',
	'quote',
	'unorderedList',
	'orderedList',
	'task',
	'-',
	'codeRow',
	'code',
	'link',
	'table',
	'-',
	'revoke',
	'next',
	'=',
	'preview',
	'fullscreen',
]
const { t } = useI18n()
const toast = useToast()
const search = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const detailsSection = ref<HTMLElement | null>(null)
const guideSection = ref<HTMLElement | null>(null)
const contentsSection = ref<HTMLElement | null>(null)
const draft = reactive({
	name: {} as Record<string, string>,
	role: null as BozjaHolsterSummary['role'],
	type: 'prepop' as BozjaHolsterSummary['type'],
	parent_holster_id: null as number | null,
	max_capacity: 99,
	notes: '',
	guide: '',
	items: [] as BozjaHolsterItem[],
})

const normalizeLocalizedName = (value: unknown) => typeof value === 'string' ? value : ''

const resetDraft = () => {
	draft.name = Object.fromEntries(locales.map(locale => [
		locale,
		normalizeLocalizedName(props.holster?.name?.[locale]),
	]))
	draft.role = props.holster?.role ?? null
	draft.type = props.holster?.type ?? 'prepop'
	draft.parent_holster_id = props.holster?.parent_holster_id ?? null
	draft.max_capacity = props.holster?.max_capacity ?? 99
	draft.notes = props.holster?.notes ?? ''
	draft.guide = props.holster?.guide ?? ''
	draft.items = (props.holster?.items ?? []).map(item => ({ ...item }))
	search.value = ''
	errors.value = {}
}

watch(
	() => [props.holster?.id, props.isCreating],
	resetDraft,
	{ immediate: true },
)

const capacityUsed = computed(() => draft.items.reduce(
	(total, item) => total + item.cache_weight * item.quantity,
	0,
))
const roleOptions = computed(() => [
	{ label: t('general.roles.tank'), value: 'tank' },
	{ label: t('general.roles.healer'), value: 'healer' },
	{ label: t('general.roles.melee_dps'), value: 'melee dps' },
	{ label: t('general.roles.physical_ranged_dps'), value: 'physical ranged dps' },
	{ label: t('general.roles.magic_ranged_dps'), value: 'magic ranged dps' },
])
const typeOptions = computed(() => [
	{ label: t('groups.index.content.delubrum_reginae_savage.holsters.types.prepop'), value: 'prepop' },
	{ label: t('groups.index.content.delubrum_reginae_savage.holsters.types.refill'), value: 'refill' },
])
const parentHolsterOptions = computed(() => props.holsters
	.filter(holster => holster.type === 'prepop' && holster.id !== props.holster?.id)
	.map(holster => ({
		label: holster.display_name
			?? t('groups.index.content.delubrum_reginae_savage.holsters.untitled'),
		value: holster.id,
	})))
const roleIcons: Record<string, string> = {
	tank: '/role-icons/tank.png',
	healer: '/role-icons/healer.png',
	'melee dps': '/role-icons/melee_dps.png',
	'physical ranged dps': '/role-icons/physrange_dps.png',
	'magic ranged dps': '/role-icons/magic_range_dps.png',
}
const roleLabels: Record<string, string> = {
	tank: 'general.roles.tank',
	healer: 'general.roles.healer',
	'melee dps': 'general.roles.melee_dps',
	'physical ranged dps': 'general.roles.physical_ranged_dps',
	'magic ranged dps': 'general.roles.magic_ranged_dps',
}
const roleLabel = computed(() => draft.role
	? t(roleLabels[draft.role] ?? draft.role)
	: t('groups.index.content.delubrum_reginae_savage.holsters.role_unset'))
const draftDisplayName = computed(() => {
	const names = Object.values(draft.name)
		.map(value => normalizeLocalizedName(value).trim())
		.filter(Boolean)

	return names[0]
		?? (props.isCreating
			? t('groups.index.content.delubrum_reginae_savage.holsters.new_title')
			: t('groups.index.content.delubrum_reginae_savage.holsters.untitled'))
})
const localeStatuses = computed(() => locales.map(locale => ({
	locale,
	isFilled: Boolean(draft.name[locale]?.trim()),
})))
const capacityRemaining = computed(() => Math.max(0, draft.max_capacity - capacityUsed.value))
const capacityExceeded = computed(() => capacityUsed.value > draft.max_capacity)
const capacityPercentage = computed(() => Math.min(
	100,
	Math.round((capacityUsed.value / Math.max(1, draft.max_capacity)) * 100),
))
const capacityRingStyle = computed(() => ({
	background: `conic-gradient(var(--color-brand-400) 0 ${capacityPercentage.value}%, var(--ui-bg-accented) ${capacityPercentage.value}% 100%)`,
}))
const detailsComplete = computed(() => Boolean(
	Object.values(draft.name).some(value => normalizeLocalizedName(value).trim())
	&& draft.role
	&& draft.type
	&& (draft.type !== 'refill' || draft.parent_holster_id),
))
const selectedItemIds = computed(() => new Set(draft.items.map(item => item.id)))
const filteredItems = computed(() => {
	const query = search.value.trim().toLowerCase()

	return props.bozjaItems
		.filter(item => !selectedItemIds.value.has(item.id))
		.filter(item => query === '' || [
			item.display_name,
			item.key,
			item.classification,
			...Object.values(item.name ?? {}),
		].some(value => String(value ?? '').toLowerCase().includes(query)))
})

const maxQuantityFor = (item: BozjaItemOption | BozjaHolsterItem) => {
	if (item.cache_weight <= 0) {
		return 99
	}

	const usedWithoutItem = capacityUsed.value
		- ('quantity' in item ? item.cache_weight * item.quantity : 0)

	return Math.max(0, Math.min(99, Math.floor((draft.max_capacity - usedWithoutItem) / item.cache_weight)))
}

const canAdd = (item: BozjaItemOption) => maxQuantityFor(item) >= 1

const addItem = (item: BozjaItemOption) => {
	if (!canAdd(item)) {
		return
	}

	draft.items.push({ ...item, quantity: 1 })
}

const removeItem = (itemId: number) => {
	draft.items = draft.items.filter(item => item.id !== itemId)
}

const updateQuantity = (item: BozjaHolsterItem, value: string | number) => {
	const quantity = Number(value)
	item.quantity = Math.max(1, Math.min(
		Number.isFinite(quantity) ? Math.floor(quantity) : 1,
		maxQuantityFor(item),
	))
}

const changeQuantity = (item: BozjaHolsterItem, difference: number) => {
	updateQuantity(item, item.quantity + difference)
}

const scrollToSection = (section: HTMLElement | null) => {
	section?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const firstError = computed(() => Object.values(errors.value)[0] ?? '')

watch(() => draft.type, type => {
	if (type !== 'refill') {
		draft.parent_holster_id = null
	}
})

const save = async () => {
	if (saving.value || capacityExceeded.value) {
		return
	}

	saving.value = true
	errors.value = {}

	const payload = {
		name: draft.name,
		role: draft.role,
		type: draft.type,
		parent_holster_id: draft.type === 'refill' ? draft.parent_holster_id : null,
		notes: draft.notes || null,
		guide: draft.guide || null,
		items: draft.items.map(item => ({ id: item.id, quantity: item.quantity })),
	}

	try {
		const response = props.holster
			? await axios.put(route('groups.dashboard.content.delubrum-reginae-savage.holsters.update', {
				group: props.groupSlug,
				bozjaHolster: props.holster.id,
			}), payload)
			: await axios.post(route('groups.dashboard.content.delubrum-reginae-savage.holsters.store', props.groupSlug), payload)

		emit('saved', response.data.data)
		toast.add({
			title: t('groups.index.content.delubrum_reginae_savage.holsters.saved'),
			color: 'success',
			icon: 'i-lucide-check',
		})
	} catch (error: any) {
		errors.value = Object.fromEntries(
			Object.entries(error.response?.data?.errors ?? {}).map(([key, messages]) => [
				key,
				Array.isArray(messages) ? String(messages[0] ?? '') : String(messages),
			]),
		)
		toast.add({
			title: t('general.error'),
			description: firstError.value || t('groups.index.content.delubrum_reginae_savage.holsters.save_failed'),
			color: 'error',
			icon: 'i-lucide-circle-alert',
		})
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<section class="min-w-0">
		<div v-if="!holster && !isCreating" class="flex min-h-96 flex-col items-center justify-center px-6 text-center">
			<UIcon name="i-lucide-panel-right-open" class="size-7 text-muted" />
			<p class="mt-3 text-sm font-medium">
				{{ t('groups.index.content.delubrum_reginae_savage.holsters.no_selection') }}
			</p>
		</div>

		<template v-else>
			<UAlert
				v-if="firstError"
				color="error"
				variant="soft"
				icon="i-lucide-circle-alert"
				:description="firstError"
				class="mb-4"
			/>

			<div class="grid items-start gap-4 xl:grid-cols-[14rem_minmax(0,1fr)_18rem] 2xl:grid-cols-[16rem_minmax(0,1fr)_20rem]">
				<aside class="border border-default bg-elevated/45 p-4 xl:sticky xl:top-20">
					<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
						{{ t('groups.index.content.delubrum_reginae_savage.holsters.summary') }}
					</p>

					<div class="mt-4 flex items-center gap-3 border-b border-default pb-4">
						<div class="flex size-12 shrink-0 items-center justify-center border border-primary/30 bg-accented/45 text-primary">
							<img
								v-if="draft.role && roleIcons[draft.role]"
								:src="roleIcons[draft.role]"
								:alt="roleLabel"
								class="size-9 object-contain"
							>
							<UIcon v-else name="i-lucide-shield-question" class="size-7" />
						</div>
						<div class="min-w-0">
							<p class="truncate text-sm font-semibold text-highlighted">{{ draftDisplayName }}</p>
							<p class="truncate text-xs text-muted">{{ roleLabel }}</p>
							<p class="mt-1 flex items-center gap-1 text-[11px] text-muted">
								<span class="size-1.5" :class="holster?.is_active === false ? 'bg-muted' : 'bg-success'" />
								{{ holster?.is_active === false
									? t('groups.index.content.delubrum_reginae_savage.holsters.inactive')
									: t('groups.index.content.delubrum_reginae_savage.holsters.active') }}
							</p>
						</div>
					</div>

					<div class="border-b border-default py-4">
						<p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.locales') }}
						</p>
						<div class="mt-3 grid grid-cols-4 gap-1.5">
							<div
								v-for="status in localeStatuses"
								:key="status.locale"
								class="flex items-center justify-center gap-1 border border-default bg-muted/25 px-1.5 py-1 text-[11px]"
							>
								<span class="font-semibold uppercase">{{ status.locale }}</span>
								<UIcon
									:name="status.isFilled ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
									:class="status.isFilled ? 'text-success' : 'text-error'"
								/>
							</div>
						</div>
					</div>

					<div class="border-b border-default py-4">
						<p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.capacity.title') }}
						</p>
						<div class="mx-auto mt-4 flex size-24 items-center justify-center p-2" :style="capacityRingStyle">
							<div class="flex size-full flex-col items-center justify-center bg-elevated text-center">
								<p class="font-semibold tabular-nums" :class="capacityExceeded ? 'text-error' : ''">
									{{ capacityUsed }}<span class="text-xs text-muted"> / {{ draft.max_capacity }}</span>
								</p>
								<p class="text-[11px] text-muted">{{ capacityPercentage }}%</p>
							</div>
						</div>
						<p class="mt-4 text-xs text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.capacity.remaining', { count: capacityRemaining }) }}
						</p>
						<UProgress
							:model-value="Math.min(capacityUsed, draft.max_capacity)"
							:max="draft.max_capacity"
							:color="capacityExceeded ? 'error' : capacityRemaining === 0 ? 'warning' : 'primary'"
							class="mt-2"
							:ui="{ base: 'rounded-none', indicator: 'rounded-none' }"
						/>
					</div>

					<nav class="border-b border-default py-4">
						<p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.sections') }}
						</p>
						<button type="button" class="flex w-full items-center gap-2 border border-transparent px-2 py-2 text-left hover:border-default hover:bg-muted/25" @click="scrollToSection(detailsSection)">
							<UIcon name="i-lucide-file-pen-line" class="size-4 text-primary" />
							<span class="min-w-0 flex-1">
								<span class="block text-xs font-medium">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.title') }}</span>
								<span class="block truncate text-[11px] text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.section_details') }}</span>
							</span>
							<UIcon :name="detailsComplete ? 'i-lucide-circle-check' : 'i-lucide-circle'" :class="detailsComplete ? 'text-success' : 'text-muted'" />
						</button>
						<button type="button" class="flex w-full items-center gap-2 border border-transparent px-2 py-2 text-left hover:border-default hover:bg-muted/25" @click="scrollToSection(guideSection)">
							<UIcon name="i-lucide-book-open-text" class="size-4 text-primary" />
							<span class="min-w-0 flex-1">
								<span class="block text-xs font-medium">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.guide') }}</span>
								<span class="block truncate text-[11px] text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.section_guide') }}</span>
							</span>
							<UIcon :name="draft.guide ? 'i-lucide-circle-check' : 'i-lucide-circle'" :class="draft.guide ? 'text-success' : 'text-muted'" />
						</button>
						<button type="button" class="flex w-full items-center gap-2 border border-primary/45 bg-primary/8 px-2 py-2 text-left" @click="scrollToSection(contentsSection)">
							<UIcon name="i-lucide-package-open" class="size-4 text-primary" />
							<span class="min-w-0 flex-1">
								<span class="block text-xs font-medium">{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.title') }}</span>
								<span class="block truncate text-[11px] text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.section_contents', { count: draft.items.length }) }}</span>
							</span>
							<UBadge color="primary" variant="subtle" size="sm" :label="String(draft.items.length)" />
						</button>
					</nav>

					<UButton
						icon="i-lucide-save"
						:label="t('general.save')"
						:loading="saving"
						:disabled="capacityExceeded"
						class="mt-4 w-full justify-center"
						@click="save"
					/>
				</aside>

				<main class="min-w-0 space-y-4">
					<section ref="detailsSection" class="scroll-mt-20 border border-default bg-elevated/45">
						<header class="flex items-center gap-3 border-b border-default px-4 py-3">
							<span class="flex size-6 items-center justify-center border border-primary text-xs font-semibold text-primary">1</span>
							<div>
								<h3 class="font-semibold">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.title') }}</h3>
								<p class="text-xs text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.description') }}</p>
							</div>
						</header>
						<div class="grid items-start gap-5 p-4 md:grid-cols-2">
							<div class="space-y-5">
								<div class="grid items-start gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
									<LocalizedTextFields
										v-model="draft.name"
										:locales="locales"
										:label="t('groups.index.content.delubrum_reginae_savage.holsters.metadata.name')"
										:all-locales-label="t('groups.index.content.delubrum_reginae_savage.holsters.manage_locales')"
										class="holster-localized-fields"
									/>
									<UFormField class="self-end" :label="t('groups.index.content.delubrum_reginae_savage.holsters.metadata.role')" :error="errors.role">
										<USelect v-model="draft.role" :items="roleOptions" value-key="value" class="w-full" />
									</UFormField>
								</div>
								<div class="grid items-start gap-4" :class="draft.type === 'refill' ? 'sm:grid-cols-2' : ''">
									<UFormField :label="t('groups.index.content.delubrum_reginae_savage.holsters.metadata.type')" :error="errors.type">
										<USelect v-model="draft.type" :items="typeOptions" value-key="value" class="w-full" />
									</UFormField>
									<UFormField
										v-if="draft.type === 'refill'"
										:label="t('groups.index.content.delubrum_reginae_savage.holsters.metadata.parent_holster')"
										:error="errors.parent_holster_id"
									>
										<USelect
											v-model="draft.parent_holster_id"
											:items="parentHolsterOptions"
											value-key="value"
											:placeholder="t('groups.index.content.delubrum_reginae_savage.holsters.metadata.parent_holster_placeholder')"
											class="w-full"
										/>
									</UFormField>
								</div>
							</div>
							<UFormField :label="t('general.notes')" :error="errors.notes">
								<UTextarea v-model="draft.notes" :rows="7" class="w-full" />
							</UFormField>
						</div>
					</section>

					<section ref="guideSection" class="scroll-mt-20 border border-default bg-elevated/45">
						<header class="flex items-center gap-3 border-b border-default px-4 py-3">
							<span class="flex size-6 items-center justify-center border border-primary text-xs font-semibold text-primary">2</span>
							<div>
								<h3 class="font-semibold">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.guide') }}</h3>
								<p class="text-xs text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.metadata.guide_description') }}</p>
							</div>
						</header>
						<div class="p-3">
							<UFormField :error="errors.guide">
								<MdEditor
									v-model="draft.guide"
									language="en-US"
									theme="dark"
									:toolbars="guideToolbars"
									:no-mermaid="true"
									:no-katex="true"
									:no-echarts="true"
									class="holster-guide-editor"
									style="height: 26rem"
								/>
							</UFormField>
						</div>
					</section>

					<section ref="contentsSection" class="scroll-mt-20 border border-default bg-elevated/45">
						<header class="flex items-center gap-3 border-b border-default px-4 py-3">
							<span class="flex size-6 items-center justify-center border border-primary text-xs font-semibold text-primary">3</span>
							<div class="min-w-0 flex-1">
								<h3 class="font-semibold">
									{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.title') }}
									<span class="ml-1 text-xs font-normal text-muted">({{ draft.items.length }})</span>
								</h3>
								<p class="text-xs text-muted">{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.description') }}</p>
							</div>
						</header>

						<div class="p-4">
							<div v-if="draft.items.length" class="overflow-x-auto border border-default">
								<div class="min-w-[34rem]">
									<div class="grid grid-cols-[minmax(0,1fr)_8rem_5rem_2.5rem] gap-3 border-b border-default bg-muted/30 px-3 py-2 text-xs font-semibold text-muted">
										<span>{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.item') }}</span>
										<span class="text-center">{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.quantity') }}</span>
										<span class="text-center">{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.weight_label') }}</span>
										<span />
									</div>
									<div
										v-for="item in draft.items"
										:key="item.id"
										class="grid grid-cols-[minmax(0,1fr)_8rem_5rem_2.5rem] items-center gap-3 border-b border-default px-3 py-2 last:border-b-0"
									>
										<div class="flex min-w-0 items-center gap-2">
											<img v-if="item.icon_url" :src="item.icon_url" :alt="item.display_name" class="size-8 shrink-0 object-contain">
											<UIcon v-else name="i-lucide-package" class="size-5 shrink-0 text-muted" />
											<span class="truncate text-sm font-medium">{{ item.display_name }}</span>
										</div>
										<div class="grid grid-cols-[2rem_minmax(0,1fr)_2rem] border border-default">
											<UButton icon="i-lucide-minus" color="neutral" variant="ghost" :disabled="item.quantity <= 1" @click="changeQuantity(item, -1)" />
											<UInput :model-value="item.quantity" type="number" :min="1" :max="maxQuantityFor(item)" variant="none" class="holster-quantity-input text-center" @update:model-value="updateQuantity(item, $event)" />
											<UButton icon="i-lucide-plus" color="neutral" variant="ghost" :disabled="item.quantity >= maxQuantityFor(item)" @click="changeQuantity(item, 1)" />
										</div>
										<span class="text-center text-sm tabular-nums">{{ item.cache_weight * item.quantity }}</span>
										<UButton icon="i-lucide-trash-2" color="error" variant="ghost" :aria-label="t('general.remove')" @click="removeItem(item.id)" />
									</div>
								</div>
							</div>
							<div v-else class="border border-dashed border-default px-4 py-10 text-center text-sm text-muted">
								{{ t('groups.index.content.delubrum_reginae_savage.holsters.contents.empty') }}
							</div>
						</div>
					</section>
				</main>

				<aside class="flex max-h-[calc(100dvh-10rem)] flex-col border border-default bg-elevated/45 xl:sticky xl:top-20">
					<header class="border-b border-default p-4">
						<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.library') }}
						</p>
						<UInput v-model="search" icon="i-lucide-search" :placeholder="t('groups.index.content.delubrum_reginae_savage.holsters.contents.search')" class="mt-3 w-full" />
					</header>

					<div class="min-h-0 flex-1 overflow-y-auto">
						<button
							v-for="item in filteredItems"
							:key="item.id"
							type="button"
							class="flex w-full items-center gap-2 border-b border-default px-3 py-2 text-left transition"
							:class="canAdd(item) ? 'hover:bg-muted/35' : 'cursor-not-allowed opacity-45'"
							:disabled="!canAdd(item)"
							@click="addItem(item)"
						>
							<div class="flex size-9 shrink-0 items-center justify-center border border-default bg-muted/30">
								<img v-if="item.icon_url" :src="item.icon_url" :alt="item.display_name" class="size-8 object-contain">
								<UIcon v-else name="i-lucide-package" class="size-5 text-muted" />
							</div>
							<span class="min-w-0 flex-1">
								<span class="block truncate text-sm font-medium">{{ item.display_name }}</span>
								<span class="block truncate text-[11px] text-muted">{{ item.classification }}</span>
							</span>
							<UBadge
								color="neutral"
								variant="soft"
								size="sm"
								:label="t('groups.index.content.delubrum_reginae_savage.holsters.contents.weight', { weight: item.cache_weight })"
							/>
							<UIcon name="i-lucide-plus" class="size-4 text-primary" />
						</button>
						<div v-if="!filteredItems.length" class="px-4 py-10 text-center text-sm text-muted">
							{{ t('groups.index.content.delubrum_reginae_savage.holsters.library_empty') }}
						</div>
					</div>

					<footer class="border-t border-default bg-muted/25 px-4 py-3 text-xs text-muted">
						{{ t('groups.index.content.delubrum_reginae_savage.holsters.library_count', {
							shown: filteredItems.length,
							total: bozjaItems.length - selectedItemIds.size,
						}) }}
					</footer>
				</aside>
			</div>
		</template>
	</section>
</template>

<style scoped>
.holster-guide-editor {
	--md-color: var(--ui-text);
	--md-hover-color: var(--ui-text-highlighted);
	--md-bk-color: var(--ui-bg);
	--md-bk-color-outstand: var(--ui-bg);
	--md-bk-hover-color: var(--ui-bg-elevated);
	--md-border-color: var(--ui-border);
	--md-border-hover-color: var(--color-brand-400);
	--md-border-active-color: var(--color-brand-500);

	border-radius: 0;
}

:deep(.holster-guide-editor .md-editor-preview),
:deep(.holster-guide-editor .md-editor-preview-wrapper) {
	color: var(--ui-text);
}

:deep(.holster-guide-editor .md-editor-preview ul) {
	list-style-type: disc;
	padding-inline-start: 2rem;
}

:deep(.holster-guide-editor .md-editor-preview ul ul) {
	list-style-type: circle;
}

:deep(.holster-guide-editor .md-editor-preview ul ul ul) {
	list-style-type: square;
}

:deep(.holster-guide-editor .md-editor-preview ol) {
	list-style-type: decimal;
	padding-inline-start: 2rem;
}

:deep(.holster-guide-editor .md-editor-preview li::marker) {
	color: var(--ui-text);
}

:deep(.holster-quantity-input input[type='number']) {
	-moz-appearance: textfield;
}

:deep(.holster-quantity-input input[type='number']::-webkit-inner-spin-button),
:deep(.holster-quantity-input input[type='number']::-webkit-outer-spin-button) {
	margin: 0;
	appearance: none;
}

:deep(.holster-localized-fields .rounded-full),
:deep(.holster-localized-fields .rounded-lg) {
	border-radius: 0;
}
</style>
