<script setup lang="ts">
import type { GroupDashboardGroup } from '@/Types/Groups'
import { buildGroupTimeZoneOptions } from '@/utils/groupTimeZoneOptions'
import { uiLocales } from '@/i18n/uiLocales'
import { useToast } from '@nuxt/ui/composables'
import { useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

type GroupDiscoveryLookups = {
	primary_focuses?: string[]
	experience_expectations?: string[]
	voice_expectations?: string[]
	active_days?: string[]
	preferred_languages?: string[]
	max_tags?: number
}


const props = defineProps<{
	group: GroupDashboardGroup
}>()

const { t, locale } = useI18n()
const toast = useToast()
const page = usePage()
const tagSearchTerm = ref('')
const availableTags = ref<string[]>([])

const groupDiscoveryLookups = computed<GroupDiscoveryLookups>(() => page.props.lookups?.group_discovery ?? {})
const localeOptions = computed(() => (groupDiscoveryLookups.value.preferred_languages ?? []).map((code) => ({
	value: code,
	label: uiLocales[code as keyof typeof uiLocales]?.name ?? code.toUpperCase(),
})))
const primaryFocusOptions = computed(() => (groupDiscoveryLookups.value.primary_focuses ?? []).map((value) => ({
	value,
	label: t(`groups.index.create_modal.fields.primary_focuses.options.${value}`),
})))
const experienceExpectationOptions = computed(() => (groupDiscoveryLookups.value.experience_expectations ?? []).map((value) => ({
	value,
	label: t(`groups.index.create_modal.fields.experience_expectation.options.${value}`),
})))
const voiceExpectationOptions = computed(() => (groupDiscoveryLookups.value.voice_expectations ?? []).map((value) => ({
	value,
	label: t(`groups.common.voice_expectations.${value}`),
})))
const activeDayOptions = computed(() => (groupDiscoveryLookups.value.active_days ?? []).map((value) => ({
	value,
	label: t(`groups.common.active_days.${value}`),
})))
const timeZoneOptions = computed(() => buildGroupTimeZoneOptions())
const maxTagCount = computed(() => Number(groupDiscoveryLookups.value.max_tags ?? 12))
const regionSummary = computed(() => {
	if (props.group.datacenter && props.group.region) {
		return t('groups.index.create_modal.fields.region.inferred', {
			datacenter: props.group.datacenter,
			region: props.group.region,
		})
	}

	return t('groups.index.create_modal.fields.region.pending')
})

const form = useForm({
	primary_focuses: [...props.group.primary_focuses],
	experience_expectation: props.group.experience_expectation ?? '',
	voice_expectation: props.group.voice_expectation ?? '',
	preferred_languages: [...props.group.preferred_languages],
	tags: [...props.group.tags],
	active_timezone: props.group.active_timezone ?? '',
	active_days: [...props.group.active_days],
	active_start_time: props.group.active_start_time ?? '',
	active_end_time: props.group.active_end_time ?? '',
})

const mergeTagOptions = (tags: string[]) => {
	availableTags.value = Array.from(new Set(tags))
		.filter((tag) => tag !== '')
		.sort((left, right) => left.localeCompare(right))
}

watch(() => props.group.tags, (tags) => {
	mergeTagOptions(tags)
}, { immediate: true })

const addCreatedTag = (rawTag: string) => {
	const tag = String(rawTag ?? '').trim()

	if (!tag) {
		tagSearchTerm.value = ''
		return
	}

	if (!form.tags.includes(tag)) {
		form.tags = [...form.tags, tag]
	}

	mergeTagOptions([...availableTags.value, tag])
	tagSearchTerm.value = ''
}

const submit = () => {
	form.put(route('groups.dashboard.discovery-settings.update', props.group.slug), {
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.settings.discovery.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check',
			})
		},
	})
}
</script>

