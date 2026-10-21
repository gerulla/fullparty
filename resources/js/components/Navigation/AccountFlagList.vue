<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AccountFlag } from '../../Types/AccountFlags'
import { accountFlagPresentation } from '@/utils/accountFlags'

defineProps<{ flags: AccountFlag[] }>()
defineEmits<{ view: [] }>()
const { t } = useI18n()
</script>

<template>
    <ul class="space-y-3">
        <li v-for="flag in flags" :key="flag.id">
            <article class="rounded-xl border border-default bg-default p-4 shadow-lg">
                <div class="flex items-start gap-3">
                    <UIcon :name="accountFlagPresentation[flag.severity].icon" class="mt-0.5 size-5 shrink-0"
                        :class="accountFlagPresentation[flag.severity].foreground" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <span class="sr-only">{{ t(`general.account_flags.severity.${flag.severity}`) }}:</span>
                        <h3 class="text-sm font-semibold text-highlighted">{{ t(flag.title_key, flag.parameters) }}</h3>
                        <p class="mt-1 break-words text-sm leading-relaxed text-muted">{{ t(flag.description_key, flag.parameters) }}</p>
                        <UButton :to="flag.url" :color="flag.severity" variant="soft" size="sm" class="mt-3 rounded-md"
                            :class="accountFlagPresentation[flag.severity].foreground"
                            trailing-icon="i-lucide-arrow-right" @click="$emit('view')">
                            {{ t('general.account_flags.view_issue') }}
                        </UButton>
                    </div>
                </div>
            </article>
        </li>
    </ul>
</template>
