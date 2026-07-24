<script setup lang="ts">
import { useToast } from '@nuxt/ui/composables'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
	RaidPlanAuthor,
	RaidPlanFightOption,
	RaidPlanLinks,
	RaidPlanVisibility,
} from '../../types/RaidPlan'

const { t } = useI18n()
const toast = useToast()
const name = defineModel<string>('name', { required: true })
const description = defineModel<string>('description', { required: true })
const fightId = defineModel<number | null>('fightId', { required: true })
const visibility = defineModel<RaidPlanVisibility>('visibility', { required: true })

const props = defineProps<{
	fightOptions: RaidPlanFightOption[]
	author: RaidPlanAuthor | null
	links: RaidPlanLinks | null
	disabled: boolean
	errors: Partial<Record<'name' | 'description' | 'fight_id' | 'visibility', string>>
}>()

const fightItems = computed(() => props.fightOptions.map((fight) => ({
	...fight,
	value: fight.id,
	avatar: fight.image_url ? {
		src: fight.image_url,
		alt: fight.label,
	} : undefined,
})))

const selectedFight = computed(() => (
	props.fightOptions.find((fight) => fight.id === fightId.value) ?? null
))

const visibilityItems = computed(() => [
	{
		label: t('planner.editor.plan.visibility_unlisted'),
		value: 'unlisted',
	},
	{
		label: t('planner.editor.plan.visibility_public'),
		value: 'public',
	},
])

const visibilityDescription = computed(() => (
	visibility.value === 'public'
		? t('planner.editor.plan.visibility_public_description')
		: t('planner.editor.plan.visibility_unlisted_description')
))

const copyLink = async (link: string): Promise<void> => {
	await navigator.clipboard.writeText(link)
	toast.add({
		title: t('planner.editor.plan.link_copied'),
		icon: 'i-lucide-check',
		color: 'success',
	})
}
</script>

<template>
	<div class="space-y-5 p-3">
		<UFormField
			:label="t('planner.editor.plan.name')"
			:error="props.errors.name"
			required
		>
			<UInput
				v-model="name"
				:maxlength="150"
				:disabled="props.disabled"
				class="w-full"
			/>
		</UFormField>

		<UFormField
			:label="t('planner.editor.plan.description')"
			:error="props.errors.description"
		>
			<UTextarea
				v-model="description"
				:maxlength="5000"
				:rows="5"
				:placeholder="t('planner.editor.plan.description_placeholder')"
				:disabled="props.disabled"
				autoresize
				class="w-full"
			/>
		</UFormField>

		<UFormField
			:label="t('planner.editor.plan.fight')"
			:error="props.errors.fight_id"
		>
			<div class="flex items-center gap-2">
				<USelectMenu
					v-model="fightId"
					:items="fightItems"
					value-key="value"
					:avatar="selectedFight?.image_url ? {
						src: selectedFight.image_url,
						alt: selectedFight.label,
					} : undefined"
					:placeholder="t('planner.editor.plan.fight_placeholder')"
					:disabled="props.disabled"
					class="min-w-0 flex-1"
				/>
				<UTooltip
					v-if="fightId !== null && !props.disabled"
					:text="t('planner.editor.plan.clear_fight')"
				>
					<UButton
						icon="i-lucide-x"
						color="neutral"
						variant="ghost"
						:aria-label="t('planner.editor.plan.clear_fight')"
						@click="fightId = null"
					/>
				</UTooltip>
			</div>
		</UFormField>

		<UFormField
			:label="t('planner.editor.plan.visibility')"
			:description="visibilityDescription"
			:error="props.errors.visibility"
			required
		>
			<USelect
				v-model="visibility"
				:items="visibilityItems"
				value-key="value"
				:disabled="props.disabled"
				class="w-full"
			/>
		</UFormField>

		<USeparator />

		<section class="space-y-2">
			<p class="text-xs font-semibold uppercase text-muted">
				{{ t('planner.editor.plan.author') }}
			</p>
			<UUser
				v-if="props.author"
				:name="props.author.name"
				:avatar="{
					src: props.author.avatar_url ?? undefined,
					alt: props.author.name,
					icon: 'i-lucide-user',
				}"
			/>
			<div v-else class="flex items-center gap-2 text-sm text-muted">
				<UIcon name="i-lucide-user-round-x" class="size-5" />
				<span>{{ t('planner.editor.plan.anonymous_author') }}</span>
			</div>
		</section>

		<USeparator />

		<section class="space-y-3">
			<p class="text-xs font-semibold uppercase text-muted">
				{{ t('planner.editor.plan.links') }}
			</p>

			<p v-if="!props.links" class="text-sm text-muted">
				{{ t('planner.editor.plan.links_after_save') }}
			</p>

			<UFormField v-if="props.links" :label="t('planner.editor.plan.view_link')">
				<UInput :model-value="props.links.view" readonly class="w-full">
					<template #trailing>
						<UButton
							icon="i-lucide-copy"
							color="neutral"
							variant="link"
							size="xs"
							:aria-label="t('planner.editor.plan.copy_link')"
							@click="copyLink(props.links.view)"
						/>
					</template>
				</UInput>
			</UFormField>

			<UFormField
				v-if="props.links?.edit"
				:label="t('planner.editor.plan.edit_link')"
			>
				<UInput :model-value="props.links.edit" readonly class="w-full">
					<template #trailing>
						<UButton
							icon="i-lucide-copy"
							color="neutral"
							variant="link"
							size="xs"
							:aria-label="t('planner.editor.plan.copy_link')"
							@click="copyLink(props.links.edit)"
						/>
					</template>
				</UInput>
			</UFormField>
		</section>
	</div>
</template>
