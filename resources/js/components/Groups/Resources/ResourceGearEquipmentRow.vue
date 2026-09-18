<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EquippedGearItem, GearNames } from '@/Types/XivGear'
import ResourceGearItemIcon from './ResourceGearItemIcon.vue'
import ResourceGearMateriaList from './ResourceGearMateriaList.vue'

const props = defineProps<{ item: EquippedGearItem }>()
const { t, locale } = useI18n()
const name = computed(() => props.item.names[locale.value as keyof GearNames] || props.item.names.en)
</script>

<template>
    <div class="gear-item-row">
        <ResourceGearItemIcon :item="item" :name="name" />
        <div class="min-w-0 flex-1">
            <span class="gear-slot">{{ t(`xivgear.slots.${item.slot}`) }}</span>
            <div class="gear-item-name">{{ name }}</div>
            <ResourceGearMateriaList :items="item.materia" />
            <div v-if="Object.keys(item.relicStats).length" class="gear-materia">{{ Object.entries(item.relicStats).map(([stat, value]) => `${t(`xivgear.stats.${stat}`)} +${value}`).join(' · ') }}</div>
        </div>
        <span class="gear-item-level">{{ item.itemLevel }}</span>
    </div>
</template>
