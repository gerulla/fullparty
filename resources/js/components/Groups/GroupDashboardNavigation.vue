<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import { useGroupDashboardNavigation } from '@/composables/useGroupDashboardNavigation'
import type { GroupNavigationContext } from '@/Types/GroupNavigation'

const props = defineProps<{ group: GroupNavigationContext }>()
const emit = defineEmits<{ navigate: [] }>()
const { t } = useI18n()
const { sections } = useGroupDashboardNavigation(() => props.group)
</script>

<template>
    <nav :aria-label="t('groups.index.navigation.menu')" class="space-y-6">
        <Link :href="route('groups.dashboard', { group: group.slug })"
            class="flex min-w-0 items-center gap-3 rounded-lg p-2 transition-colors hover:bg-elevation-3 focus-visible:outline-2 focus-visible:outline-brand-400"
            @click="emit('navigate')">
            <UAvatar :src="group.profile_picture_url ?? undefined" :alt="group.name" icon="i-lucide-users"
                size="lg" :ui="{ root: 'shrink-0 rounded-lg' }" />
            <span class="min-w-0 text-sm font-semibold leading-snug text-highlighted wrap-break-word">{{ group.name ?? group.slug }}</span>
        </Link>

        <section v-for="section in sections" :key="section.key" class="space-y-1">
            <h2 class="px-3 pb-1 text-xs font-semibold tracking-wide text-muted">{{ section.label }}</h2>
            <Link v-for="item in section.links" :key="item.href" :href="item.href"
                :aria-current="item.active ? 'page' : undefined"
                class="flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm leading-snug transition-colors focus-visible:outline-2 focus-visible:outline-brand-400 max-xl:min-h-11"
                :class="item.active
                    ? 'bg-brand-100 font-semibold text-brand-800 dark:bg-brand-400/15 dark:text-brand-300'
                    : 'text-toned hover:bg-elevation-3 hover:text-highlighted'"
                @click="emit('navigate')">
                <UIcon :name="item.icon" class="size-4.5 shrink-0" />
                <span class="min-w-0">{{ item.label }}</span>
            </Link>
        </section>

        <div class="border-t border-muted pt-3">
            <Link :href="route('groups.index')"
                class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted transition-colors hover:bg-elevation-3 hover:text-highlighted focus-visible:outline-2 focus-visible:outline-brand-400"
                @click="emit('navigate')">
                <UIcon name="i-lucide-arrow-left" class="size-4.5 shrink-0" />
                {{ t('groups.index.navigation.back_to_groups') }}
            </Link>
        </div>
    </nav>
</template>