<template>
	<UCard :ui="{ root: 'rounded-none', body: 'p-5 sm:p-6' }">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-bold">{{ t('groups.settings.discovery.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.settings.discovery.subtitle') }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-6" @submit.prevent="submit">
			<div class="rounded-sm border border-default bg-muted/20 px-3 py-3">
				<p class="text-sm font-semibold mb-1">{{ t('groups.index.create_modal.fields.region.label') }}</p>
				<p class="text-sm text-muted">{{ regionSummary }}</p>
			</div>

			<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
				<UFormField
					:label="t('groups.index.create_modal.fields.experience_expectation.label')"
					:help="t('groups.index.create_modal.fields.experience_expectation.help')"
					:error="form.errors.experience_expectation"
				>
					<USelect
						v-model="form.experience_expectation"
						class="w-full"
						:items="experienceExpectationOptions"
						value-key="value"
						:placeholder="t('groups.index.create_modal.fields.experience_expectation.placeholder')"
						:ui="{ base: 'rounded-none' }"
					/>
				</UFormField>
			</div>

			<UFormField
				:label="t('groups.index.create_modal.fields.primary_focuses.label')"
				:help="t('groups.index.create_modal.fields.primary_focuses.help')"
				:error="form.errors.primary_focuses"
			>
				<USelectMenu
					v-model="form.primary_focuses"
					class="w-full"
					:items="primaryFocusOptions"
					value-key="value"
					multiple
					:placeholder="t('groups.index.create_modal.fields.primary_focuses.placeholder')"
				/>
			</UFormField>

			<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
				<UFormField
					:label="t('groups.index.create_modal.fields.voice_expectation.label')"
					:help="t('groups.index.create_modal.fields.voice_expectation.help')"
					:error="form.errors.voice_expectation"
				>
					<USelect
						v-model="form.voice_expectation"
						class="w-full"
						:items="voiceExpectationOptions"
						value-key="value"
						:placeholder="t('groups.index.create_modal.fields.voice_expectation.placeholder')"
						:ui="{ base: 'rounded-none' }"
					/>
				</UFormField>

				<UFormField
					:label="t('groups.index.create_modal.fields.preferred_languages.label')"
					:help="t('groups.index.create_modal.fields.preferred_languages.help')"
					:error="form.errors.preferred_languages"
				>
					<USelectMenu
						v-model="form.preferred_languages"
						class="w-full"
						:items="localeOptions"
						value-key="value"
						multiple
						:placeholder="t('groups.index.create_modal.fields.preferred_languages.placeholder')"
					/>
				</UFormField>
			</div>

			<UFormField
				:label="t('groups.index.create_modal.fields.tags.label')"
				:help="t('groups.index.create_modal.fields.tags.help', { max: maxTagCount })"
				:error="form.errors.tags"
			>
				<UInputMenu
					v-model="form.tags"
					v-model:search-term="tagSearchTerm"
					class="w-full"
					:items="availableTags"
					multiple
					create-item="always"
					:placeholder="t('groups.index.create_modal.fields.tags.placeholder')"
					@create="addCreatedTag"
				/>
			</UFormField>

			<div class="border-t border-default pt-6">
				<div class="flex flex-col gap-1 mb-4">
					<p class="font-bold">{{ t('groups.settings.discovery.active_window_title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.settings.discovery.active_window_subtitle') }}</p>
				</div>

				<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
					<UFormField
						:label="t('groups.index.create_modal.fields.active_timezone.label')"
						:help="t('groups.index.create_modal.fields.active_timezone.help')"
						:error="form.errors.active_timezone"
					>
						<USelectMenu
							v-model="form.active_timezone"
							class="w-full"
							:items="timeZoneOptions"
							value-key="value"
							:placeholder="t('groups.index.create_modal.fields.active_timezone.placeholder')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.active_days.label')"
						:help="t('groups.index.create_modal.fields.active_days.help')"
						:error="form.errors.active_days"
					>
						<USelectMenu
							v-model="form.active_days"
							class="w-full"
							:items="activeDayOptions"
							value-key="value"
							multiple
							:placeholder="t('groups.index.create_modal.fields.active_days.placeholder')"
						/>
					</UFormField>
				</div>

				<div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
					<UFormField
						:label="t('groups.index.create_modal.fields.active_start_time.label')"
						:help="t('groups.index.create_modal.fields.active_start_time.help')"
						:error="form.errors.active_start_time"
					>
						<UInput
							v-model="form.active_start_time"
							class="w-full"
							type="time"
							:lang="locale"
							step="60"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.index.create_modal.fields.active_end_time.label')"
						:help="t('groups.index.create_modal.fields.active_end_time.help')"
						:error="form.errors.active_end_time"
					>
						<UInput
							v-model="form.active_end_time"
							class="w-full"
							type="time"
							:lang="locale"
							step="60"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>
				</div>
			</div>

			<div class="flex justify-end">
				<UButton
					type="submit"
					color="primary"
					size="lg"
					:ui="{ base: 'rounded-none' }"
					:loading="form.processing"
					:label="t('general.save')"
				/>
			</div>
		</form>
	</UCard>
</template>
