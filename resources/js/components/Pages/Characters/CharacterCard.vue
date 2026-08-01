<script setup>

import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {useI18n} from "vue-i18n";
import { route } from 'ziggy-js';
import ClassElement from "@/components/Characters/ClassElement.vue";
import PhantomJobElement from "@/components/Characters/PhantomJobElement.vue";
import ForkedTowerBloodProgress from "@/components/Characters/ForkedTowerBloodProgress.vue";
import ForkedTowerMagicProgress from "@/components/Characters/ForkedTowerMagicProgress.vue";

const props = defineProps({
	character: {
		type: Object,
		required: true,
	},
})
const { t } = useI18n()
const open = ref(props.character.is_primary)
const isRefreshing = ref(false)
const isMakingPrimary = ref(false)
const isRemoving = ref(false)
const removeModalOpen = ref(false)

const classRoleOrder = ['tank', 'healer', 'melee dps', 'physical ranged dps', 'magic ranged dps'];

const groupedClasses = computed(() => {
	const grouped = classRoleOrder
		.map((role) => ({
			role,
			classes: props.character.classes.filter((characterClass) => characterClass.role === role)
		}))
		.filter((group) => group.classes.length > 0);

	return grouped;
});

const getRoleLabel = (role) => {
	const roleLabels = {
		'tank': t('general.roles.tank'),
		'healer': t('general.roles.healer'),
		'melee dps': t('general.roles.melee_dps'),
		'physical ranged dps': t('general.roles.physical_ranged_dps'),
		'magic ranged dps': t('general.roles.magic_ranged_dps'),
	};

	return roleLabels[role] || role;
};

const refreshCharacterData = () => {
	isRefreshing.value = true;

	router.post(route('characters.refresh', props.character.id), {}, {
		onError: (errors) => {
			console.error('[FullParty] Character refresh request failed.', {
				character: {
					id: props.character.id,
					name: props.character.name,
					world: props.character.world,
					datacenter: props.character.datacenter,
					lodestone_id: props.character.lodestone_id,
				},
				errors,
			});
		},
		onFinish: () => {
			isRefreshing.value = false;
		},
	});
};

const makePrimary = () => {
	isMakingPrimary.value = true;

	router.post(route('characters.make-primary', props.character.id), {}, {
		onFinish: () => {
			isMakingPrimary.value = false;
		},
	});
};

const removeCharacter = () => {
	isRemoving.value = true;

	router.delete(route('characters.destroy', props.character.id), {
		onFinish: () => {
			isRemoving.value = false;
			removeModalOpen.value = false;
		},
	});
};
</script>

<template>
	<UCard :ui="{ root: 'border-l-2 border-brand-800 bg-neutral-950' }">
