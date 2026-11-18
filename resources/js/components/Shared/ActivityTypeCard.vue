<script setup lang="ts">
withDefaults(defineProps<{
    title: string
    image?: string | null
    as?: 'article' | 'button'
}>(), { as: 'article' })
</script>

<template>
    <component :is="as" :type="as === 'button' ? 'button' : undefined"
        class="relative flex min-h-40 min-w-0 flex-col justify-end overflow-hidden border border-default bg-neutral-950 p-3 text-left sm:min-h-44 sm:p-4">
        <img v-if="image" :src="image" alt="" loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-t from-neutral-950 via-neutral-950/65 to-neutral-950/10" />
        <div class="relative space-y-2">
            <h3 class="text-sm font-semibold text-white [overflow-wrap:anywhere] sm:text-base">{{ title }}</h3>
            <slot />
        </div>
    </component>
</template>
