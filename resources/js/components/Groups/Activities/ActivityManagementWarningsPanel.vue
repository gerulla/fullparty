<script setup lang="ts">
import type { ActivityManagementWarning } from "@/Types/ActivityManagement"
import { localizedValue } from "@/utils/localizedValue"
import { computed } from "vue"
import { useI18n } from "vue-i18n"
import { usePage } from "@inertiajs/vue3"

const props = defineProps<{
	warnings: ActivityManagementWarning[]
	dismissingWarningIds?: number[]
}>()

const emit = defineEmits<{
	dismiss: [warning: ActivityManagementWarning]
}>()

const { t, locale } = useI18n()
const page = usePage()
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"))

const isDismissing = (warningId: number): boolean => (
	props.dismissingWarningIds?.includes(warningId) ?? false
)

const warningTitle = (warning: ActivityManagementWarning): string => {
	if (warning.type === "raid_leader_withdrawn") {
		return t("groups.activities.management.management_warnings.raid_leader_withdrawn.title")
	}

	return t("groups.activities.management.management_warnings.unknown.title")
}

const warningDescription = (warning: ActivityManagementWarning): string => {
	if (warning.type === "raid_leader_withdrawn") {
		const slotLabel = localizedValue(
			warning.payload.slot_label,
			locale.value,
			fallbackLocale.value,
		) || warning.payload.slot_key || t("groups.activities.management.management_warnings.unknown_slot")

		return t("groups.activities.management.management_warnings.raid_leader_withdrawn.description", {
			name: warning.payload.character_name || t("groups.activities.management.management_warnings.unknown_character"),
			slot: slotLabel,
		})
	}

	return t("groups.activities.management.management_warnings.unknown.description")
}
</script>

<template>
	<section class="border border-error/50 bg-error/5">
		<header class="flex items-center gap-3 border-b border-error/30 px-4 py-3">
			<div class="flex size-8 shrink-0 items-center justify-center bg-error text-inverted">
				<UIcon name="i-lucide-triangle-alert" class="size-4" />
			</div>

			<div class="min-w-0 flex-1">
				<div class="flex flex-wrap items-center gap-2">
					<h2 class="font-semibold text-toned">
						{{ t("groups.activities.management.management_warnings.title") }}
					</h2>
					<UBadge color="error" variant="soft" :label="String(warnings.length)" />
				</div>
				<p class="text-xs text-muted">
					{{ t("groups.activities.management.management_warnings.description") }}
				</p>
			</div>
		</header>

		<div class="divide-y divide-error/20">
			<article
				v-for="warning in warnings"
				:key="warning.id"
				class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center"
			>
				<UIcon
					:name="warning.type === 'raid_leader_withdrawn' ? 'i-lucide-crown-off' : 'i-lucide-triangle-alert'"
					class="size-5 shrink-0 text-error"
				/>

				<div class="min-w-0 flex-1">
					<p class="font-medium text-toned">
						{{ warningTitle(warning) }}
					</p>
					<p class="mt-0.5 text-sm text-muted">
						{{ warningDescription(warning) }}
					</p>
				</div>

				<UButton
					color="error"
					variant="outline"
					size="sm"
					icon="i-lucide-check"
					:label="t('groups.activities.management.management_warnings.dismiss')"
					:loading="isDismissing(warning.id)"
					:disabled="isDismissing(warning.id)"
					@click="emit('dismiss', warning)"
				/>
			</article>
		</div>
	</section>
</template>
