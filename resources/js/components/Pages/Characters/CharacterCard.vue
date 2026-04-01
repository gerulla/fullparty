<script setup>

import Placeholder from "@/components/Placeholder.vue";
import { ref } from 'vue';
import {useI18n} from "vue-i18n";

const { t } = useI18n()
const open = ref(false)
defineProps({
	character: {
		type: Object,
		required: true,
	},
})
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
						<UBadge v-if="character.is_primary" :ui="{base:'rounded-sm'}" icon="i-lucide-star" size="md" color="neutral" variant="soft">Primary</UBadge>
						<UBadge v-if="character.verified_at!==null" :ui="{base:'rounded-sm'}" icon="i-lucide-check-circle" size="md" color="success" variant="soft">Verified</UBadge>
						<UBadge v-else :ui="{base:'rounded-sm'}" icon="i-lucide-x-circle" size="md" color="error" variant="soft">Unverified</UBadge>
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
							View Lodestone Profile
							<UIcon name="i-lucide-square-arrow-out-up-right" size="12" />
						</a>
					</div>
				</div>

				<div id="div3" class="ml-auto flex flex-col items-center justify-between">
					<div class="flex flex-row items-center">
						<UButton v-if="!character.is_primary && character.verified_at !== null" label="Make Primary" color="neutral" variant="soft" icon="i-lucide-star" class="mr-2" />
						<UButton icon="i-lucide-refresh-ccw" variant="ghost" color="neutral" />
					</div>
					<UButton
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
				<Placeholder class="h-48 mt-6" />
			</template>
		</UCollapsible>
	</UCard>
</template>

<style scoped>

</style>