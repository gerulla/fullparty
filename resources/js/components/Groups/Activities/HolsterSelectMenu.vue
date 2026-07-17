<script setup lang="ts">
import type { HolsterContentItem, HolsterPairOption } from '@/Types/ActivityHolsters'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePage } from '@inertiajs/vue3'
import { localizedValue } from '@/utils/localizedValue'

const props = defineProps<{
	modelValue?: string
	options: HolsterPairOption[]
	disabled?: boolean
	placeholder: string
}>()

const emit = defineEmits<{
	'update:modelValue': [value: string | undefined]
}>()

const { t, locale } = useI18n()
const page = usePage()
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'))
const open = ref(false)
const searchTerm = ref('')
const previewValue = ref<string>()
const requiresConfirmation = ref(false)
let touchMediaQuery: MediaQueryList | null = null

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

const localizedLabel = (value: HolsterPairOption['label'], fallback: string) => localizedValue(
	value,
	locale.value,
	fallbackLocale.value,
) || fallback

const items = computed(() => props.options.map(option => ({
	label: localizedLabel(option.label, option.key),
	value: option.key,
	iconUrl: option.meta?.role ? roleIcons[option.meta.role] : undefined,
	roleLabel: option.meta?.role ? t(roleLabels[option.meta.role] ?? option.meta.role) : null,
	holster: option,
})))

const selectedItem = computed(() => items.value.find(item => item.value === props.modelValue))
const filteredItems = computed(() => {
	const query = searchTerm.value.trim().toLocaleLowerCase()

	if (!query) {
		return items.value
	}

	return items.value.filter(item => [item.label, item.roleLabel]
		.filter(Boolean)
		.some(value => String(value).toLocaleLowerCase().includes(query)))
})
const previewItem = computed(() => items.value.find(item => item.value === previewValue.value)
	?? filteredItems.value[0]
	?? null)

const holsterItemLabel = (item: HolsterContentItem) => localizedLabel(item.label, item.key)
const itemCount = (option: HolsterPairOption) => option.meta?.items?.length ?? 0

const syncTouchMode = () => {
	requiresConfirmation.value = Boolean(touchMediaQuery?.matches)
}

const initializePreview = () => {
	searchTerm.value = ''
	previewValue.value = selectedItem.value?.value ?? items.value[0]?.value
}

const previewOption = (value: string) => {
	previewValue.value = value
}

const chooseOption = (value: string) => {
	previewOption(value)

	if (!requiresConfirmation.value) {
		emit('update:modelValue', value)
		open.value = false
	}
}

const confirmPreview = () => {
	if (!previewItem.value) {
		return
	}

	emit('update:modelValue', previewItem.value.value)
	open.value = false
}

watch(open, (isOpen) => {
	if (isOpen) {
		initializePreview()
	}
})

watch(filteredItems, (availableItems) => {
	if (!open.value || availableItems.some(item => item.value === previewValue.value)) {
		return
	}

	previewValue.value = availableItems[0]?.value
})

onMounted(() => {
	touchMediaQuery = window.matchMedia('(hover: none), (pointer: coarse)')
	syncTouchMode()
	touchMediaQuery.addEventListener('change', syncTouchMode)
})

onBeforeUnmount(() => {
	touchMediaQuery?.removeEventListener('change', syncTouchMode)
})
</script>

