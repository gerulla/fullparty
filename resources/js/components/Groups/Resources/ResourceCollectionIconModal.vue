<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { addCollection } from '@iconify/vue'
import catalog from '@iconify-json/lucide/icons.json'

const props = defineProps<{ name: string; icon: string; busy: boolean; error: string }>()
const emit = defineEmits<{ close: []; select: [icon: string | null] }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
// This catalog is bundled with the lazy-loaded modal, so browsing icons needs no network requests.
addCollection(catalog)
const names = Object.keys(catalog.icons).sort()
const query = ref('')
const page = ref(1)
const pageSize = 84
const filtered = computed(() => {
    const search = query.value.trim().toLowerCase().replace(/^(?:i-lucide-|lucide:)/, '').replace(/[\s_]+/g, '-')
    return names.filter(name => name.includes(search))
})
const visible = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize))
watch(query, () => { page.value = 1 })
</script>

<template>
    <UModal :open="true" :title="l('change_icon')" :description="name" :dismissible="!busy" :close="!busy" :ui="{ content: 'sm:max-w-3xl' }" @update:open="value => { if (!value && !busy) emit('close') }">
        <template #body>
            <div class="space-y-4">
                <UAlert v-if="error" :title="error" color="error" variant="soft" />
                <div class="flex items-center gap-3">
                    <UInput v-model="query" autofocus icon="i-lucide-search" :placeholder="l('search_icons')" :aria-label="l('search_icons')" class="min-w-0 flex-1" :disabled="busy" />
                    <UButton color="neutral" variant="outline" :label="l('clear_icon')" :disabled="busy" @click="emit('select', null)" />
                </div>
                <p class="text-xs text-muted" role="status">{{ t('groups.resources.workspace.icon_results', { count: filtered.length }) }}</p>
                <div :key="page" class="grid max-h-[50vh] grid-cols-4 gap-1 overflow-y-auto sm:grid-cols-7" :aria-busy="busy">
                    <UButton v-for="name in visible" :key="name" color="neutral" :variant="icon === `i-lucide-${name}` ? 'soft' : 'ghost'" :aria-label="`i-lucide-${name}`" :aria-pressed="icon === `i-lucide-${name}`" :title="`i-lucide-${name}`" class="min-w-0 flex-col gap-2 px-1 py-3" :disabled="busy" @click="emit('select', `i-lucide-${name}`)">
                        <UIcon :name="`i-lucide-${name}`" class="size-6 shrink-0" />
                        <span class="w-full truncate text-center text-[10px] font-normal">{{ name }}</span>
                    </UButton>
                </div>
                <p v-if="!filtered.length" class="py-8 text-center text-sm text-muted">{{ l('no_icons') }}</p>
                <UPagination v-if="filtered.length > pageSize" v-model:page="page" :total="filtered.length" :items-per-page="pageSize" :sibling-count="1" :disabled="busy" class="flex justify-center" />
            </div>
        </template>
    </UModal>
</template>
