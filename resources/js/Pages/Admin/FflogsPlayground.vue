<script setup lang="ts">
import JsonTreeViewer from "@/components/Admin/JsonTreeViewer.vue";
import PageHeader from "@/components/PageHeader.vue";
import axios from "axios";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

type PlaygroundResult = {
	request: {
		endpoint: string
		payload: unknown
	}
	response: {
		ok: boolean
		status: number | null
		body: unknown
	}
}

const { t } = useI18n();

type PlaygroundPayload = {
	query: string
	variables?: Record<string, unknown>
	operationName?: string | null
}

const characterZoneRankingsQuery = `query CharacterZoneRankings(
  $name: String!,
  $serverSlug: String!,
  $serverRegion: String!,
  $zoneId: Int!
) {
  characterData {
    character(name: $name, serverSlug: $serverSlug, serverRegion: $serverRegion) {
      zoneRankings(zoneID: $zoneId)
    }
  }
}`;

const activityReportProgressQuery = `query ActivityReportProgress($code: String!) {
  reportData {
    report(code: $code) {
      title
      fights(translate: true) {
        id
        encounterID
        name
        kill
        lastPhase
        bossPercentage
        fightPercentage
        startTime
        endTime
      }
    }
  }
}`;

const reportStructureQuery = `query ReportStructure($code: String!) {
  reportData {
    report(code: $code) {
      title
      fights(translate: true) {
        id
        encounterID
        originalEncounterID
        name
        kill
        lastPhase
        lastPhaseAsAbsoluteIndex
        lastPhaseIsIntermission
        startTime
        endTime
      }
    }
  }
}`;

const presetPayloads = {
	activity_report_progress: {
		query: activityReportProgressQuery,
		variables: {
			code: "REPORT_CODE",
		},
	},
	character_zone_rankings: {
		query: characterZoneRankingsQuery,
		variables: {
			name: "Character Name",
			serverSlug: "Twintania",
			serverRegion: "EU",
			zoneId: 77,
		},
	},
	report_structure: {
		query: reportStructureQuery,
		variables: {
			code: "REPORT_CODE",
		},
	},
} satisfies Record<string, PlaygroundPayload>;

type PresetKey = keyof typeof presetPayloads;

type PlaygroundPresetOption = {
	label: string
	value: PresetKey
}

const presetKeys = Object.keys(presetPayloads) as PresetKey[];
const defaultPreset: PresetKey = "activity_report_progress";
const formatPayload = (payload: PlaygroundPayload) => JSON.stringify(payload, null, 2);
const selectedPreset = ref<PresetKey>(defaultPreset);
const requestInput = ref(formatPayload(presetPayloads[defaultPreset]));

const responsePayload = ref<PlaygroundResult | null>(null);
const errorMessage = ref<string | null>(null);
const isSending = ref(false);

const presetOptions = computed<PlaygroundPresetOption[]>(() => presetKeys.map((key) => ({
	label: t(`admin.fflogs_playground.presets.${key}.label`),
	value: key,
})));

const selectedPresetDescription = computed(() => t(`admin.fflogs_playground.presets.${selectedPreset.value}.description`));

const applyPreset = (value: string) => {
	if (!presetKeys.includes(value as PresetKey)) {
		return;
	}

	const presetKey = value as PresetKey;
	selectedPreset.value = presetKey;
	requestInput.value = formatPayload(presetPayloads[presetKey]);
	responsePayload.value = null;
	errorMessage.value = null;
};

const isRecord = (value: unknown): value is Record<string, unknown> => (
	typeof value === "object"
	&& value !== null
	&& !Array.isArray(value)
);

const isPlaygroundResult = (value: unknown): value is PlaygroundResult => {
	if (!isRecord(value) || !isRecord(value.response)) {
		return false;
	}

	return "ok" in value.response && "status" in value.response && "body" in value.response;
};

const translateValidationCode = (code: string) => {
	const key = `admin.fflogs_playground.validation.${code}`;
	const translated = t(key);

	return translated === key ? code : translated;
};