<!--	<UCard :ui="{ root: 'border border-white/10 bg-white/5 p-8 backdrop-blur-xl shadow-[0_10px_30px_rgba(0,0,0,0.35)]' }">-->

		<UCollapsible v-model:open="open" class="flex flex-col gap-2 w-full">
			<div class="w-full flex flex-row items-stretch gap-3 sm:gap-0">
				<div id="div1" class="flex shrink-0 flex-row items-start border-2 border-blue-800 shadow-lg shadow-blue-800">
					<img
						class="h-24 w-24 rounded-sm object-cover"
						:src="character.avatar_url"
						:alt="character.name+' avatar'"
					>
				</div>

				<div id="div2" class="flex h-24 min-w-0 flex-1 flex-col justify-between items-start sm:ml-4">
					<div class="flex min-w-0 flex-row items-center gap-1">
						<p class="truncate text-lg font-semibold">{{character.name}}</p>
						<UBadge v-if="character.is_primary" class="hidden sm:inline-flex" :ui="{base:'rounded-sm'}" icon="i-lucide-star" size="md" color="neutral" variant="soft">{{ t('general.primary') }}</UBadge>
						<UIcon v-if="character.is_primary" name="i-lucide-star" class="size-4 shrink-0 text-brand-300 sm:hidden" />
						<UBadge v-if="character.verified_at!==null" class="hidden sm:inline-flex" :ui="{base:'rounded-sm'}" icon="i-lucide-check-circle" size="md" color="success" variant="soft">{{ t('general.verified') }}</UBadge>
						<UIcon v-if="character.verified_at!==null" name="i-lucide-check-circle" class="size-4 shrink-0 text-success sm:hidden" />
						<UBadge v-if="character.verified_at===null" class="hidden sm:inline-flex" :ui="{base:'rounded-sm'}" icon="i-lucide-x-circle" size="md" color="error" variant="soft">{{ t('general.unverified') }}</UBadge>
						<UIcon v-if="character.verified_at===null" name="i-lucide-x-circle" class="size-4 shrink-0 text-error sm:hidden" />
					</div>

					<div class="flex flex-row gap-1 text-muted text-sm sm:gap-2">
						<span class="hidden sm:inline">{{character.datacenter}}</span>
						<span class="hidden sm:inline">&middot;</span>
						<span class="hidden sm:inline">{{character.world}}</span>
						<span class="hidden sm:inline">&middot;</span>
						<span>{{ character.add_method === 'manual' ? t('characters.added_manually') : t('characters.from_xivauth') }}</span>
					</div>

					<div class="flex flex-row gap-1 text-brand">
						<a :href="'https://na.finalfantasyxiv.com/lodestone/character/'+character.lodestone_id" class="flex flex-row items-center gap-1 font-thin p-0 text-sm hover:underline hover:bg-transparent cursor-pointer" variant="ghost">
							{{ t('characters.card.view_lodestone_profile') }}
							<UIcon name="i-lucide-square-arrow-out-up-right" size="12" />
						</a>
					</div>
				</div>

				<div id="div3" class="ml-auto flex flex-col items-center justify-between">
					<div class="flex flex-row items-center">
						<UButton
							v-if="!character.is_primary && character.verified_at !== null"
							@click.stop="makePrimary"
							:loading="isMakingPrimary"
							:disabled="isMakingPrimary"
							color="neutral"
							variant="soft"
							icon="i-lucide-star"
							class="sm:hidden"
							:aria-label="t('characters.card.make_primary')"
						/>
						<UButton
							v-if="!character.is_primary && character.verified_at !== null"
							@click.stop="makePrimary"
							:label="t('characters.card.make_primary')"
							:loading="isMakingPrimary"
							:disabled="isMakingPrimary"
							color="neutral"
							variant="soft"
							icon="i-lucide-star"
							class="mr-2 hidden sm:inline-flex"
						/>
						<UButton
							@click.stop="refreshCharacterData"
							:loading="isRefreshing"
							:disabled="isRefreshing"
							icon="i-lucide-refresh-ccw"
							variant="ghost"
							color="neutral"
							class="hidden sm:inline-flex"
							:aria-label="t('characters.card.refresh_character')"
						/>
						<UButton
							@click.stop="removeModalOpen = true"
							:label="t('characters.card.unclaim_character')"
							color="error"
							variant="ghost"
							icon="i-lucide-trash-2"
							class="mr-2 hidden sm:inline-flex"
						/>
					</div>
					<UButton
						@click.stop="open = !open"
						color="neutral"
						variant="ghost"
						trailing-icon="i-lucide-chevron-down"
						:ui="{
							trailingIcon: 'transition-transform duration-200' + (open ? ' rotate-180' : ''),
						  }"
						block
					/>
				</div>
			</div>

			<template #content>
				<div class="mt-6 space-y-6">
					<div class="flex gap-2 sm:hidden">
						<UButton
							@click.stop="refreshCharacterData"
							:loading="isRefreshing"
							:disabled="isRefreshing"
							icon="i-lucide-refresh-ccw"
							variant="soft"
							color="neutral"
							class="flex-1 justify-center"
							:label="t('characters.card.refresh_character')"
						/>
						<UButton
							@click.stop="removeModalOpen = true"
							:label="t('characters.card.unclaim_character')"
							color="error"
							variant="soft"
							icon="i-lucide-trash-2"
							class="flex-1 justify-center"
						/>
					</div>

					<div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
					<section class="space-y-3">
						<div class="flex items-center gap-2">
							<UIcon name="i-lucide-swords" size="18" class="text-muted" />
							<h3 class="text-sm font-semibold uppercase tracking-wide text-muted">
								{{ t('characters.card.sections.classes') }}
							</h3>
						</div>

						<div class="space-y-3">
							<div
								v-for="group in groupedClasses"
								:key="group.role"
								class="space-y-2 mb-4"
							>
								<div class="grid grid-cols-4 gap-2 sm:grid-cols-6 lg:grid-cols-8 xl:grid-cols-2 2xl:grid-cols-4">
									<ClassElement
										v-for="characterClass in group.classes"
										:key="characterClass.id"
										:character-id="character.id"
										:characterClass="characterClass"
									/>
								</div>
							</div>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center gap-2">
							<UIcon name="i-lucide-ghost" size="18" class="text-muted" />
							<h3 class="text-sm font-semibold uppercase tracking-wide text-muted">
								{{ t('characters.card.sections.occult') }}
							</h3>
						</div>

						<div class="flex items-center justify-between rounded-sm border border-default bg-muted/20 px-3 py-2">
							<p class="text-sm font-semibold">{{t('characters.card.knowledge_level')}}</p>
							<p class="text-sm font-semibold">{{ character.occult.knowledge_level }}</p>
						</div>

						<div class="grid grid-cols-4 gap-2 sm:grid-cols-6 lg:grid-cols-8 xl:grid-cols-3">
							<PhantomJobElement
								v-for="phantomJob in character.occult.phantom_jobs"
								:key="phantomJob.id"
								:character-id="character.id"
								:phantomJob="phantomJob"
							/>
						</div>
					</section>
					</div>

					<div class="grid gap-6 xl:grid-cols-2">
						<ForkedTowerBloodProgress :progress="character.occult.blood_progress" />
						<ForkedTowerMagicProgress :progress="character.occult.magic_progress" />
					</div>
				</div>
			</template>
		</UCollapsible>
		<UModal v-model:open="removeModalOpen" :title="t('characters.card.unclaim_title')" :description="t('characters.card.unclaim_description')">
			<template #body>
				<div class="flex flex-col gap-3">
					<p class="text-sm text-toned">
						{{ character.name }} - {{ character.world }}
					</p>
				</div>
			</template>
			<template #footer>
				<div class="flex w-full justify-end gap-2">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('characters.card.unclaim_cancel')"
						@click="removeModalOpen = false"
					/>
					<UButton
						color="error"
						variant="solid"
						:label="t('characters.card.unclaim_confirm')"
						:loading="isRemoving"
						@click="removeCharacter"
					/>
				</div>
			</template>
		</UModal>
	</UCard>
</template>

<style scoped>

</style>
