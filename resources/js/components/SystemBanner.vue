<script setup lang="ts">
defineProps<{
	banner: {
		id: number
		title: string
		message: string
		action_label: string | null
		action_url: string | null
		updated_at: string | null
	}
}>()

const isExternalUrl = (url: string | null) => {
	if (!url) {
		return false
	}

	return /^(https?:)?\/\//.test(url)
}
</script>

<template>
	<section
		class="border-b border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/35 dark:text-amber-100"
	>
		<div class="flex flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8 xl:flex-row xl:items-center xl:justify-between">
			<div class="min-w-0 space-y-1.5">
				<div class="flex items-center gap-2">
					<UIcon name="i-lucide-badge-alert" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" />
					<h2 class="text-sm font-semibold sm:text-base">
						{{ banner.title }}
					</h2>
				</div>

				<p class="max-w-5xl whitespace-pre-line text-sm leading-6 text-amber-900/85 dark:text-amber-100/85">
					{{ banner.message }}
				</p>
			</div>

			<div v-if="banner.action_label && banner.action_url" class="shrink-0">
				<UButton
					as="a"
					:href="banner.action_url"
					:target="isExternalUrl(banner.action_url) ? '_blank' : undefined"
					:rel="isExternalUrl(banner.action_url) ? 'noopener noreferrer' : undefined"
					color="warning"
					variant="solid"
					icon="i-lucide-arrow-right"
					:label="banner.action_label"
				/>
			</div>
		</div>
	</section>
</template>
