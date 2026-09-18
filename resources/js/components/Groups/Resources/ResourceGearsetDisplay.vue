<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GearNames, GearsetDisplay, GearsetSnapshot } from '@/Types/XivGear'
import { isXivGearUrl } from '@/utils/xivGear'
import ResourceGearItemIcon from './ResourceGearItemIcon.vue'
import ResourceGearMateriaList from './ResourceGearMateriaList.vue'
import ResourceGearEquipmentRow from './ResourceGearEquipmentRow.vue'
import '@/../css/resource-gearsets.css'

const props = defineProps<{ snapshot: GearsetSnapshot; display: GearsetDisplay }>()
const { t, locale } = useI18n()
const l = (key: string, values = {}) => t(`xivgear.${key}`, values)
const name = (names: GearNames) => names[locale.value as keyof GearNames] || names.en
const open = ref(false)
const selectedSlot = ref<string | null>(null)
const selected = computed(() => props.snapshot.items.find(item => item.slot === selectedSlot.value))
const armor = new Set(['Head', 'Body', 'Hand', 'Legs', 'Feet'])
const columns = computed(() => [
    { title: l('armor'), hand: props.snapshot.items.find(item => item.slot === 'Weapon'), items: props.snapshot.items.filter(item => armor.has(item.slot)), food: false },
    { title: l('accessories'), hand: props.snapshot.items.find(item => item.slot === 'OffHand'), items: props.snapshot.items.filter(item => !armor.has(item.slot) && !['Weapon', 'OffHand'].includes(item.slot)), food: true },
])
const imported = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(props.snapshot.importedAt)))
const shownStats = computed(() => Object.entries(props.snapshot.stats).filter(([, value]) => value > 0))
</script>

<template>
    <section class="gearset" :class="`gearset-${display}`" :aria-label="snapshot.name">
        <div class="gear-heading">
            <span v-if="display === 'expanded'" class="gear-eyebrow"><UIcon name="i-lucide-book-open" />{{ l('recommendation') }}</span>
            <div class="gear-title-row">
                <span v-if="display === 'compact'" class="gear-job">{{ snapshot.job }}</span>
                <div class="min-w-0 flex-1">
                    <div class="gear-title">{{ snapshot.name }}</div>
                    <p v-if="display === 'compact'" class="gear-subtitle">{{ snapshot.job }} · {{ l('ilevel', { value: snapshot.itemLevel }) }} · {{ l('level', { value: snapshot.level }) }}</p>
                </div>
                <UButton v-if="display === 'compact'" :icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" color="neutral" variant="ghost" size="xs" :label="l(open ? 'less' : 'details')" :aria-expanded="open" @click="open = !open" />
            </div>
            <p v-if="snapshot.description && (display === 'expanded' || open)" class="gear-description">{{ snapshot.description }}</p>
            <div v-if="display === 'expanded' || open" class="gear-metrics">
                <span><b>{{ snapshot.job }}</b> · {{ l('level', { value: snapshot.level }) }}</span>
                <span>{{ l('ilevel', { value: snapshot.itemLevel }) }}</span>
                <span v-if="snapshot.gcd" :title="l('gcd_help')">{{ l('gcd', { value: snapshot.gcd.toFixed(2) }) }}</span>
                <span>{{ l('party_bonus', { value: snapshot.partyBonus }) }}</span>
                <span v-if="snapshot.itemLevelSync">{{ l('sync', { value: snapshot.itemLevelSync }) }}</span>
            </div>
        </div>
        <template v-if="display === 'compact' && !open">
            <div class="gear-icon-strip">
                <button v-for="item in snapshot.items" :key="item.slot" type="button" :title="`${l(`slots.${item.slot}`)}: ${name(item.names)}`" :aria-label="`${l(`slots.${item.slot}`)}: ${name(item.names)}`" :aria-pressed="selectedSlot === item.slot" class="gear-icon-button" @click="selectedSlot = selectedSlot === item.slot ? null : item.slot">
                    <ResourceGearItemIcon :item="item" :name="name(item.names)" small />
                </button>
                <ResourceGearItemIcon v-if="snapshot.food" :item="snapshot.food" :name="name(snapshot.food.names)" small :title="`${l('food')}: ${name(snapshot.food.names)}`" />
            </div>
            <div v-if="selected" class="gear-inspector">
                <b>{{ name(selected.names) }}</b><span class="text-muted">{{ l('ilevel', { value: selected.itemLevel }) }}</span>
                <ResourceGearMateriaList :items="selected.materia" class="basis-full" />
                <span v-for="(value, stat) in selected.relicStats" :key="stat" class="text-xs text-muted">{{ l(`stats.${stat}`) }} +{{ value }}</span>
            </div>
        </template>
        <div v-if="display === 'expanded' || open" class="gear-columns">
            <div v-for="column in columns" :key="column.title" class="gear-column min-w-0">
                <div class="gear-section-title">{{ column.title }}</div>
                <ResourceGearEquipmentRow v-if="column.hand" :item="column.hand" />
                <div v-else aria-hidden="true" />
                <div class="min-w-0">
                    <ResourceGearEquipmentRow v-for="item in column.items" :key="item.slot" :item="item" />
                    <div v-if="column.food && snapshot.food" class="gear-food">
                        <ResourceGearItemIcon :item="snapshot.food" :name="name(snapshot.food.names)" />
                        <div><span class="gear-slot">{{ l('food') }}</span><div class="gear-item-name">{{ name(snapshot.food.names) }}</div></div>
                    </div>
                </div>
            </div>
        </div>
        <details v-if="display === 'expanded' || open" class="gear-stats">
            <summary>{{ l('attributes') }}</summary>
            <dl><div v-for="[stat, value] in shownStats" :key="stat"><dt>{{ l(`stats.${stat}`) }}</dt><dd>{{ value.toLocaleString(locale) }}</dd></div></dl>
            <p>{{ l('gcd_help') }}</p>
        </details>
        <div class="gear-footer"><span>{{ l('saved', { date: imported }) }}</span><a v-if="isXivGearUrl(snapshot.sourceUrl)" :href="snapshot.sourceUrl" target="_blank" rel="nofollow noopener noreferrer" @click.stop>{{ l('open_source') }}<UIcon name="i-lucide-arrow-up-right" /></a></div>
    </section>
</template>
