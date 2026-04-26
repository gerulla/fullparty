<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { ActivitySlot, LocalizedText } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	slots: ActivitySlot[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const rows = computed(() => [...props.slots]
	.sort((left, right) => left.sort_order - right.sort_order)
	.map((slot) => ({
		id: slot.id,
		groupLabel: localizedText(slot.group_label, slot.group_key),
		slotLabel: localizedText(slot.slot_label, slot.slot_key),
		statusLabel: slot.assigned_character_id
			? t('groups.activities.management.roster.assigned')
			: t('groups.activities.management.roster.open'),
		statusColor: slot.assigned_character_id ? 'success' : 'neutral',
		fields: slot.assigned_character_id
			? slot.field_values.map((field) => localizedText(field.field_label, field.field_key))
			: [],
	})));
</script>

<template>
	<section class="border border-default bg-muted shadow-sm dark:bg-elevated/50">
		<div class="overflow-x-auto">
			<table class="min-w-full divide-y divide-default">
				<thead>
					<tr class="text-left text-xs uppercase tracking-wide text-muted">
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.party') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.slot') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.details') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.status') }}</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-default">
					<tr
						v-for="row in rows"
						:key="row.id"
						class="transition-colors duration-200 hover:bg-background/70"
					>
						<td class="px-5 py-4 text-sm font-medium text-toned">{{ row.groupLabel }}</td>
						<td class="px-5 py-4 text-sm text-toned">{{ row.slotLabel }}</td>
						<td class="px-5 py-4">
							<div class="flex flex-wrap gap-2">
								<UBadge
									v-for="field in row.fields"
									:key="field"
									color="neutral"
									variant="outline"
									:label="field"
								/>
							</div>
						</td>
						<td class="px-5 py-4">
							<UBadge
								:color="row.statusColor"
								variant="subtle"
								:label="row.statusLabel"
							/>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>
