<script setup>
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { phantomJobTranslationKey, translateJobName } from "@/utils/characterJobTranslations";

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
const lastTapAt = ref(0);
const { t } = useI18n();

const translatedPhantomJobName = computed(() => translateJobName(
	t,
	phantomJobTranslationKey(props.phantomJob),
	props.phantomJob?.name,
));

const togglePreferred = () => {
	if (!props.phantomJob || isUpdating.value) {
		return;
	}

	isUpdating.value = true;

	router.post(route('characters.preferred-phantom-job', props.characterId), {
		phantom_job_id: props.phantomJob.id,
		is_preferred: !props.phantomJob.is_preferred,
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
		v-if="phantomJob"
		class="relative isolate flex flex-col items-center justify-center gap-1 overflow-hidden rounded-sm border bg-neutral-900 px-2 py-2 text-center [backface-visibility:hidden] [contain:paint] xl:hidden"
		:class="[phantomJob.current_level === 0 ? 'opacity-50' : 'opacity-100', phantomJob.is_preferred ? 'border-rose-400/50 ' : 'border-default']"
		@click="handleCompactTap"
	>
		<div class="relative">
			<img
				v-if="phantomJob.icon_url"
				:src="phantomJob.is_maxed ? phantomJob.icon_url : phantomJob.black_icon_url"
				:alt="`${translatedPhantomJobName} icon`"
				class="size-10 rounded-sm object-contain"
			>
			<div
				v-else
				class="flex size-10 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
			>
				PJ
			</div>
			<UIcon
				:name="phantomJob.is_preferred ? 'mdi:heart' : 'i-lucide-heart'"
				class="absolute -right-1 -top-1 size-4"
				:class="phantomJob.is_preferred ? 'text-rose-500' : 'text-white/55'"
			/>
		</div>
		<p class="text-xs font-semibold leading-none text-white/86">
			{{ phantomJob.current_level }} / {{ phantomJob.max_level }}
		</p>
	</div>

	<div v-if="phantomJob"
		 class="group relative hidden items-center gap-2 rounded-sm border bg-neutral-800/50 px-3 py-2 pr-11 xl:flex"
		 :class="[phantomJob.current_level === 0 ? 'opacity-50' : 'opacity-100', phantomJob.is_preferred ? 'border-rose-400/50 ' : 'border-default']"
	>
		<img
			v-if="phantomJob.icon_url"
			:src="phantomJob.is_maxed ? phantomJob.icon_url : phantomJob.black_icon_url"
			:alt="`${translatedPhantomJobName} icon`"
			class="h-8 w-8 rounded-sm object-contain"
		>
		<div
			v-else
			class="flex h-8 w-8 items-center justify-center rounded-sm bg-elevated text-[10px] font-semibold text-muted"
		>
			PJ
		</div>

		<div class="min-w-0 flex-1">
			<div class="flex min-w-0 flex-wrap items-center gap-1.5">
				<p class="max-w-full whitespace-normal break-words text-sm font-semibold leading-tight">{{ translatedPhantomJobName }}</p>
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
			:icon="phantomJob.is_preferred ? 'mdi:heart' : 'i-lucide-heart'"
			size="sm"
			variant="ghost"
			:color="phantomJob.is_preferred ? 'error' : 'neutral'"
			class="absolute right-2 top-1/2 -translate-y-1/2 transition-opacity duration-200"
			:ui="{ base: phantomJob.is_preferred ? 'text-rose-500' : 'text-white/55 hover:text-rose-400' }"
		/>
	</div>
</template>

<style scoped>

</style>
