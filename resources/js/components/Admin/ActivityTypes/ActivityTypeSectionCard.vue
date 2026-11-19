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
	<UCard class="min-w-0">
		<div class="flex flex-col gap-4">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div class="min-w-0">
					<div class="flex flex-wrap items-center gap-2">
						<h2 class="text-lg font-semibold">{{ title }}</h2>
						<slot name="headerMeta" />
					</div>
					<p v-if="description" class="text-sm text-muted">{{ description }}</p>
				</div>

				<div class="flex flex-wrap items-center gap-2">
					<slot name="headerActions" />
					<UButton
						type="button"
						color="neutral"
						variant="ghost"
						:aria-label="title"
						:aria-expanded="open"
						:icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
						@click.stop="open = !open"
					/>
				</div>
			</div>

			<div v-show="open" class="flex min-w-0 flex-col gap-4 border-t border-default pt-4" @invalid.capture="open = true">
				<slot />
			</div>
		</div>
	</UCard>
</template>
