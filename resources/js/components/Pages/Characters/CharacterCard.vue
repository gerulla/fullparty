<script setup>

import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {useI18n} from "vue-i18n";
import { route } from 'ziggy-js';
import ClassElement from "@/components/Characters/ClassElement.vue";
import PhantomJobElement from "@/components/Characters/PhantomJobElement.vue";
import ForkedTowerBloodProgress from "@/components/Characters/ForkedTowerBloodProgress.vue";
import ForkedTowerMagicProgress from "@/components/Characters/ForkedTowerMagicProgress.vue";

const { t } = useI18n()
const open = ref(false)
const isRefreshing = ref(false)
const props = defineProps({
	character: {
		type: Object,
		required: true,
	},
})

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
		preserveScroll: true,
		onFinish: () => {
			isRefreshing.value = false;
		},
	});
};
</script>

<template>
	<UCard :ui="{ root: 'rounded-sm dark:bg-neutral-800/50' }">

		<UCollapsible v-model:open="open" class="flex flex-col gap-2 w-full">
			<div class="w-full flex flex-row items-stretch">
				<div id="div1" class="flex flex-row items-start">
					<img
						class="h-24 w-24 rounded-sm object-cover"
						:src="character.avatar_url"
						:alt="character.name+' avatar'"
					>
				</div>

				<div id="div2" class="ml-4 flex h-24 flex-col justify-between items-start">
					<div class="flex flex-row gap-1">
						<p class="text-lg font-semibold">{{character.name}}</p>
						<UBadge v-if="character.is_primary" :ui="{base:'rounded-sm'}" icon="i-lucide-star" size="md" color="neutral" variant="soft">{{ t('general.primary') }}</UBadge>
						<UBadge v-if="character.verified_at!==null" :ui="{base:'rounded-sm'}" icon="i-lucide-check-circle" size="md" color="success" variant="soft">{{ t('general.verified') }}</UBadge>
						<UBadge v-else :ui="{base:'rounded-sm'}" icon="i-lucide-x-circle" size="md" color="error" variant="soft">{{ t('general.unverified') }}</UBadge>
					</div>

					<div class="flex flex-row gap-1 text-muted text-sm gap-2">
						<span>{{character.world}}</span>
						<span>&middot;</span>
						<span>{{character.datacenter}}</span>
						<span>&middot;</span>
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
						<UButton v-if="!character.is_primary && character.verified_at !== null" :label="t('characters.card.make_primary')" color="neutral" variant="soft" icon="i-lucide-star" class="mr-2" />
						<UButton
							@click.stop="refreshCharacterData"
							:loading="isRefreshing"
							:disabled="isRefreshing"
							icon="i-lucide-refresh-ccw"
							variant="ghost"
							color="neutral"
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
								<div class="grid gap-2 sm:grid-cols-2 2xl:grid-cols-4">
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

						<div class="grid gap-2 sm:grid-cols-3">
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
						<ForkedTowerMagicProgress />
					</div>
				</div>
			</template>
		</UCollapsible>
	</UCard>
</template>

<style scoped>

</style>
