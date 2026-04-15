<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";

const props = defineProps<{
	form: {
		slug: string
		draft_name: Record<string, string>
		draft_layout_schema: { groups?: Array<{ size?: number }> }
		draft_slot_schema: Array<unknown>
		draft_application_schema: Array<unknown>
	}
}>();

const { t, locale } = useI18n();

const totalSlots = computed(() => (props.form.draft_layout_schema?.groups ?? []).reduce((total, group) => total + Number(group.size || 0), 0));
</script>

<template>
	<UCard class=" dark:bg-elevated/25">
		<template #header>
			<div>
				<h2 class="text-lg font-semibold">{{ t('admin.activity_types.summary.title') }}</h2>
				<p class="text-sm text-muted">{{ t('admin.activity_types.summary.subtitle') }}</p>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div class="rounded-lg bg-neutral-100 p-4 dark:bg-neutral-800">
				<p class="text-sm text-muted">{{ t('admin.activity_types.summary.draft_name') }}</p>
				<p class="mt-1 font-semibold text-highlighted">
					{{ localizedValue(form.draft_name, locale) || t('admin.activity_types.summary.untitled') }}
				</p>
				<p class="mt-2 text-sm text-muted">
					{{ form.slug || t('admin.activity_types.summary.no_slug') }}
				</p>
			</div>

			<div class="grid grid-cols-2 gap-3">
				<div class="rounded-lg border border-default p-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('admin.activity_types.summary.groups') }}</p>
					<p class="mt-2 text-2xl font-semibold">{{ form.draft_layout_schema?.groups?.length ?? 0 }}</p>
				</div>

				<div class="rounded-lg border border-default p-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('admin.activity_types.summary.slots') }}</p>
					<p class="mt-2 text-2xl font-semibold">{{ totalSlots }}</p>
				</div>

				<div class="rounded-lg border border-default p-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('admin.activity_types.summary.slot_fields') }}</p>
					<p class="mt-2 text-2xl font-semibold">{{ form.draft_slot_schema?.length ?? 0 }}</p>
				</div>

				<div class="rounded-lg border border-default p-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('admin.activity_types.summary.application_questions') }}</p>
					<p class="mt-2 text-2xl font-semibold">{{ form.draft_application_schema?.length ?? 0 }}</p>
				</div>
			</div>
		</div>
	</UCard>
</template>
