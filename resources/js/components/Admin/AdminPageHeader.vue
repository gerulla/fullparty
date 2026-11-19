<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
    <header data-page-header class="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div class="min-w-0 sm:flex-1 sm:basis-56">
            <h1 class="text-2xl leading-tight font-semibold tracking-tight wrap-anywhere text-highlighted lg:text-3xl">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1.5 max-w-prose text-sm leading-relaxed text-muted">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.default" class="flex max-w-full flex-wrap items-center gap-2"><slot /></div>
    </header>
</template>