const resolveErrorMessage = (data: unknown) => {
	if (!isRecord(data)) {
		return t("admin.fflogs_playground.errors.request_failed");
	}

	const errors = data.errors;

	if (isRecord(errors)) {
		const requestErrors = errors.request;

		if (Array.isArray(requestErrors) && typeof requestErrors[0] === "string") {
			return translateValidationCode(requestErrors[0]);
		}
	}

	return typeof data.message === "string"
		? data.message
		: t("admin.fflogs_playground.errors.request_failed");
};

const responseStatus = computed(() => {
	if (!responsePayload.value) {
		return t("admin.fflogs_playground.response.empty_status");
	}

	const status = responsePayload.value.response.status;

	return status === null
		? t("admin.fflogs_playground.response.failed_status")
		: t("admin.fflogs_playground.response.http_status", { status });
});

const responseStatusColor = computed(() => {
	if (!responsePayload.value) {
		return "neutral";
	}

	return responsePayload.value.response.ok ? "success" : "error";
});

const executeRequest = async () => {
	errorMessage.value = null;
	isSending.value = true;

	try {
		const response = await axios.post<PlaygroundResult>(route("admin.fflogs-playground.execute"), {
			request: requestInput.value,
		});

		responsePayload.value = response.data;
	} catch (error) {
		const data = (error as { response?: { data?: unknown } }).response?.data;

		if (isPlaygroundResult(data)) {
			responsePayload.value = data;

			return;
		}

		errorMessage.value = resolveErrorMessage(data);
	} finally {
		isSending.value = false;
	}
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.fflogs_playground.title')"
			:subtitle="t('admin.fflogs_playground.subtitle')"
		>
			<UBadge
				color="warning"
				variant="subtle"
				icon="i-lucide-shield"
				:label="t('admin.fflogs_playground.admin_only')"
			/>
		</PageHeader>

		<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
						<div class="space-y-1">
							<h2 class="text-base font-semibold text-highlighted">
								{{ t("admin.fflogs_playground.request.title") }}
							</h2>
							<p class="text-sm text-muted">
								{{ t("admin.fflogs_playground.request.description") }}
							</p>
						</div>
						<UButton
							color="primary"
							icon="i-lucide-send"
							:loading="isSending"
							:label="t('admin.fflogs_playground.request.send')"
							@click="executeRequest"
						/>
					</div>
				</template>

				<div class="space-y-4">
					<UAlert
						v-if="errorMessage"
						color="error"
						variant="soft"
						icon="i-lucide-triangle-alert"
						:description="errorMessage"
					/>

					<UFormField :label="t('admin.fflogs_playground.request.preset_label')">
						<USelect
							v-model="selectedPreset"
							class="w-full"
							:items="presetOptions"
							@update:model-value="applyPreset"
						/>
						<p class="mt-2 text-xs text-muted">
							{{ selectedPresetDescription }}
						</p>
					</UFormField>

					<UFormField :label="t('admin.fflogs_playground.request.field_label')">
						<UTextarea
							v-model="requestInput"
							class="w-full font-mono text-sm"
							:rows="24"
							:placeholder="t('admin.fflogs_playground.request.placeholder')"
						/>
					</UFormField>
				</div>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
						<div class="space-y-1">
							<h2 class="text-base font-semibold text-highlighted">
								{{ t("admin.fflogs_playground.response.title") }}
							</h2>
							<p class="text-sm text-muted">
								{{ t("admin.fflogs_playground.response.description") }}
							</p>
						</div>
						<UBadge
							variant="subtle"
							:color="responseStatusColor"
							:label="responseStatus"
						/>
					</div>
				</template>

				<div
					v-if="!responsePayload"
					class="flex min-h-[36rem] items-center justify-center border border-dashed border-default bg-muted/20 p-8 text-center text-sm text-muted"
				>
					{{ t("admin.fflogs_playground.response.empty") }}
				</div>
				<JsonTreeViewer
					v-else
					:value="responsePayload"
				/>
			</UCard>
		</div>
	</div>
</template>
