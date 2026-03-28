<script setup>

import Placeholder from "@/components/Placeholder.vue";
</script>

<template>
	<UCard :ui="{ root: 'rounded-sm dark:bg-neutral-800/50' }">

		<UCollapsible v-model:open="open" class="flex flex-col gap-2 w-full">
			<div class="w-full flex flex-row items-stretch">
				<div id="div1" class="flex flex-row items-start">
					<img
						class="h-24 w-24 rounded-sm object-cover"
						src="https://img2.finalfantasyxiv.com/f/15cff6ad5af687333d4ae7545c7b4ec4_7206469080400ed57a5373d0a9c55c59fc0.jpg?1774692966"
						alt=""
					>
				</div>

				<div id="div2" class="ml-4 flex h-24 flex-col justify-between items-start">
					<div class="flex flex-row gap-1">
						<p class="text-lg font-semibold">Giki Chomusuke</p>
						<UBadge :ui="{base:'rounded-sm'}" icon="i-lucide-star" size="md" color="neutral" variant="soft">Primary</UBadge>
						<UBadge :ui="{base:'rounded-sm'}" icon="i-lucide-check-circle" size="md" color="success" variant="soft">Verified</UBadge>
					</div>

					<div class="flex flex-row gap-1 text-muted text-sm gap-2">
						<span>Lich</span>
						<span>&middot;</span>
						<span>Light</span>
						<span>&middot;</span>
						<span>Added Manually</span>
					</div>

					<div class="flex flex-row gap-1 text-brand">
						<UButton type="button" @submit.prevent="" class="font-thin p-0 text-sm hover:underline hover:bg-transparent cursor-pointer" variant="ghost">
							View Lodestone Profile
							<UIcon name="i-lucide-square-arrow-out-up-right" size="12" />
						</UButton>
					</div>
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