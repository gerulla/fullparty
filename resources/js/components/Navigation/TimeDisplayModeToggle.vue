<script setup lang="ts">
import type { TimeDisplayMode } from "@/composables/useTimeDisplayMode";
import axios from "axios";
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

type SharedTimeDisplayPageProps = {
	auth?: {
		user?: {
			time_display_mode?: TimeDisplayMode | null
		} | null
	}
};

const { t } = useI18n();
const toast = useToast();
const page = usePage<SharedTimeDisplayPageProps>();
const { timeDisplayMode, setTimeDisplayMode } = useTimeDisplayMode();
const isSaving = ref(false);

const options: Array<{ label: string, value: TimeDisplayMode, titleKey: string }> = [
	{ label: "LT", value: "local", titleKey: "navigation.topbar.time_display.local" },
	{ label: "ST", value: "server", titleKey: "navigation.topbar.time_display.server" },
];

const activeLabel = computed(() => (
	timeDisplayMode.value === "server"
		? t("navigation.topbar.time_display.server")
		: t("navigation.topbar.time_display.local")
));

function syncSharedUserMode(mode: TimeDisplayMode) {
	if (page.props.auth?.user) {
		page.props.auth.user.time_display_mode = mode;
	}
}

async function updateMode(mode: TimeDisplayMode) {
	if (mode === timeDisplayMode.value || isSaving.value) {
		return;
	}

	const previousMode = timeDisplayMode.value;
	setTimeDisplayMode(mode);
	syncSharedUserMode(mode);
	isSaving.value = true;

	try {
		const response = await axios.patch<{ time_display_mode: TimeDisplayMode }>(
			route("settings.time-display"),
			{ time_display_mode: mode },
		);
		const savedMode = response.data.time_display_mode;
		setTimeDisplayMode(savedMode);
		syncSharedUserMode(savedMode);
	} catch {
		setTimeDisplayMode(previousMode);
		syncSharedUserMode(previousMode);
		toast.add({
			color: "error",
			title: t("navigation.topbar.time_display.error_title"),
			description: t("navigation.topbar.time_display.error_description"),
		});
	} finally {
		isSaving.value = false;
	}
}
</script>

<template>
	<UTooltip :text="activeLabel">
		<div
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
