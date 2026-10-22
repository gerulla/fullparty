<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupTimeZoneOption } from '@/Types/Groups'

const props = defineProps<{ modelValue: string; options: GroupTimeZoneOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()
const hovered = ref<string | null>(null)
const mapCities = new Set([
    'Pacific/Honolulu', 'America/Anchorage', 'America/Los_Angeles', 'America/Denver',
    'America/Chicago', 'America/New_York', 'America/Halifax', 'America/Mexico_City',
    'America/Bogota', 'America/Lima', 'America/Santiago', 'America/Sao_Paulo',
    'America/Argentina/Buenos_Aires', 'America/Buenos_Aires', 'Atlantic/Azores', 'Europe/London', 'Europe/Berlin',
    'Europe/Istanbul', 'Europe/Moscow', 'Africa/Cairo', 'Africa/Lagos', 'Africa/Johannesburg',
    'Africa/Nairobi', 'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Calcutta',
    'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Shanghai', 'Asia/Tokyo', 'Asia/Manila',
    'Asia/Jakarta', 'Asia/Krasnoyarsk', 'Asia/Yakutsk', 'Asia/Kamchatka',
    'Australia/Perth', 'Australia/Darwin', 'Australia/Adelaide', 'Australia/Sydney',
    'Pacific/Auckland', 'Pacific/Fiji',
])
const selected = computed(() => props.options.find(option => option.value === props.modelValue))
const markers = computed(() => props.options.filter(option => option.x !== undefined
    && (mapCities.has(option.value) || option.value === props.modelValue))
    .sort((left, right) => Number(left.value === props.modelValue) - Number(right.value === props.modelValue)))
const highlighted = computed(() => props.options.find(option => option.value === hovered.value) ?? selected.value)
</script>

<template>
    <section class="min-w-0 space-y-4" :aria-label="t('groups.index.create_flow.activity.timezone')">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-highlighted">{{ t('groups.index.create_flow.activity.timezone') }}</h2>
                <p class="mt-1 hidden text-sm text-muted xl:block">{{ t('groups.index.create_flow.activity.map_help') }}</p>
            </div>
            <USelectMenu :model-value="modelValue" :items="options" value-key="value" :filter-fields="['label', 'value']" icon="i-lucide-globe"
                :aria-label="t('groups.index.create_flow.activity.timezone')"
                :search-input="{ placeholder: t('groups.index.create_flow.activity.search_timezone') }"
                :placeholder="t('groups.index.create_modal.fields.active_timezone.placeholder')"
                size="lg" class="w-full rounded-lg bg-elevation-1 shadow-sm xl:w-80 dark:bg-elevation-3 dark:shadow-none"
                :ui="{ content: 'rounded-lg', item: 'rounded-md', value: 'truncate' }"
                @update:model-value="emit('update:modelValue', $event as string)" />
        </div>
        <div class="relative hidden xl:block">
            <svg viewBox="0 0 1000 405" class="block h-auto w-full" role="group"
                :aria-label="t('groups.index.create_flow.activity.timezone')">
                <image :href="'/images/groups/world-map.svg'" width="1000" height="440" aria-hidden="true"
                    class="pointer-events-none opacity-35 dark:opacity-30" />
                <g v-for="zone in markers" :key="zone.value" :transform="`translate(${zone.x},${zone.y})`"
                    role="button" tabindex="0" :aria-label="zone.label" :aria-pressed="zone.value === modelValue"
                    class="timezone-marker group cursor-pointer outline-none"
                    :class="zone.value === modelValue ? 'text-brand-700 dark:text-brand-300' : 'text-neutral-600 dark:text-neutral-300'"
                    @click="emit('update:modelValue', zone.value)"
                    @keydown.enter.prevent="emit('update:modelValue', zone.value)"
                    @keydown.space.prevent="emit('update:modelValue', zone.value)"
                    @mouseenter="hovered = zone.value" @mouseleave="hovered = null"
                    @focus="hovered = zone.value" @blur="hovered = null">
                    <title>{{ zone.label }}</title>
                    <circle r="14" fill="transparent" />
                    <circle v-if="zone.value === modelValue" r="13" fill="currentColor" opacity="0.2" />
                    <circle :r="zone.value === modelValue ? 6 : 4" fill="currentColor"
                        class="marker-dot stroke-elevation-1 group-hover:fill-brand-600 dark:stroke-elevation-2 dark:group-hover:fill-brand-300"
                        stroke-width="1.5" />
                </g>
            </svg>
            <div class="pointer-events-none absolute inset-x-0 bottom-1 flex justify-center" aria-hidden="true">
                <span v-if="highlighted" class="rounded-lg bg-elevation-1/95 px-4 py-2 text-sm font-medium text-highlighted shadow-sm dark:bg-elevation-3/95 dark:shadow-none">
                    {{ highlighted.city }} <span class="ml-2 text-muted">{{ highlighted.offsetLabel }}</span>
                </span>
            </div>
        </div>
        <p class="text-sm text-muted">{{ t('groups.index.create_flow.activity.timezone_help') }}</p>
    </section>
</template>

<style scoped>
.timezone-marker:focus-visible .marker-dot {
    stroke: var(--color-brand-400);
    stroke-width: 5;
}
</style>
