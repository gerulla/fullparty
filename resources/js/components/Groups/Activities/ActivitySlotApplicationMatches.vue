<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import type { ActivitySlotApplicationMatch } from "@/Types/ActivityRoster";
import { localizedValue } from "@/utils/localizedValue";

const props = withDefaults(defineProps<{
	matches: ActivitySlotApplicationMatch[]
}>(), {
	matches: () => [],
});

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const matchLabel = (match: ActivitySlotApplicationMatch) => (
	localizedValue(match.label, locale.value, fallbackLocale.value) || match.key
);
</script>

<template>
	<UPopover
		v-if="matches.length > 0"
		:content="{ side: 'top' }"
		arrow
		mode="hover"
		enable-touch
	>
		<span class="ml-2 inline-flex items-center gap-1.5 normal-case tracking-normal">
			<span
				v-for="match in matches"
				:key="match.key"
				class="inline-flex items-center gap-0.5 text-[11px] font-semibold"
				:class="match.matches ? 'text-success' : 'text-error'"
			>
				<span>{{ match.abbreviation }}</span>
				<UIcon
					:name="match.matches ? 'i-lucide-check' : 'i-lucide-x'"
					class="size-3.5"
				/>
			</span>
		</span>

		<template #content>
			<div class="space-y-2.5 p-4">
				<p class="text-xs font-semibold text-highlighted">
					{{ t('groups.activities.management.roster.application_match.title') }}
				</p>
				<div
					v-for="match in matches"
					:key="match.key"
					class="flex items-center justify-between gap-5 text-xs"
				>
					<span class="text-muted">{{ matchLabel(match) }}</span>
					<span
						class="inline-flex shrink-0 items-center gap-1 font-medium"
						:class="match.matches ? 'text-success' : 'text-error'"
					>
						<UIcon
							:name="match.matches ? 'i-lucide-check' : 'i-lucide-x'"
							class="size-3.5"
						/>
						{{ t(match.matches
							? 'groups.activities.management.roster.application_match.matched'
							: 'groups.activities.management.roster.application_match.not_matched') }}
					</span>
				</div>
			</div>
		</template>
	</UPopover>
</template>
