<script setup lang="ts">
import { computed } from 'vue'
import type { RoleSelectOption } from '@/Types/GroupDiscord'

const props = defineProps<{ modelValue: string; items: RoleSelectOption[]; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const selected = computed(() => props.items.find(item => item.value === props.modelValue))
</script>

<template>
    <USelectMenu :model-value="modelValue" :items="items" :disabled="disabled" value-key="value" size="lg"
        class="w-full min-w-0" :ui="{ base: 'rounded-lg', content: 'max-w-[calc(100vw-2rem)]' }"
        @update:model-value="emit('update:modelValue', String($event))">
        <template #default="{ ui }">
            <span v-if="selected" data-slot="value" :class="ui.value({ class: 'min-w-0 truncate' })">
                <span class="block truncate font-medium" :style="selected.roleTextStyle ?? undefined">{{ selected.label }}</span>
            </span>
        </template>
        <template #item-label="{ item }">
            <span class="block min-w-0 break-words font-medium whitespace-normal" :style="(item as RoleSelectOption).roleTextStyle ?? undefined">
                {{ (item as RoleSelectOption).label }}
            </span>
        </template>
    </USelectMenu>
</template>
