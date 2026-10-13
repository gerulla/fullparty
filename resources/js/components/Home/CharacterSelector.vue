<script lang="ts" setup>
import { computed, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { CollapsibleContent, CollapsibleRoot, CollapsibleTrigger } from 'reka-ui'
import { useAppearance } from '@/composables/useAppearance'
import type { V2HomeCharacter, V2HomeUser } from '../../Types/V2Home'

const { user, loading = false } = defineProps<{
	user?: V2HomeUser | null
	loading?: boolean
}>()
const selectedCharacter = defineModel<V2HomeCharacter | null>({ required: true })
const { t } = useI18n()
const { isDark } = useAppearance()
const badgeVariant = computed(() => isDark.value ? 'outline' : 'solid')
const open = ref(false)
const trigger = useTemplateRef<{ $el: HTMLButtonElement }>('trigger')
const otherCharacters = computed(() => user?.characters.filter(
	character => character.id !== selectedCharacter.value?.id,
) ?? [])

function selectCharacter(character: V2HomeCharacter) {
	selectedCharacter.value = character
	open.value = false
	trigger.value?.$el.focus()
}
</script>

<template>
	<div v-if="loading" class="user-panel-style" aria-busy="true">
		<p role="status" class="sr-only">{{ t('dashboard.character_selector.loading') }}</p>
		<div aria-hidden="true" class="flex items-center justify-between gap-3 bg-elevation-1 p-4 dark:bg-elevation-3">
			<div class="flex min-w-0 flex-1 items-center gap-3">
				<div class="relative shrink-0">
					<USkeleton class="size-10 rounded-full bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
					<USkeleton class="absolute -right-0.5 -top-0.5 size-3 rounded-full bg-elevation-5 ring-2 ring-elevation-1 dark:bg-elevation-7 dark:ring-elevation-3 motion-reduce:animate-none" />
				</div>
				<div class="flex min-w-0 flex-1 flex-col gap-2">
					<USkeleton class="h-4 w-40 max-w-full rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
					<USkeleton class="h-3.5 w-24 max-w-full rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
				</div>
			</div>
			<div class="flex shrink-0 items-center gap-2">
				<USkeleton class="h-5 w-16 rounded-md bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
				<USkeleton class="size-5 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
			</div>
		</div>
	</div>
	<CollapsibleRoot
		v-else-if="selectedCharacter"
		v-model:open="open"
		:disabled="otherCharacters.length === 0"
		class="user-panel-style"
	>
		<CollapsibleTrigger as-child>
			<UButton
				ref="trigger"
				type="button"
				color="neutral"
				variant="ghost"
				class="user-panel-shape w-full text-left text-default disabled:cursor-default disabled:opacity-100"
				:aria-label="t('dashboard.character_selector.change', { name: selectedCharacter.name })"
			>
				<div class="flex flex-row items-center gap-2">
					<UUser
						:name="selectedCharacter.name"
						:avatar="{
						src: selectedCharacter.avatar_url,
						loading: 'lazy',
						icon: 'i-lucide-image',
					}"
						:description="[selectedCharacter.world, selectedCharacter.datacenter].filter(Boolean).join(' - ')"
						:chip="{ color: 'success', position: 'top-right' }"
						size="lg"
					>
						<template #name>
							<span class="inline-flex items-center gap-1.5">
								<span>{{ selectedCharacter.name }}</span>
								<UTooltip v-if="selectedCharacter.is_primary" :text="t('dashboard.character_selector.primary_character')" :ui="{ tooltip: 'rounded-md' }">
									<UIcon name="i-boxicons-crown-filled" size="16" class="shrink-0 text-gold" />
								</UTooltip>
							</span>
						</template>
					</UUser>
				</div>
				<div class="flex flex-row items-center gap-2">
					<UBadge v-if="selectedCharacter.claimed_at != null" :variant="badgeVariant" size="sm" color="brand" leadingIcon="i-lucide-star">Claimed</UBadge>
					<UBadge v-else-if="selectedCharacter.verified_at != null" :variant="badgeVariant" size="sm" color="success" leadingIcon="i-lucide-check">Verified</UBadge>
					<UBadge v-else :variant="badgeVariant" size="sm" color="error" leadingIcon="i-lucide-x">Unverified</UBadge>
					<UIcon
						v-if="otherCharacters.length"
						size="20"
						name="i-lucide-chevron-right"
						class="shrink-0 transition-transform duration-200 motion-reduce:transition-none"
						:class="{ 'rotate-90': open }"
					/>
				</div>
			</UButton>
		</CollapsibleTrigger>

		<CollapsibleContent
			force-mount
			:aria-hidden="!open"
			:inert="open ? undefined : ''"
			class="max-h-80 overflow-y-auto bg-elevation-1 dark:bg-elevation-3"
		>
			<div class="character-reveal character-list-reveal">
				<div class="relative z-10 min-h-0 overflow-hidden rounded-b-lg bg-elevation-2 dark:bg-elevation-5">
					<UButton
						v-for="character in otherCharacters"
						:key="character.id"
						type="button"
						color="neutral"
						variant="ghost"
						class="w-full justify-between rounded-md p-5 text-left text-default hover:bg-elevation-3 dark:hover:bg-elevation-4 "
						:aria-label="t('dashboard.character_selector.select', { name: character.name })"
						@click="selectCharacter(character)"
					>
						<div class="flex flex-row items-center gap-2">
							<UUser
								:name="character.name"
								:avatar="{ src: character.avatar_url, loading: 'lazy', icon: 'i-lucide-image' }"
								:description="[character.world, character.datacenter].filter(Boolean).join(' - ')"
								size="lg"
								class=""
							>
								<template #name>
									<span class="inline-flex items-center gap-1.5">
										<span>{{ character.name }}</span>
										<UTooltip v-if="character.is_primary" :text="t('dashboard.character_selector.primary_character')" :ui="{ tooltip: 'rounded-md' }">
											<UIcon name="i-boxicons-crown-filled" size="16" class="shrink-0 text-gold" />
										</UTooltip>
									</span>
								</template>
							</UUser>
						</div>
						<div class="flex flex-row items-center gap-2">
							<UBadge v-if="character.claimed_at != null" :variant="badgeVariant" size="sm" color="brand" leadingIcon="i-lucide-star">Claimed</UBadge>
							<UBadge v-else-if="character.verified_at != null" :variant="badgeVariant" size="sm" color="success" leadingIcon="i-lucide-check">Verified</UBadge>
							<UBadge v-else :variant="badgeVariant" size="sm" color="error" leadingIcon="i-lucide-x">Unverified</UBadge>
						</div>
					</UButton>
				</div>
			</div>
			<div class="character-reveal character-actions-reveal">
				<div class="min-h-0 overflow-hidden">
					<div class="w-full flex flex-row items-center justify-between p-2 bg-elevation-1 dark:bg-elevation-3">
						<UButton color="neutral" variant="ghost" size="xs" leadingIcon="i-lucide-plus" class="hover:text-muted active:text-zinc-700" >Add Character</UButton>
						<UButton color="neutral" variant="ghost" size="xs" class="text-muted hover:text-default active:text-zinc-700">How to Verify?</UButton>
					</div>
				</div>
			</div>
		</CollapsibleContent>
	</CollapsibleRoot>
