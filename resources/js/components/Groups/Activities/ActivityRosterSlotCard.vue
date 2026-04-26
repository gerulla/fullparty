<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { ActivitySlot, LocalizedText } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	slot: ActivitySlot
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const slotLabel = computed(() => localizedText(props.slot.slot_label, props.slot.slot_key));
const assignedCharacter = computed(() => props.slot.assigned_character);
const classField = computed(() => props.slot.field_values.find((field) => field.source === 'character_classes') ?? null);
const phantomField = computed(() => props.slot.field_values.find((field) => field.source === 'phantom_jobs') ?? null);
const roleField = computed(() => classField.value?.display_meta?.role ?? null);
const fieldEntries = computed(() => props.slot.field_values.map((field) => ({
	id: field.id,
	label: localizedText(field.field_label, field.field_key),
	value: typeof field.display_value === 'string'
		? field.display_value
		: localizedText(field.display_value, ''),
	source: field.source,
})));
const visibleFieldEntries = computed(() => (
	props.slot.assigned_character_id
		? fieldEntries.value.filter((field) => field.value && field.source !== 'character_classes' && field.source !== 'phantom_jobs')
		: []
));
const roleToneClass = computed(() => {
	if (!assignedCharacter.value) {
		return 'border-dashed border-default bg-elevated hover:border-primary';
	}

	if (roleField.value === 'tank') {
		return 'border-blue-500/70 bg-blue-500/10 hover:border-blue-400';
	}

	if (roleField.value === 'healer') {
		return 'border-emerald-500/70 bg-emerald-500/10 hover:border-emerald-400';
	}

	return 'border-red-500/70 bg-red-500/10 hover:border-red-400';
});
const classIconUrl = computed(() => classField.value?.display_meta?.flaticon_url || classField.value?.display_meta?.icon_url || null);
const phantomIconUrl = computed(() => phantomField.value?.display_meta?.transparent_icon_url || phantomField.value?.display_meta?.icon_url || phantomField.value?.display_meta?.sprite_url || null);
const classDisplayValue = computed(() => classField.value
	? (typeof classField.value.display_value === 'string' ? classField.value.display_value : localizedText(classField.value.display_value, ''))
	: null);
const phantomDisplayValue = computed(() => phantomField.value
	? (typeof phantomField.value.display_value === 'string' ? phantomField.value.display_value : localizedText(phantomField.value.display_value, ''))
	: null);
</script>

<template>
	<div
		class="min-h-28 cursor-pointer border px-4 py-4 transition duration-200 ease-out hover:scale-105 hover:shadow-lg"
		:class="roleToneClass"
	>
		<div class="flex h-full flex-col gap-3">
			<!-- Slot card header: slot identity and assignment status -->
			<div class="flex items-start justify-between gap-3">
				<div class="flex flex-col gap-1">
					<p class="text-xs uppercase tracking-wide text-primary">
						{{ slotLabel }}
					</p>
					<p v-if="!assignedCharacter" class="font-medium text-toned">
						{{ t('groups.activities.management.roster.empty_slot') }}
					</p>
				</div>

				<UBadge
					:color="slot.assigned_character_id ? 'success' : 'neutral'"
					variant="subtle"
					:label="slot.assigned_character_id
						? t('groups.activities.management.roster.assigned')
						: t('groups.activities.management.roster.open')"
				/>
			</div>

			<!-- Filled-slot content: assigned character and role-defining icons -->
			<div v-if="assignedCharacter" class="space-y-3">
				<div class="flex items-start justify-between gap-3">
					<UUser
						:name="assignedCharacter.name"
						:description="assignedCharacter.world || undefined"
						:avatar="assignedCharacter.avatar_url ? { src: assignedCharacter.avatar_url, loading: 'lazy' } : undefined"
						size="lg"
					/>

					<div class="flex items-center">
						<img
							v-if="classIconUrl"
							:src="classIconUrl"
							:alt="classDisplayValue || ''"
							class="h-10 w-10 rounded-sm p-1 object-contain"
						>
						<img
							v-if="phantomIconUrl"
							:src="phantomIconUrl"
							:alt="phantomDisplayValue || ''"
							class="h-10 w-10 rounded-sm  p-1 object-contain"
						>
					</div>
				</div>

				<!-- Filled-slot metadata rows such as position or extra slot fields -->
				<div v-if="visibleFieldEntries.length > 0" class="space-y-2">
					<div
						v-for="field in visibleFieldEntries"
						:key="field.id"
						class="flex items-start justify-between gap-3 text-sm"
					>
						<span class="text-muted">
							{{ field.label }}
						</span>
						<span class="text-right font-medium text-toned">
							{{ field.value }}
						</span>
					</div>
				</div>
			</div>

			<!-- Empty-slot fallback state -->
			<div v-else class="mt-auto text-sm text-muted">
				{{ t('groups.activities.management.roster.open') }}
			</div>
		</div>
	</div>
</template>
