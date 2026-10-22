<script setup lang="ts">
import { ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupType } from '@/Types/Groups'

const emit = defineEmits<{ choose: [type: GroupType] }>()
const { t } = useI18n()
const staticInfoOpen = ref(false)
const staticNoticeId = useId()
const types = [
    { value: 'community', icon: 'i-lucide-users-round' },
    { value: 'static', icon: 'i-lucide-swords' },
] as const
const benefits = ['roster', 'activities', 'coordination'] as const
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2">
        <div v-for="type in types" :key="type.value" class="relative flex min-w-0">
            <UCard as="button" type="button"
                :aria-label="t('groups.index.create_flow.continue_with', { type: t(`groups.common.group_types.${type.value}`) })"
                :aria-describedby="type.value === 'static' ? staticNoticeId : undefined"
                class="flex w-full min-w-0 cursor-pointer flex-col rounded-xl border border-elevation-3 bg-elevation-1 text-left shadow-sm ring-0 transition-colors hover:border-brand-500 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand motion-reduce:transition-none dark:border-elevation-7 dark:bg-elevation-3 dark:shadow-none dark:hover:border-brand-400"
                :ui="{ body: 'flex flex-1 flex-col p-6 sm:p-7' }"
                @click="emit('choose', type.value)">
                <div class="mb-5 flex justify-center" aria-hidden="true">
                    <span class="flex size-20 items-center justify-center text-neutral-600 dark:text-neutral-300">
                        <UIcon :name="type.icon" class="size-10" />
                    </span>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <h2 :id="`group-type-${type.value}`" class="text-center text-xl font-semibold text-highlighted">
                        {{ t(`groups.common.group_types.${type.value}`) }}
                    </h2>
                    <UBadge v-if="type.value === 'static'" :id="staticNoticeId" color="warning" variant="soft" size="sm"
                        class="rounded-md text-warning-700 dark:text-warning-300">
                        {{ t('groups.index.create_flow.static_notice.title') }}
                    </UBadge>
                </div>
                <p class="mt-3 text-sm leading-relaxed text-muted md:min-h-18">
                    {{ t(`groups.index.create_flow.types.${type.value}.description`) }}
                </p>
                <ul class="mt-6 space-y-3 text-sm text-default">
                    <li v-for="benefit in benefits" :key="benefit" class="flex items-start gap-3 leading-relaxed">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-700/10 text-brand-700 dark:bg-brand-300/10 dark:text-brand-300" aria-hidden="true">
                            <UIcon name="i-lucide-check" class="size-3" />
                        </span>
                        <span>{{ t(`groups.index.create_flow.types.${type.value}.benefits.${benefit}`) }}</span>
                    </li>
                </ul>
                <span class="mt-auto inline-flex items-center justify-center gap-2 pt-7 text-sm font-semibold text-brand-700 dark:text-brand-300">
                    {{ t('general.continue') }}
                    <UIcon name="i-lucide-arrow-right" class="size-4" aria-hidden="true" />
                </span>
            </UCard>
            <div v-if="type.value === 'static'" class="absolute top-2 right-2 flex items-center gap-1">
                <UPopover v-model:open="staticInfoOpen" :content="{ side: 'bottom', align: 'end' }"
                    :ui="{ content: 'w-80 max-w-[calc(100vw-2rem)] rounded-lg bg-elevation-1 text-default shadow-lg ring-default dark:bg-elevation-4' }">
                    <UTooltip :text="t('groups.index.create_flow.static_notice.description')" :disabled="staticInfoOpen"
                        :ui="{ content: 'h-auto max-w-80 rounded-lg p-3', text: 'whitespace-normal text-sm leading-relaxed' }">
                        <UButton type="button" color="neutral" variant="ghost" icon="i-lucide-circle-help"
                            class="size-8 justify-center rounded-full text-muted max-md:size-11"
                            :aria-label="t('groups.index.create_flow.static_notice.info_label')" />
                    </UTooltip>
                    <template #content>
                        <div class="space-y-2 p-4">
                            <p class="text-sm font-semibold text-highlighted">{{ t('groups.index.create_flow.static_notice.title') }}</p>
                            <p class="text-sm leading-relaxed text-muted">{{ t('groups.index.create_flow.static_notice.description') }}</p>
                        </div>
                    </template>
                </UPopover>
            </div>
        </div>
    </div>
</template>