</template>

<style scoped>
@reference '../../../css/app.css';

.user-panel-shape {
	@apply relative z-10 rounded-lg p-4 flex flex-row items-center justify-between cursor-pointer;
	@apply bg-elevation-1 dark:bg-elevation-3;
}

.user-panel-style {
	--character-reveal-duration: 180ms;
	/*Base*/
	@apply overflow-hidden shadow-md rounded-lg transition-all duration-100;
	/*LightMode*/
	@apply bg-elevation-2;
	/*DarkMode*/
	@apply dark:bg-elevation-5 dark:border-y dark:border-t-elevation-7 dark:border-b-elevation-2;
}

.user-panel-style[data-state='closed']:not([data-disabled]):hover {
	@apply scale-105;
}

.user-panel-shape:active {
	@apply bg-elevation-2 dark:bg-elevation-2;
}

/* Keep both stages mounted so their heights can reverse smoothly on repeated clicks. */
.character-reveal {
	display: grid;
	grid-template-rows: 0fr;
	transition: grid-template-rows var(--character-reveal-duration) ease-out;
}

.character-list-reveal {
	transition-delay: var(--character-reveal-duration);
}

.character-actions-reveal {
	opacity: 0;
	visibility: hidden;
	transition:
		grid-template-rows var(--character-reveal-duration) ease-out,
		opacity var(--character-reveal-duration) ease-out,
		visibility 0s var(--character-reveal-duration);
}

.user-panel-style[data-state='open'] .character-reveal {
	grid-template-rows: 1fr;
}

.user-panel-style[data-state='open'] .character-list-reveal {
	transition-delay: 0s;
}

.user-panel-style[data-state='open'] .character-actions-reveal {
	opacity: 1;
	visibility: visible;
	transition-delay: var(--character-reveal-duration);
}

@media (prefers-reduced-motion: reduce) {
	.character-reveal {
		transition: none;
	}
}
</style>
