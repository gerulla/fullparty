<script setup lang="ts">
import type { TimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useTimeDisplayPreference } from '@/composables/useTimeDisplayPreference';

const { t } = useI18n();
defineProps<{ compact?: boolean }>();
const { timeDisplayMode, isSaving, updateMode } = useTimeDisplayPreference();

const options: Array<{ label: string, value: TimeDisplayMode, titleKey: string }> = [
	{ label: "LT", value: "local", titleKey: "navigation.topbar.time_display.local" },
	{ label: "ST", value: "server", titleKey: "navigation.topbar.time_display.server" },
];

const activeLabel = computed(() => (
	timeDisplayMode.value === "server"
		? t("navigation.topbar.time_display.server")
		: t("navigation.topbar.time_display.local")
));
const nextMode = computed<TimeDisplayMode>(() => timeDisplayMode.value === 'server' ? 'local' : 'server');
const switchLabel = computed(() => t(`navigation.topbar.time_display.switch_${nextMode.value}`));

</script>

<template>
	<UTooltip :text="compact ? switchLabel : activeLabel">
        <UButton v-if="compact" type="button" color="neutral" variant="ghost"
            class="min-w-9 justify-center rounded-md font-semibold hover:bg-elevated"
            :disabled="isSaving" :aria-label="switchLabel" :aria-busy="isSaving" @click="updateMode(nextMode)">
            {{ timeDisplayMode === 'server' ? 'ST' : 'LT' }}
        </UButton>
		<div
            v-else
			class="inline-grid h-8 grid-cols-2 overflow-hidden border-default bg-default text-xs font-semibold"
			:aria-label="t('navigation.topbar.time_display.label')"
			role="group"
		>
			<button
				v-for="option in options"
				:key="option.value"
				type="button"
				class="flex min-w-8 items-center justify-center px-2 transition-colors disabled:cursor-wait disabled:opacity-70"
				:class="timeDisplayMode === option.value
					? 'bg-primary text-white'
					: 'text-muted hover:bg-muted hover:text-toned'"
				:aria-label="t(option.titleKey)"
				:aria-pressed="timeDisplayMode === option.value"
				:disabled="isSaving"
				@click="updateMode(option.value)"
			>
				{{ option.label }}
			</button>
		</div>
	</UTooltip>
</template>
