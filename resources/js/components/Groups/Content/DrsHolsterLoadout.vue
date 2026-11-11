<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BozjaHolsterDraft, BozjaHolsterItem, BozjaHolsterSummary } from '@/Types/Bozja'
import { holsterRoles } from '@/utils/bozjaHolsterRoles'

const RichTextEditor = defineAsyncComponent(() => import('@/components/Shared/RichText/RichTextEditor.vue'))
const draft = defineModel<BozjaHolsterDraft>({ required: true })
const props = defineProps<{ selected: BozjaHolsterSummary | null; capacity: number; maximum: number; busy: boolean; errors: Record<string, string> }>()
const emit = defineEmits<{ toggle: []; default: []; duplicate: []; delete: []; save: []; addAction: [] }>()
const { t, locale } = useI18n()
const k = 'groups.index.content.delubrum_reginae_savage.holsters.'
const tab = ref('loadout')
const role = computed(() => holsterRoles.find(role => role.value === draft.value.role))
const name = computed(() => draft.value.name[locale.value] || Object.values(draft.value.name).find(Boolean) || t(k + 'new_title'))
const tabs = computed(() => [
    { label: t(k + 'workspace.loadout'), value: 'loadout', icon: 'i-lucide-folder' },
    { label: t(k + 'metadata.guide'), value: 'guide', icon: 'i-lucide-book-open' },
])
const menu = computed(() => [
    { label: t(k + 'duplicate'), icon: 'i-lucide-copy', onSelect: () => emit('duplicate') },
    { label: t('general.delete'), icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => emit('delete') },
])
function maxQuantity(item: BozjaHolsterItem) {
    return item.cache_weight > 0 ? Math.max(1, Math.min(99, Math.floor((props.maximum - props.capacity + item.cache_weight * item.quantity) / item.cache_weight))) : 99
}
function changeQuantity(item: BozjaHolsterItem, value: number | string) {
    item.quantity = Math.max(1, Math.min(maxQuantity(item), Math.floor(Number(value)) || 1))
}
</script>

<template>
    <section class="drs-panel @container min-w-0 p-4 sm:p-5">
        <header class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-3 @2xl:grid-cols-[auto_minmax(0,1fr)_auto]">
            <div class="flex size-12 @2xl:size-14 shrink-0 items-center justify-center rounded-lg border border-default bg-elevation-3">
                <img v-if="role" :src="role.icon" alt="" class="size-10 @2xl:size-11 object-contain">
                <UIcon v-else name="i-lucide-backpack" class="size-7 text-muted" />
            </div>
            <div class="min-w-0 flex-1"><h2 class="break-words text-lg font-bold @2xl:text-xl">{{ name }}</h2><p class="mt-1 text-xs text-muted">{{ role ? t(role.label) : t(k + 'role_unset') }} · {{ t(k + 'types.' + draft.type) }} · {{ capacity }}/{{ maximum }}</p></div>
            <div v-if="selected" class="col-span-2 flex items-center justify-end gap-3 @2xl:col-span-1">
                <UButton icon="i-lucide-star" color="neutral" variant="outline" :class="selected.is_default ? 'text-amber-500' : ''" :aria-label="t(k + (selected.is_default ? 'workspace.default' : 'make_default'))" :disabled="busy || selected.is_default" @click="emit('default')" />
                <USwitch :model-value="selected.is_active" :label="t(k + 'active')" :disabled="busy" @update:model-value="emit('toggle')" />
                <UDropdownMenu :items="menu"><UButton icon="i-lucide-ellipsis" color="neutral" variant="outline" :disabled="busy" :aria-label="t(k + 'actions')" /></UDropdownMenu>
            </div>
        </header>
        <UTabs v-model="tab" :items="tabs" :content="false" variant="link" class="my-5" :ui="{ list: 'justify-start', trigger: 'grow-0' }" />
        <fieldset v-if="tab === 'loadout'" :disabled="busy">
            <div class="mb-4 flex justify-between gap-2 border-b border-default pb-3 text-sm"><h3 class="font-medium text-muted">{{ t(k + 'workspace.inventory') }} ({{ draft.items.length }})</h3><span class="tabular-nums">{{ capacity }} / {{ maximum }}</span></div>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(7.25rem,1fr))] gap-2">
                <div v-for="item in draft.items" :key="item.id" class="relative flex min-w-0 flex-col items-center rounded-lg border border-default bg-elevation-2 p-2 pt-4 dark:bg-elevation-4">
                    <UButton icon="i-lucide-x" size="xs" color="neutral" variant="ghost" :aria-label="t('general.remove') + ': ' + item.display_name" class="absolute right-0 top-0" @click="draft.items = draft.items.filter(entry => entry.id !== item.id)" />
                    <img v-if="item.icon_url" :src="item.icon_url" alt="" class="mb-2 size-11 object-contain">
                    <UIcon v-else name="i-lucide-package" class="mb-2 size-11 text-muted" />
                    <p class="mb-3 text-center text-xs font-medium leading-snug">{{ item.display_name }}</p>
                    <div class="mt-auto flex w-full items-center justify-center">
                        <UButton icon="i-lucide-minus" size="xs" color="neutral" variant="soft" :disabled="item.quantity <= 1" :aria-label="t(k + 'workspace.decrease') + ': ' + item.display_name" @click="changeQuantity(item, item.quantity - 1)" />
                        <input :value="item.quantity" type="number" min="1" :max="maxQuantity(item)" :aria-label="t(k + 'contents.quantity') + ': ' + item.display_name" class="drs-quantity min-w-0 flex-1 bg-transparent text-center text-sm tabular-nums" @change="changeQuantity(item, ($event.target as HTMLInputElement).value)">
                        <UButton icon="i-lucide-plus" size="xs" color="neutral" variant="soft" :disabled="item.quantity >= maxQuantity(item)" :aria-label="t(k + 'workspace.increase') + ': ' + item.display_name" @click="changeQuantity(item, item.quantity + 1)" />
                    </div>
                </div>
                <button type="button" class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-default text-sm text-muted transition hover:border-primary hover:text-primary" @click="emit('addAction')"><UIcon name="i-lucide-plus" class="size-5" />{{ t(k + 'workspace.add_action') }}</button>
            </div>
            <div class="mt-5 flex items-center gap-3 text-xs"><span>{{ t(k + 'capacity.title') }}</span><UProgress :model-value="capacity" :max="maximum" :color="capacity > maximum ? 'error' : 'primary'" class="flex-1" :aria-label="t(k + 'capacity.title')" /><span class="whitespace-nowrap tabular-nums">{{ capacity }} / {{ maximum }}</span></div>
            <p v-if="errors.items" class="mt-2 text-sm text-error">{{ errors.items }}</p>
        </fieldset>
        <div v-else :inert="busy">
            <template v-if="selected?.guide_needs_conversion"><UAlert :title="t('rich_text.conversion_required')" color="warning" variant="soft" class="mb-3" /><div class="rich-text-content" v-html="selected.guide_html" /></template>
            <RichTextEditor v-else v-model="draft.guide" :max-length="50000" class="h-[28rem] rounded-md border border-default" @save="emit('save')" />
            <p v-if="errors.guide" class="mt-2 text-sm text-error">{{ errors.guide }}</p>
        </div>
        <slot />

    </section>
</template>

<style scoped>
.drs-quantity { appearance: textfield; -moz-appearance: textfield; }
.drs-quantity::-webkit-inner-spin-button, .drs-quantity::-webkit-outer-spin-button { appearance: none; margin: 0; }
</style>
