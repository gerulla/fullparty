<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GearItem, GearNames } from '@/Types/XivGear'
import { materiaBonus } from '@/utils/xivGearMateria'
import ResourceGearItemIcon from './ResourceGearItemIcon.vue'

const props = defineProps<{ items: GearItem[] }>()
const { t, locale } = useI18n()
const melds = computed(() => props.items.map(item => ({
    item,
    name: item.names[locale.value as keyof GearNames] || item.names.en,
    bonus: materiaBonus(item.id),
})))
</script>

<template>
    <div v-if="melds.length" class="gear-materia-list">
        <UTooltip v-for="(meld, index) in melds" :key="index" :text="meld.name">
            <span class="gear-meld" tabindex="0" :aria-label="meld.name">
                <ResourceGearItemIcon :item="meld.item" name="" class="gear-materia-icon" />
                <span v-if="meld.bonus">{{ meld.bonus.value }} {{ t(`xivgear.stat_abbreviations.${meld.bonus.stat}`) }}</span>
                <span v-else>{{ meld.name }}</span>
            </span>
        </UTooltip>
    </div>
    <span v-else class="gear-materia">{{ t('xivgear.no_materia') }}</span>
</template>
