<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { HolsterLoadout } from '@/Types/HolsterPlanner'

const props = defineProps<{ holster: HolsterLoadout; compact?: boolean }>()
const { t } = useI18n()
const roles: Record<string, { label: string; icon: string }> = {
    tank: { label: 'general.roles.tank', icon: '/role-icons/tank.png' },
    healer: { label: 'general.roles.healer', icon: '/role-icons/healer.png' },
    'melee dps': { label: 'general.roles.melee_dps', icon: '/role-icons/melee_dps.png' },
    'physical ranged dps': { label: 'general.roles.physical_ranged_dps', icon: '/role-icons/physrange_dps.png' },
    'magic ranged dps': { label: 'general.roles.magic_ranged_dps', icon: '/role-icons/magic_range_dps.png' },
}
const role = computed(() => props.holster.role ? roles[props.holster.role] : undefined)
const roleLabel = computed(() => props.holster.role ? t(role.value?.label ?? props.holster.role) : '')
</script>

<template>
    <section class="min-w-0" :class="[
        compact ? 'border-l-2 pl-3' : 'border-t-2 pt-3',
        holster.type === 'refill' ? 'border-info' : 'border-primary',
    ]">
        <div class="mb-2 flex items-center gap-2 text-xs font-medium" :class="holster.type === 'refill' ? 'text-info' : 'text-primary'">
            <UIcon :name="holster.type === 'refill' ? 'i-lucide-rotate-cw' : 'i-lucide-log-in'" class="size-4 shrink-0" />
            {{ t(`holsters.${holster.type}`) }}
        </div>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <img v-if="role" :src="role.icon" alt="" class="size-6 shrink-0 object-contain" />
            <UIcon v-else name="i-lucide-shield-question" class="size-6 shrink-0 text-muted" />
            <div class="min-w-0 flex-1 break-words font-medium text-highlighted" :class="compact ? 'text-sm' : 'text-base'">
                <slot name="heading">{{ holster.name }}</slot>
            </div>
            <span v-if="holster.capacity_used !== null && holster.max_capacity !== null" class="text-xs whitespace-nowrap text-muted tabular-nums">
                {{ t('holsters.capacity', { used: holster.capacity_used, max: holster.max_capacity }) }}
            </span>
        </div>
        <p v-if="roleLabel" class="mt-1 text-xs text-muted">{{ roleLabel }}</p>
        <template v-if="compact">
            <div v-if="holster.items.length" class="mt-2 flex flex-wrap gap-1.5" :aria-label="t('holsters.contents')">
                <UPopover v-for="item in holster.items" :key="item.id">
                    <button type="button" class="flex items-center gap-1 border border-default bg-elevated/60 p-1 text-xs tabular-nums hover:border-primary" :aria-label="`${item.name} ×${item.quantity}`">
                        <img v-if="item.icon_url" :src="item.icon_url" alt="" class="size-6 object-contain" />
                        <UIcon v-else name="i-lucide-package" class="size-6 text-muted" />
                        <span class="pr-1">×{{ item.quantity }}</span>
                    </button>
                    <template #content><div class="max-w-64 p-3 text-sm">{{ item.name }} <span class="font-medium">×{{ item.quantity }}</span></div></template>
                </UPopover>
            </div>
            <details class="mt-2 text-xs">
                <summary class="cursor-pointer text-muted hover:text-highlighted">{{ t('holsters.details', { count: holster.items.length }) }}</summary>
                <p v-if="holster.notes" class="mt-2 whitespace-pre-line text-muted">{{ holster.notes }}</p>
                <ul class="mt-2 space-y-1.5">
                    <li v-for="item in holster.items" :key="item.id" class="flex justify-between gap-3"><span class="break-words">{{ item.name }}</span><span class="shrink-0 tabular-nums">×{{ item.quantity }}</span></li>
                </ul>
                <p v-if="!holster.items.length" class="mt-2 text-muted">{{ t('holsters.no_items') }}</p>
            </details>
        </template>
        <template v-else>
            <p v-if="holster.notes" class="mt-3 text-sm whitespace-pre-line text-muted">{{ holster.notes }}</p>
            <ul class="mt-3 divide-y divide-default">
                <li v-for="item in holster.items" :key="item.id" class="flex min-w-0 items-center gap-3 py-2.5">
                    <img v-if="item.icon_url" :src="item.icon_url" alt="" class="size-8 shrink-0 object-contain" />
                    <UIcon v-else name="i-lucide-package" class="size-8 shrink-0 text-muted" />
                    <span class="min-w-0 flex-1 break-words text-sm">{{ item.name }}</span>
                    <span class="shrink-0 text-sm font-medium tabular-nums">×{{ item.quantity }}</span>
                </li>
            </ul>
            <p v-if="!holster.items.length" class="mt-3 text-sm text-muted">{{ t('holsters.no_items') }}</p>
        </template>
    </section>
</template>
