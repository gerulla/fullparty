<script setup lang="ts">
import { computed } from "vue"
import { useI18n } from "vue-i18n"

const props = defineProps<{
	title: string
	groupName: string
	startsAt: string | null
	durationHours: number | null
	status: string | null
	overviewUrl: string
	icsUrl: string
	enabled: boolean
	notes?: string | null
	progressPoint?: string | null
	size?: "xs" | "sm" | "md" | "lg" | "xl"
}>()

const { t } = useI18n()
const archivedStatuses = new Set(["complete", "cancelled"])

const startDate = computed(() => props.startsAt ? new Date(props.startsAt) : null)
const endDate = computed(() => {
	if (!startDate.value) {
		return null
	}

	return new Date(startDate.value.getTime() + (props.durationHours || 1) * 60 * 60 * 1000)
})
const isAvailable = computed(() => Boolean(
	props.enabled
	&& startDate.value
	&& endDate.value
	&& !Number.isNaN(startDate.value.getTime())
	&& endDate.value.getTime() > Date.now()
	&& !archivedStatuses.has(props.status || ""),
))

const details = computed(() => [
	`${t("calendar.group")}: ${props.groupName}`,
	props.progressPoint ? `${t("calendar.progress_point")}: ${props.progressPoint}` : null,
	props.notes?.trim() || null,
	`${t("calendar.view_run")}: ${props.overviewUrl}`,
].filter(Boolean).join("\n"))

const compactUtc = (date: Date) => date.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "")

const googleUrl = computed(() => {
	if (!startDate.value || !endDate.value) {
		return ""
	}

	const params = new URLSearchParams({
		action: "TEMPLATE",
		text: props.title,
		dates: `${compactUtc(startDate.value)}/${compactUtc(endDate.value)}`,
		details: details.value,
	})

	return `https://calendar.google.com/calendar/render?${params.toString()}`
})

const outlookUrl = computed(() => {
	if (!startDate.value || !endDate.value) {
		return ""
	}

	const params = new URLSearchParams({
		path: "/calendar/action/compose",
		rru: "addevent",
		subject: props.title,
		startdt: startDate.value.toISOString(),
		enddt: endDate.value.toISOString(),
		body: details.value,
	})

	return `https://outlook.live.com/calendar/0/deeplink/compose?${params.toString()}`
})

const handleTriggerClick = (event: MouseEvent) => {
	// Plain clicks open the dropdown; modified clicks retain native link behavior.
	if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
		event.preventDefault()
	}
}

const items = computed(() => [[
	{
		label: t("calendar.google"),
		icon: "logos:google-calendar",
		to: googleUrl.value,
		external: true,
		target: "_blank",
	},
	{
		label: t("calendar.outlook"),
		icon: "mdi:microsoft-outlook",
		to: outlookUrl.value,
		external: true,
		target: "_blank",
	},
	{
		label: t("calendar.apple_other"),
		icon: "mdi:apple",
		to: props.icsUrl,
		external: true,
	},
]])
</script>

<template>
	<UDropdownMenu v-if="isAvailable" :items="items">
		<UButton
			:to="icsUrl"
			external
			color="neutral"
			variant="outline"
			icon="i-lucide-calendar-plus"
			:label="t('calendar.add')"
			:size="size"
			@click="handleTriggerClick"
		/>
	</UDropdownMenu>
</template>