<template>
	<UPopover
		v-model:open="open"
		:content="{ side: 'bottom', align: 'start', sideOffset: 8, collisionPadding: 8 }"
		:ui="{ content: 'w-[60rem] max-w-[calc(100vw-1rem)] overflow-hidden border border-default bg-elevated p-0 shadow-2xl' }"
	>
		<button
			type="button"
			class="flex h-10 w-full items-center gap-2 border border-accented bg-default px-3 text-left text-sm text-highlighted transition hover:border-primary focus-visible:border-primary focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
			:disabled="disabled"
			:aria-expanded="open"
		>
			<img
				v-if="selectedItem?.iconUrl"
				:src="selectedItem.iconUrl"
				alt=""
				class="size-5 shrink-0 object-contain"
			>
			<UIcon v-else name="i-lucide-shield-question" class="size-5 shrink-0 text-muted" />
			<span class="min-w-0 flex-1 truncate" :class="{ 'text-muted': !selectedItem }">
				{{ selectedItem?.label ?? placeholder }}
			</span>
			<UIcon name="i-lucide-chevron-down" class="size-4 shrink-0 text-muted transition-transform" :class="{ 'rotate-180': open }" />
		</button>

		<template #content>
			<section class="flex max-h-[min(42rem,calc(100vh-1rem))] flex-col">
				<header class="flex shrink-0 items-center justify-between gap-3 border-b border-default px-4 py-3">
					<h3 class="font-semibold text-highlighted">
						{{ t('groups.activities.application.holsters.selector_title') }}
					</h3>
					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-x"
						:aria-label="t('general.close')"
						@click="open = false"
					/>
				</header>

				<div class="grid min-h-0 flex-1 md:grid-cols-[20rem_minmax(0,1fr)]">
					<div class="flex min-h-0 flex-col border-b border-default md:border-r md:border-b-0">
						<div class="shrink-0 border-b border-default p-3">
							<UInput
								v-model="searchTerm"
								icon="i-lucide-search"
								size="lg"
								class="w-full"
								:placeholder="t('groups.activities.application.holsters.search_placeholder')"
							/>
						</div>

						<div class="max-h-52 min-h-0 overflow-y-auto p-2 md:max-h-none md:flex-1">
							<button
								v-for="item in filteredItems"
								:key="item.value"
								type="button"
								class="flex w-full items-center gap-3 border px-3 py-2.5 text-left transition"
								:class="previewItem?.value === item.value
									? 'border-primary bg-primary/10'
									: 'border-transparent hover:border-default hover:bg-accented/45'"
								@mouseenter="previewOption(item.value)"
								@click="chooseOption(item.value)"
							>
								<div class="flex size-10 shrink-0 items-center justify-center border border-default bg-accented/60">
									<img v-if="item.iconUrl" :src="item.iconUrl" alt="" class="size-8 object-contain">
									<UIcon v-else name="i-lucide-shield-question" class="size-6 text-muted" />
								</div>
								<div class="min-w-0 flex-1">
									<p class="truncate font-medium text-highlighted">{{ item.label }}</p>
									<p class="truncate text-xs text-muted">
										<span v-if="item.roleLabel">{{ item.roleLabel }} · </span>
										{{ t('groups.activities.application.holsters.items_count', { count: itemCount(item.holster) }) }}
									</p>
								</div>
								<UIcon
									v-if="modelValue === item.value"
									name="i-lucide-circle-check"
									class="size-5 shrink-0 text-primary"
								/>
							</button>

							<div v-if="filteredItems.length === 0" class="px-3 py-8 text-center text-sm text-muted">
								{{ t('groups.activities.application.holsters.no_results') }}
							</div>
						</div>
					</div>

					<div class="min-h-0 overflow-y-auto p-4 md:p-5">
						<template v-if="previewItem">
							<div class="flex items-start gap-4 border-b border-default pb-4">
								<div class="flex size-16 shrink-0 items-center justify-center border border-primary/40 bg-accented/60">
									<img v-if="previewItem.iconUrl" :src="previewItem.iconUrl" alt="" class="size-12 object-contain">
									<UIcon v-else name="i-lucide-shield-question" class="size-10 text-muted" />
								</div>
								<div class="min-w-0 flex-1 pt-1">
									<div class="flex flex-wrap items-center gap-2">
										<h4 class="text-lg font-semibold text-highlighted">{{ previewItem.label }}</h4>
										<UBadge v-if="previewItem.roleLabel" color="neutral" variant="soft" :label="previewItem.roleLabel" />
									</div>
									<p class="mt-1 text-sm text-muted">
										{{ t('groups.activities.application.holsters.items_count', { count: itemCount(previewItem.holster) }) }}
									</p>
								</div>
							</div>

							<h5 class="mt-4 text-sm font-semibold text-highlighted">
								{{ t('groups.activities.application.holsters.contains') }}
							</h5>

							<div v-if="previewItem.holster.meta?.items?.length" class="mt-3 divide-y divide-default border border-default">
								<div
									v-for="holsterItem in previewItem.holster.meta.items"
									:key="holsterItem.key"
									class="flex items-center gap-3 bg-default/45 px-3 py-2.5"
								>
									<img
										v-if="holsterItem.icon_url"
										:src="holsterItem.icon_url"
										:alt="holsterItemLabel(holsterItem)"
										class="size-11 shrink-0 object-contain"
									>
									<UIcon v-else name="i-lucide-package" class="size-11 shrink-0 text-muted" />
									<span class="min-w-0 flex-1 text-sm text-toned">{{ holsterItemLabel(holsterItem) }}</span>
									<UBadge color="neutral" variant="outline" :label="`×${holsterItem.quantity}`" />
								</div>
							</div>
							<p v-else class="mt-3 border border-default bg-default/45 px-3 py-6 text-center text-sm text-muted">
								{{ t('groups.activities.application.holsters.no_items') }}
							</p>

							<div v-if="requiresConfirmation" class="sticky bottom-0 mt-4 border-t border-default bg-elevated pt-4">
								<UButton
									color="neutral"
									class="w-full justify-center"
									icon="i-lucide-check"
									:label="t('groups.activities.application.holsters.select_action')"
									@click="confirmPreview"
								/>
							</div>
						</template>
					</div>
				</div>
			</section>
		</template>
	</UPopover>
</template>
