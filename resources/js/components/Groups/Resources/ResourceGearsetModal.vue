<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GearsetCandidate, GearsetDisplay, GearsetSnapshot } from '@/Types/XivGear'
import ResourceGearsetEmbed from './ResourceGearsetEmbed.vue'

defineProps<{ open: boolean; url: string; loadedUrl: string; display: GearsetDisplay; busy: boolean; error: string; sets: GearsetCandidate[]; snapshots: GearsetSnapshot[]; selected: number[] }>()
defineEmits<{ close: []; load: [indices?: number[]]; insert: []; 'update:url': [value: string]; 'update:display': [value: GearsetDisplay]; 'update:selected': [value: number[]] }>()
const { t } = useI18n()
const l = (key: string) => t(`xivgear.${key}`)
</script>

<template>
    <UModal :open="open" :title="l('insert_title')" :description="l('import_help')" :ui="{ content: 'max-w-4xl', body: 'overflow-y-auto' }" @update:open="!$event && $emit('close')">
        <template #body>
            <form class="flex items-end gap-2" @submit.prevent="$emit('load')">
                <UFormField :label="l('url')" class="min-w-0 flex-1"><UInput :model-value="url" type="url" :maxlength="2048" placeholder="https://xivgear.app/…" :disabled="busy" autofocus class="w-full" @update:model-value="$emit('update:url', $event)" /></UFormField>
                <UButton type="submit" icon="i-lucide-download" :label="l('load')" :loading="busy" :disabled="!url.trim()" />
            </form>
            <UAlert v-if="error" color="error" variant="soft" :title="error" class="mt-4" />
            <div v-if="sets.length > 1 && loadedUrl === url.trim()" class="mt-4">
                <p class="mb-2 text-sm font-medium">{{ l('choose_set') }}</p>
                <div class="grid max-h-40 gap-1 overflow-y-auto">
                    <UCheckbox v-for="set in sets" :key="set.index" :label="`${set.job} · ${set.name}`" :disabled="busy || (!selected.includes(set.index) && selected.length >= 20)" :model-value="selected.includes(set.index)" @update:model-value="$emit('update:selected', $event ? [...selected, set.index].sort((a, b) => a - b) : selected.filter(index => index !== set.index))" />
                </div>
                <p class="mt-2 text-xs text-muted">{{ l('tabs_help') }}</p>
                <UButton class="mt-3" color="neutral" variant="outline" :label="l('load_selected')" :loading="busy" :disabled="!selected.length" @click="$emit('load', selected)" />
            </div>
            <fieldset class="mt-5">
                <legend class="mb-2 text-sm font-medium">{{ l('display') }}</legend>
                <div class="grid grid-cols-2 gap-3">
                    <label v-for="mode in (['expanded', 'compact'] as const)" :key="mode" class="flex cursor-pointer items-start gap-3 border p-3" :class="display === mode ? 'border-primary bg-primary/5' : 'border-default'">
                        <input type="radio" name="gearset-display" :value="mode" :checked="display === mode" class="mt-1 accent-primary" @change="$emit('update:display', mode)">
                        <span><b class="block text-sm">{{ l(mode) }}</b><span class="mt-1 block text-xs text-muted">{{ l(`${mode}_help`) }}</span></span>
                    </label>
                </div>
            </fieldset>
            <ResourceGearsetEmbed v-if="snapshots.length && loadedUrl === url.trim()" :snapshots="snapshots" :display="display" />
        </template>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton color="neutral" variant="outline" :label="l('cancel')" @click="$emit('close')" />
                <UButton :label="l('insert')" icon="i-lucide-plus" :disabled="busy || !snapshots.length || loadedUrl !== url.trim()" @click="$emit('insert')" />
            </div>
        </template>
    </UModal>
</template>
