<script setup>
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { characterClassTranslationKey, translateJobName } from "@/utils/characterJobTranslations";

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
const lastTapAt = ref(0);
const { t } = useI18n();

const translatedClassName = computed(() => translateJobName(
	t,
	characterClassTranslationKey(props.characterClass),
	props.characterClass?.name,
));

const togglePreferred = () => {
	if (!props.characterClass || isUpdating.value) {
		return;
	}

	isUpdating.value = true;

	router.post(route('characters.preferred-class', props.characterId), {
		character_class_id: props.characterClass.id,
		is_preferred: !props.characterClass.is_preferred,
	}, {
		onFinish: () => {
			isUpdating.value = false;
		}
	});
};

const handleCompactTap = () => {
	const now = Date.now();

	if (now - lastTapAt.value <= 320) {
		lastTapAt.value = 0;
		togglePreferred();

		return;
	}

	lastTapAt.value = now;
};
</script>

<template>
	<div
		v-if="characterClass"
		class="relative isolate flex flex-col items-center justify-center gap-1 overflow-hidden rounded-sm border bg-neutral-900 px-2 py-2 text-center [backface-visibility:hidden] [contain:paint] xl:hidden"
		:class="[characterClass.level === 0 ? 'opacity-50' : 'opacity-100', characterClass.is_preferred ? 'border-rose-400/50 ' : 'border-default']"
		@click="handleCompactTap"
	>
		<div class="relative">
			<img
				v-if="characterClass.icon_url"
				:src="characterClass.icon_url"
				:alt="`${translatedClassName} icon`"
				class="size-10 rounded-sm object-contain"
			>
			<div
				v-else
				class="flex size-10 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
			>
				{{ characterClass.shorthand }}
			</div>
			<UIcon
				:name="characterClass.is_preferred ? 'mdi:heart' : 'i-lucide-heart'"
				class="absolute -right-1 -top-1 size-4"
				:class="characterClass.is_preferred ? 'text-rose-500' : 'text-white/55'"
			/>
		</div>
		<p class="text-xs font-semibold leading-none text-white/86">
			{{ characterClass.level }}
		</p>
	</div>

	<div v-if="characterClass"
		 class="group relative hidden items-center gap-2 rounded-sm border bg-neutral-800/50 px-3 py-2 pr-11 xl:flex"
		:class="[characterClass.level === 0 ? 'opacity-50' : 'opacity-100', characterClass.is_preferred ? 'border-rose-400/50 ' : 'border-default']"
	>
		<img
			v-if="characterClass.icon_url"
			:src="characterClass.icon_url"
			:alt="`${translatedClassName} icon`"
			class="h-8 w-8 rounded-sm object-contain"
		>
		<div
			v-else
			class="flex h-8 w-8 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
		>
			{{ characterClass.shorthand }}
		</div>

		<div class="min-w-0 flex-1">
			<div class="flex min-w-0 items-center gap-1.5">
				<p class="max-w-full whitespace-normal break-words text-sm font-semibold leading-tight">{{ translatedClassName }}</p>
			</div>
			<p class="text-xs text-muted">
				{{ characterClass.shorthand }} · {{ characterClass.level }}
			</p>
		</div>

		<UButton
			@click.stop="togglePreferred"
			:loading="isUpdating"
			:disabled="isUpdating"
			:icon="characterClass.is_preferred ? 'mdi:heart' : 'i-lucide-heart'"
			size="sm"
			variant="ghost"
			:color="characterClass.is_preferred ? 'error' : 'neutral'"
			class="absolute right-2 top-1/2 -translate-y-1/2 transition-opacity duration-200"
			:ui="{ base: characterClass.is_preferred ? 'text-rose-500' : 'text-white/55 hover:text-rose-400' }"
		/>
	</div>
</template>

<style scoped>

</style>
