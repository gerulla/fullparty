<script setup lang="ts">
import type { BozjaHolsterSummary } from '@/Types/Bozja'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
	holster: BozjaHolsterSummary
	updating: boolean
	refillCount?: number
	refillsExpanded?: boolean
}>(), {
	refillCount: 0,
	refillsExpanded: false,
})

const emit = defineEmits<{
	edit: [holsterId: number]
	toggleActive: [payload: { holsterId: number, isActive: boolean }]
	makeDefault: [holsterId: number]
	delete: [holster: BozjaHolsterSummary]
	toggleRefills: []
}>()

const { t } = useI18n()

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

const languages = computed(() => ['en', 'de', 'fr', 'ja'].filter(
	locale => String(props.holster.name?.[locale] ?? '').trim() !== '',
))
const roleLabel = computed(() => props.holster.role
	? t(roleLabels[props.holster.role] ?? props.holster.role)
	: t('groups.index.content.delubrum_reginae_savage.holsters.role_unset'))
</script>

<template>
	<article class="group flex h-full min-h-[31rem] flex-col border border-default bg-elevated/50 p-4 shadow-[0_12px_28px_rgba(0,0,0,0.2)] transition hover:border-primary/50 hover:shadow-[0_16px_34px_rgba(0,0,0,0.26)]">
		<header class="grid grid-cols-[4rem_minmax(0,1fr)_auto] items-start gap-3">
			<div class="flex size-16 items-center justify-center border border-primary/25 bg-accented/50 text-primary">
				<img
					v-if="holster.role && roleIcons[holster.role]"
					:src="roleIcons[holster.role]"
					:alt="roleLabel"
					class="size-11 object-contain"
				>
				<UIcon v-else name="i-lucide-shield-question" class="size-9" />
			</div>

			<div class="min-w-0 pt-1">
				<h2 class="line-clamp-2 font-semibold leading-5 text-highlighted">
					{{ holster.display_name || t('groups.index.content.delubrum_reginae_savage.holsters.untitled') }}
				</h2>
				<p class="mt-0.5 text-xs text-muted">{{ roleLabel }}</p>
				<div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted">
					<UBadge
						v-for="locale in languages"
						:key="locale"
						color="neutral"
						variant="subtle"
						size="sm"
						:label="locale.toUpperCase()"
					/>
					<UIcon v-if="languages.length" name="i-lucide-chevron-right" class="size-3.5" />
					<span>
						{{ t('groups.index.content.delubrum_reginae_savage.holsters.item_count', { count: holster.items.length }) }}
						· {{ holster.capacity_used }}/{{ holster.max_capacity }}
					</span>
				</div>
			</div>

			<div class="flex shrink-0 flex-col items-end gap-2">
				<label class="flex items-center gap-1.5 text-xs text-primary">
					{{ t('groups.index.content.delubrum_reginae_savage.holsters.active') }}
					<USwitch
						size="sm"
						:model-value="holster.is_active"
						:disabled="updating"
						@update:model-value="(value) => emit('toggleActive', {
							holsterId: holster.id,
							isActive: Boolean(value),
						})"
					/>
				</label>

				<UTooltip :text="t('groups.index.content.delubrum_reginae_savage.holsters.make_default')">
					<UButton
						icon="i-lucide-star"
						:color="holster.is_default ? 'warning' : 'neutral'"
						:variant="holster.is_default ? 'soft' : 'ghost'"
						size="sm"
						:disabled="holster.is_default || updating"
						:aria-label="t('groups.index.content.delubrum_reginae_savage.holsters.make_default')"
						@click="emit('makeDefault', holster.id)"
					/>
				</UTooltip>
			</div>
		</header>

		<div class="mt-5 flex min-h-16 items-start gap-3 border border-default bg-accented/35 p-3">
			<UIcon name="i-lucide-quote" class="mt-0.5 size-5 shrink-0 text-muted" />
			<p class="line-clamp-3 text-sm leading-5 text-toned">
				{{ holster.notes || t('groups.index.content.delubrum_reginae_savage.holsters.no_description') }}
			</p>
		</div>

		<div class="-mx-4 mt-5 flex flex-1 flex-col border-y border-default bg-muted/25 px-4 py-4">
			<div class="mb-4 flex items-center gap-3">
				<p class="shrink-0 text-xs font-semibold uppercase text-muted">
					{{ t('groups.index.content.delubrum_reginae_savage.holsters.actions') }} ({{ holster.items.length }})
				</p>
				<div class="h-px flex-1 bg-default" />
			</div>

			<div v-if="holster.items.length" class="space-y-2">
				<div v-for="item in holster.items.slice(0, 4)" :key="item.id" class="flex min-h-10 items-center gap-3 text-sm">
					<div class="flex size-9 shrink-0 items-center justify-center border border-default bg-elevated/70">
						<img v-if="item.icon_url" :src="item.icon_url" :alt="item.display_name" class="size-8 object-contain">
						<UIcon v-else name="i-lucide-package" class="size-4 text-muted" />
					</div>
					<span class="min-w-0 flex-1 truncate">{{ item.display_name }}</span>
					<span class="text-xs text-muted">×{{ item.quantity }}</span>
				</div>
				<p v-if="holster.items.length > 4" class="pl-12 text-xs text-muted">
					{{ t('groups.index.content.delubrum_reginae_savage.holsters.more_actions', { count: holster.items.length - 4 }) }}
				</p>
			</div>
			<p v-else class="text-sm text-muted">
				{{ t('groups.index.content.delubrum_reginae_savage.holsters.no_actions') }}
			</p>
		</div>

		<footer class="-mx-4 -mb-4 grid grid-cols-2 gap-2 border-t border-default bg-accented/30 p-4">
			<UButton
				v-if="refillCount > 0"
				color="neutral"
				variant="soft"
				:icon="refillsExpanded ? 'i-lucide-folder-open' : 'i-lucide-folder-closed'"
				:trailing-icon="refillsExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
				:label="refillsExpanded
					? t('groups.index.content.delubrum_reginae_savage.holsters.hide_refills')
					: t('groups.index.content.delubrum_reginae_savage.holsters.show_refills')"
				class="col-span-2 justify-center"
				@click="emit('toggleRefills')"
			/>
			<UButton
				color="neutral"
				variant="outline"
				icon="i-lucide-pencil"
				:label="t('groups.index.content.delubrum_reginae_savage.holsters.open_editor')"
				class="justify-center"
				@click="emit('edit', holster.id)"
			/>
			<UButton
				color="error"
				variant="outline"
				icon="i-lucide-trash-2"
				:label="t('general.delete')"
				class="justify-center"
				@click="emit('delete', holster)"
			/>
		</footer>
	</article>
</template>
