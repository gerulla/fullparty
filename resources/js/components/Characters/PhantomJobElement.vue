<script setup>
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { route } from "ziggy-js";

const props = defineProps({
	characterId: {
		type: Number,
		required: true
	},
	phantomJob: {
		type: Object,
		default: null
	}
});

const isUpdating = ref(false);

const togglePreferred = () => {
	if (!props.phantomJob || isUpdating.value) {
		return;
	}

	isUpdating.value = true;

	router.post(route('characters.preferred-phantom-job', props.characterId), {
		phantom_job_id: props.phantomJob.id,
		is_preferred: !props.phantomJob.is_preferred,
	}, {
		preserveScroll: true,
		onFinish: () => {
			isUpdating.value = false;
		}
	});
};
</script>

<template>
	<div v-if="phantomJob"
		 class="group relative flex items-center gap-2 rounded-sm border border-default bg-muted/20 px-3 py-2 pr-11"
		 :class="phantomJob.current_level === 0 ? 'opacity-50' : 'opacity-100'"
	>
		<img
			v-if="phantomJob.icon_url"
			:src="phantomJob.is_maxed ? phantomJob.icon_url : phantomJob.black_icon_url"
			:alt="`${phantomJob.name} icon`"
			class="h-8 w-8 rounded-sm object-contain"
		>
		<div
			v-else
			class="flex h-8 w-8 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
		>
			PJ
		</div>

		<div class="min-w-0 flex-1">
			<div class="flex flex-wrap items-center gap-1.5">
				<p class="truncate text-sm font-semibold">{{ phantomJob.name }}</p>
			</div>

			<div class="flex flex-wrap items-center gap-1.5">
				<p class="text-xs text-muted">
					{{ phantomJob.current_level }} / {{ phantomJob.max_level }}
				</p>
				<UBadge
					v-if="phantomJob.is_maxed"
					icon="i-lucide-check"
					color="success"
					variant="subtle"
					size="sm"
				/>
			</div>
		</div>

		<UButton
			@click.stop="togglePreferred"
			:loading="isUpdating"
			:disabled="isUpdating"
			icon="i-lucide-heart"
			size="sm"
			variant="ghost"
			:color="phantomJob.is_preferred ? 'error' : 'neutral'"
			class="absolute right-2 top-1/2 -translate-y-1/2 transition-opacity duration-200"
			:class="phantomJob.is_preferred ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
			:ui="{ base: phantomJob.is_preferred ? 'text-rose-500' : '' }"
		/>
	</div>
</template>

<style scoped>

</style>
