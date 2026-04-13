<script setup>
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { route } from "ziggy-js";

const props = defineProps({
	characterId: {
		type: Number,
		required: true
	},
	characterClass: {
		type: Object,
		default: null
	}
});

const isUpdating = ref(false);

const togglePreferred = () => {
	if (!props.characterClass || isUpdating.value) {
		return;
	}

	isUpdating.value = true;

	router.post(route('characters.preferred-class', props.characterId), {
		character_class_id: props.characterClass.id,
		is_preferred: !props.characterClass.is_preferred,
	}, {
		preserveScroll: true,
		onFinish: () => {
			isUpdating.value = false;
		}
	});
};
</script>

<template>
	<div v-if="characterClass"
		 class="group relative flex items-center gap-2 rounded-sm border bg-muted/20 px-3 py-2 pr-11"
		:class="[characterClass.level === 0 ? 'opacity-50' : 'opacity-100', characterClass.is_preferred ? 'border-rose-400/50 ' : 'border-default']"
	>
		<img
			v-if="characterClass.icon_url"
			:src="characterClass.icon_url"
			:alt="`${characterClass.name} icon`"
			class="h-8 w-8 rounded-sm object-contain"
		>
		<div
			v-else
			class="flex h-8 w-8 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
		>
			{{ characterClass.shorthand }}
		</div>

		<div class="min-w-0 flex-1">
			<div class="flex items-center gap-1.5">
				<p class="truncate text-sm font-semibold">{{ characterClass.name }}</p>
			</div>
			<p class="text-xs text-muted">
				{{ characterClass.shorthand }} · {{ characterClass.level }}
			</p>
		</div>

		<UButton
			@click.stop="togglePreferred"
			:loading="isUpdating"
			:disabled="isUpdating"
			icon="i-lucide-heart"
			size="sm"
			variant="ghost"
			:color="characterClass.is_preferred ? 'error' : 'neutral'"
			class="absolute right-2 top-1/2 -translate-y-1/2 transition-opacity duration-200"
			:class="characterClass.is_preferred ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
			:ui="{ base: characterClass.is_preferred ? 'text-rose-500' : '' }"
		/>
	</div>
</template>

<style scoped>

</style>
