<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
	banner: {
		id: number
		title: string
		message: string
		action_label: string | null
		action_url: string | null
		updated_at: string | null
	}
}>()

const { t } = useI18n()
const isCollapsed = ref(false)

const collapsedStorageKey = computed(() => [
	'fullparty.system_banner.collapsed',
	props.banner.id,
	props.banner.updated_at ?? 'current',
].join('.'))

const isCompact = computed(() => isCollapsed.value)

const isExternalUrl = (url: string | null) => {
	if (!url) {
		return false
	}

	return /^(https?:)?\/\//.test(url)
}

const readCollapsedState = () => {
	try {
		isCollapsed.value = window.localStorage.getItem(collapsedStorageKey.value) === 'true'
	} catch {
		isCollapsed.value = false
	}
}

const setCollapsed = (collapsed: boolean) => {
	isCollapsed.value = collapsed

	try {
		window.localStorage.setItem(collapsedStorageKey.value, collapsed ? 'true' : 'false')
	} catch {
		// Keep the current render state even if storage is blocked.
	}
}

onMounted(() => {
	readCollapsedState()
})

watch(collapsedStorageKey, () => {
	readCollapsedState()
})
</script>

<template>
	<section
		class="overflow-hidden border-b border-amber-200 bg-amber-50 text-amber-950 transition-all duration-300 dark:border-amber-900/60 dark:bg-amber-950/35 dark:text-amber-100"
	>
		<div
			class="flex px-4 transition-all duration-300 sm:px-6 lg:px-8"
			:class="isCompact ? 'flex-row items-center justify-between gap-3 py-2' : 'flex-col gap-4 py-4 xl:flex-row xl:items-center xl:justify-between'"
		>
			<div
				class="min-w-0"
				:class="isCompact ? '' : 'space-y-1.5'"
			>
				<div class="flex items-center gap-2">
					<UIcon
						name="i-lucide-badge-alert"
						class="shrink-0 text-amber-600 transition-all duration-300 dark:text-amber-300"
						:class="isCompact ? 'h-4 w-4' : 'h-5 w-5'"
					/>
					<h2
						class="truncate font-semibold transition-all duration-300"
						:class="isCompact ? 'text-xs' : 'text-sm sm:text-base'"
					>
						{{ banner.title }}
					</h2>
				</div>

				<div
					class="grid transition-all duration-300"
					:class="isCompact ? 'grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr] opacity-100'"
				>
					<div class="min-h-0 overflow-hidden">
						<p class="max-w-5xl whitespace-pre-line text-sm leading-6 text-amber-900/85 dark:text-amber-100/85">
							{{ banner.message }}
						</p>
					</div>
				</div>
			</div>

			<div class="flex shrink-0 items-center gap-2">
				<UButton
					v-if="banner.action_label && banner.action_url && !isCompact"
					as="a"
					:href="banner.action_url"
					:target="isExternalUrl(banner.action_url) ? '_blank' : undefined"
					:rel="isExternalUrl(banner.action_url) ? 'noopener noreferrer' : undefined"
					color="warning"
					variant="solid"
					icon="i-lucide-arrow-right"
					size="md"
					:label="banner.action_label"
					:aria-label="banner.action_label"
				/>
				<UButton
					color="warning"
					variant="ghost"
					:icon="isCompact ? 'i-lucide-chevron-down' : 'i-lucide-chevron-up'"
					:label="isCompact ? t('navigation.topbar.system_banner.show') : t('navigation.topbar.system_banner.hide')"
					size="xs"
					@click="setCollapsed(!isCollapsed)"
				/>
			</div>
		</div>
	</section>
</template>
