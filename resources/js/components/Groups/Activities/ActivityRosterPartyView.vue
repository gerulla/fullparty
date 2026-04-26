<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import ActivityRosterSlotCard from "@/components/Groups/Activities/ActivityRosterSlotCard.vue";
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

const slotGroups = computed(() => {
	const groups = new Map<string, {
		key: string
		label: string
		slots: ActivitySlot[]
	}>();

	for (const slot of [...props.slots].sort((left, right) => left.sort_order - right.sort_order)) {
		const existingGroup = groups.get(slot.group_key);

		if (existingGroup) {
			existingGroup.slots.push(slot);
			continue;
		}

		groups.set(slot.group_key, {
			key: slot.group_key,
			label: localizedText(slot.group_label, slot.group_key),
			slots: [slot],
		});
	}

	return Array.from(groups.values());
});
</script>

<template>
	<div v-if="slotGroups.length > 0" class="flex flex-col gap-4">
		<section
			v-for="group in slotGroups"
			:key="group.key"
			class="border border-default bg-muted shadow-sm transition-all duration-300 ease-in-out dark:bg-elevated/50"
		>
			<header class="border-b border-default px-5 py-4">
				<div class="flex items-center justify-between gap-3">
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-primary text-sm font-semibold text-inverted">
							{{ group.label.charAt(0) }}
						</div>

						<div class="flex items-center gap-3">
							<h3 class="font-semibold text-lg text-toned">
								{{ group.label }}
							</h3>

							<UBadge
								color="neutral"
								variant="outline"
								:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
							/>
						</div>
					</div>

					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-user-check"
						:label="t('groups.activities.management.roster.check_in_all')"
					/>
				</div>
			</header>

			<div class="grid grid-cols-1 gap-3 px-5 py-5 transition-all duration-300 ease-in-out md:grid-cols-2 xl:grid-cols-4">
				<ActivityRosterSlotCard
					v-for="slot in group.slots"
					:key="slot.id"
					:slot="slot"
				/>
			</div>
		</section>
	</div>
</template>
