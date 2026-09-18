<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { GearItem } from '@/Types/XivGear'

const props = defineProps<{ item: GearItem; name: string; small?: boolean }>()
const failed = ref(false)
const url = computed(() => props.item.icon && !failed.value ? `/gearset-icons/${props.item.icon}.png` : null)
watch(() => props.item.icon, () => { failed.value = false })
</script>

<template>
    <span class="gear-icon" :class="{ 'gear-icon-small': small }">
        <img v-if="url" :src="url" :alt="name" loading="lazy" @error="failed = true">
        <UIcon v-else name="i-lucide-gem" class="size-5 text-muted" :aria-label="name" />
    </span>
</template>
