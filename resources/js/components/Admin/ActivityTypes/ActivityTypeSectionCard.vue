<script setup lang="ts">
import { ref } from "vue";

const props = withDefaults(defineProps<{
	title: string
	description?: string
	defaultOpen?: boolean
}>(), {
	description: undefined,
	defaultOpen: true,
});

const open = ref(props.defaultOpen);
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
				<div class="min-w-0">
					<div class="flex items-center gap-2">
						<h2 class="text-lg font-semibold">{{ title }}</h2>
						<slot name="headerMeta" />
					</div>
					<p v-if="description" class="text-sm text-muted">{{ description }}</p>
				</div>

				<div class="flex items-center gap-2">
					<slot name="headerActions" />
					<UButton
						type="button"
						color="neutral"
						variant="ghost"
						:icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
						@click.stop="open = !open"
					/>
				</div>
			</div>

			<div v-if="open" class="flex flex-col gap-4">
				<slot />
			</div>
		</div>
	</UCard>
</template>
