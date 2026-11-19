<script setup lang="ts">
import axios from 'axios'
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AssignedHolsterLoadout } from '@/Types/ActivityHolsters'
import HolsterPairPanel from '@/components/Shared/Holsters/HolsterPairPanel.vue'

const props = defineProps<{ url: string; open?: boolean }>()
const emit = defineEmits<{ close: []; 'after:leave': [] }>()
const { t } = useI18n()
const loadout = ref<AssignedHolsterLoadout | null>(null)
const failed = ref(false)
const controller = new AbortController()

async function load() {
    failed.value = false
    try {
        const response = await axios.get<AssignedHolsterLoadout>(props.url, { signal: controller.signal })
        loadout.value = response.data
    } catch (error) {
        if (!axios.isCancel(error)) failed.value = true
    }
}

onMounted(load)
onUnmounted(() => controller.abort())
</script>

<template>
    <UModal :open="open" :title="t('holsters.contents')"
        :ui="{ content: 'sm:max-w-3xl rounded-2xl', body: 'min-w-0', footer: 'justify-end' }"
        @update:open="value => { if (!value) emit('close') }" @after:leave="emit('after:leave')">
        <template #body>
            <HolsterPairPanel v-if="loadout" :prepop="loadout.prepop" :refills="loadout.refills" />
            <div v-else-if="failed" role="alert" class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-muted">{{ t('holsters.load_failed') }}</span>
                <UButton color="neutral" variant="soft" icon="i-lucide-refresh-cw" :label="t('general.try_again')" @click="load" />
            </div>
            <div v-else aria-busy="true" class="space-y-3">
                <USkeleton class="h-5 w-44 rounded" />
                <USkeleton v-for="line in 3" :key="line" class="h-8 w-full rounded" />
            </div>
        </template>
        <template v-if="loadout?.guide_url" #footer>
            <UButton :href="loadout.guide_url" target="_blank" rel="noopener noreferrer" color="neutral" variant="soft"
                class="rounded-lg" trailing-icon="i-lucide-external-link" :label="t('holsters.read_guide')" />
        </template>
    </UModal>
</template>
