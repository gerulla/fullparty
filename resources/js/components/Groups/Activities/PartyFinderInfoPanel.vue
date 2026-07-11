<script setup lang="ts">
import { useI18n } from "vue-i18n"
import type { ActivityPartyFinderInfo } from "@/Types/ActivityManagement"

defineProps<{
	info: ActivityPartyFinderInfo
}>()

const { t } = useI18n()
</script>

<template>
	<section class="border-t border-default pt-4">
		<div class="flex items-center gap-2 text-primary">
			<UIcon name="i-lucide-search-check" class="size-4" />
			<h4 class="font-semibold text-sm text-toned">{{ t('party_finder.display.title') }}</h4>
		</div>

		<div class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-3">
			<div>
				<p class="text-xs uppercase tracking-wide text-muted">{{ t('party_finder.fields.character_name') }}</p>
				<p class="mt-1 break-words font-medium text-toned">{{ info.character_name }}</p>
			</div>
			<div>
				<p class="text-xs uppercase tracking-wide text-muted">{{ t('party_finder.fields.world') }}</p>
				<p class="mt-1 break-words font-medium text-toned">{{ info.world }}</p>
			</div>
			<div>
				<p class="text-xs uppercase tracking-wide text-muted">{{ t('party_finder.fields.password') }}</p>
				<p class="mt-1 font-mono font-semibold text-primary">{{ info.password }}</p>
			</div>
		</div>
	</section>
</template>
