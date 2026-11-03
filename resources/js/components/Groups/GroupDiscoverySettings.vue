<script setup lang="ts">
import type { GroupActiveTimePreset, GroupActivityTimeRange, GroupDiscoveryLookups } from '@/Types/Groups'
import type { GroupDiscoverySettingsGroup } from '@/Types/GroupSettings'
import GroupCreateChoiceField from '@/components/Groups/GroupCreateChoiceField.vue'
import GroupActivityTimeRangeSlider from '@/components/Groups/GroupActivityTimeRangeSlider.vue'
import { groupActivityTimeRanges, groupActivityTimePreset, timeOfDayMinutes } from '@/utils/groupActivityWindow'
import { groupActivityTimeZoneOptions } from '@/utils/groupActivityTimeZones'
import { sanitizeSingleLineText } from '@/utils/textInputSanitizer'
import { uiLocales } from '@/i18n/uiLocales'
import { useToast } from '@nuxt/ui/composables'
import { useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{
    group: GroupDiscoverySettingsGroup
}>()

const { t } = useI18n()
const toast = useToast()
const page = usePage()
const tagInput = ref('')
const tagError = ref('')
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
const timeZoneOptions = computed(() => groupActivityTimeZoneOptions(new Date(), props.group.active_timezone || Intl.DateTimeFormat().resolvedOptions().timeZone))
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

const tagOptions = computed(() => availableTags.value.map(value => ({ value, label: value })))
const tagFieldError = computed(() => tagError.value || form.errors.tags
    || Object.entries(form.errors).find(([key]) => key.startsWith('tags.'))?.[1])

function addTag(): boolean {
    const tag = sanitizeSingleLineText(tagInput.value)
    tagError.value = ''
    if (!tag) {
        tagInput.value = ''
        return true
    }
    if ([...tag].length > 50) {
        tagError.value = t('groups.index.create_modal.validation.tag_too_long', { max: 50 })
        return false
    }
    if (form.tags.some(value => value.toLowerCase() === tag.toLowerCase())) {
        tagInput.value = ''
        return true
    }
    if (form.tags.length >= maxTagCount.value) {
        tagError.value = t('groups.index.create_modal.validation.max_tags', { max: maxTagCount.value })
        return false
    }
    form.tags = [...form.tags, tag]
    mergeTagOptions([...availableTags.value, tag])
    tagInput.value = ''
    return true
}

const hasSavedTime = Number.isFinite(timeOfDayMinutes(form.active_start_time))
    && Number.isFinite(timeOfDayMinutes(form.active_end_time))
const timePreset = ref<GroupActiveTimePreset | ''>(hasSavedTime
    ? groupActivityTimePreset(form.active_start_time, form.active_end_time) : '')
const customTime = ref<GroupActivityTimeRange>(hasSavedTime
    ? { start: form.active_start_time, end: form.active_end_time } : { start: '18:00', end: '22:00' })
const timeOptions = computed(() => [...Object.entries(groupActivityTimeRanges).map(([value, range]) => ({
    value,
    label: t(`groups.index.create_flow.activity.time_presets.${value}`),
    description: `${range.start}–${range.end}${range.end < range.start ? ` ${t('groups.index.create_flow.activity.next_day')}` : ''}`,
})), { value: 'custom', label: t('groups.index.create_flow.activity.time_presets.custom') }])
const overnight = computed(() => form.active_start_time && form.active_end_time && form.active_start_time > form.active_end_time)

function selectTime(value: GroupActiveTimePreset) {
    timePreset.value = value
    const range = value === 'custom' ? customTime.value : groupActivityTimeRanges[value]
    form.active_start_time = range.start
    form.active_end_time = range.end
}

function selectCustomTime(range: GroupActivityTimeRange) {
    customTime.value = range
    form.active_start_time = range.start
    form.active_end_time = range.end
}

function clearTime() {
    timePreset.value = ''
    form.active_start_time = ''
    form.active_end_time = ''
}

const submit = () => {
    if (!props.group.permissions.can_manage_discovery || !addTag()) return

    form.put(route('groups.dashboard.discovery-settings.update', props.group.slug), {
        preserveScroll: true,
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
    <UCard class="w-full dark:bg-elevated/25" :ui="{ body: 'p-5 sm:p-6' }">
        <template #header>
            <div class="flex flex-col gap-1">
                <p class="font-semibold">{{ t('groups.settings.discovery.title') }}</p>
                <p class="text-sm text-muted">{{ t('groups.settings.discovery.subtitle') }}</p>
            </div>
        </template>

        <form class="space-y-8" @submit.prevent="submit">
            <div class="rounded-lg border border-default bg-muted/20 p-4">
                <p class="mb-1 text-sm font-semibold">{{ t('groups.index.create_modal.fields.region.label') }}</p>
                <p class="text-sm text-muted">{{ regionSummary }}</p>
            </div>

            <GroupCreateChoiceField compact :model-value="[form.experience_expectation]" :options="experienceExpectationOptions" :multiple="false"
                :label="t('groups.index.create_modal.fields.experience_expectation.label')"
                :help="t('groups.index.create_modal.fields.experience_expectation.help')"
                :error="form.errors.experience_expectation" @update:model-value="form.experience_expectation = $event[0]" />
            <GroupCreateChoiceField compact v-model="form.primary_focuses" :options="primaryFocusOptions"
                :label="t('groups.index.create_modal.fields.primary_focuses.label')"
                :help="t('groups.index.create_modal.fields.primary_focuses.help')" :error="form.errors.primary_focuses" />
            <GroupCreateChoiceField compact :model-value="[form.voice_expectation]" :options="voiceExpectationOptions" :multiple="false"
                :label="t('groups.index.create_modal.fields.voice_expectation.label')"
                :help="t('groups.index.create_modal.fields.voice_expectation.help')"
                :error="form.errors.voice_expectation" @update:model-value="form.voice_expectation = $event[0]" />
            <GroupCreateChoiceField compact v-model="form.preferred_languages" :options="localeOptions"
                :label="t('groups.index.create_modal.fields.preferred_languages.label')"
                :help="t('groups.index.create_modal.fields.preferred_languages.help')" :error="form.errors.preferred_languages" />

            <div class="space-y-5">
                <GroupCreateChoiceField compact v-model="form.tags" :options="tagOptions" :limit="maxTagCount"
                    :label="t('groups.index.create_modal.fields.tags.label')"
                    :help="t('groups.index.create_flow.focus.tags_help', { max: maxTagCount })" />
                <UFormField :label="t('groups.index.create_flow.focus.other_tag')" :error="tagFieldError"
                    :hint="t('groups.index.create_flow.focus.tag_count', { count: form.tags.length, max: maxTagCount })">
                    <div class="flex items-start gap-3">
                        <UInput v-model="tagInput" size="lg" class="min-w-0 flex-1" :maxlength="100"
                            :placeholder="t('groups.index.create_flow.focus.tag_placeholder')"
                            :ui="{ base: 'rounded-lg bg-elevation-1 shadow-sm ring-neutral-300 dark:bg-elevation-4 dark:shadow-none dark:ring-elevation-6' }"
                            @update:model-value="tagError = ''" @keydown.enter.prevent="addTag" />
                        <UButton type="button" color="brand" variant="soft" size="lg" icon="i-lucide-plus"
                            class="shrink-0 rounded-lg" :disabled="!tagInput.trim()" @click="addTag">
                            {{ t('general.add') }}
                        </UButton>
                    </div>
                </UFormField>
            </div>

            <div class="space-y-6 border-t border-default pt-6">
                <div class="space-y-1">
                    <h3 class="font-semibold">{{ t('groups.settings.discovery.active_window_title') }}</h3>
                    <p class="text-sm text-muted">{{ t('groups.settings.discovery.active_window_subtitle') }}</p>
                </div>
                <UFormField :label="t('groups.index.create_modal.fields.active_timezone.label')"
                    :help="t('groups.index.create_modal.fields.active_timezone.help')" :error="form.errors.active_timezone">
                    <USelectMenu v-model="form.active_timezone" class="w-full" size="lg" :items="timeZoneOptions" value-key="value"
                        :placeholder="t('groups.index.create_modal.fields.active_timezone.placeholder')"
                        :ui="{ base: 'rounded-lg bg-elevation-1 dark:bg-elevation-4' }" />
                </UFormField>
                <GroupCreateChoiceField compact v-model="form.active_days" :options="activeDayOptions"
                    :label="t('groups.index.create_modal.fields.active_days.label')"
                    :help="t('groups.index.create_modal.fields.active_days.help')" :error="form.errors.active_days" />
                <GroupCreateChoiceField compact :model-value="timePreset ? [timePreset] : []" :options="timeOptions" :multiple="false"
                    :label="t('groups.index.create_flow.activity.active_time')" :help="t('groups.index.create_flow.activity.time_help')"
                    :error="form.errors.active_start_time || form.errors.active_end_time"
                    @update:model-value="selectTime($event[0] as GroupActiveTimePreset)" />
                <GroupActivityTimeRangeSlider v-if="timePreset === 'custom'" :model-value="customTime" @update:model-value="selectCustomTime" />
                <p v-if="overnight" class="text-sm text-muted">
                    {{ t('groups.index.create_flow.activity.overnight_help', { end: form.active_end_time }) }}
                </p>
                <UButton v-if="timePreset" type="button" color="neutral" variant="ghost" icon="i-lucide-x"
                    :label="t('groups.settings.discovery.clear_active_time')" @click="clearTime" />
            </div>

            <div class="flex justify-end">
                <UButton type="submit" color="brand" size="lg" class="rounded-lg" :loading="form.processing"
                    :disabled="!group.permissions.can_manage_discovery" :label="t('general.save')" />
            </div>
        </form>
    </UCard>
</template>
