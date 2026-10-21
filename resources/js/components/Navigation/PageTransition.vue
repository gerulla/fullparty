<script setup lang="ts">
import { ref } from 'vue'
import { usePageTransition } from '@/composables/usePageTransition'

const canvas = ref<HTMLCanvasElement | null>(null)
usePageTransition(canvas)
</script>

<template>
    <Teleport to="body">
        <canvas ref="canvas" hidden aria-hidden="true" class="page-ripple" />
    </Teleport>
</template>

<style scoped>
.page-ripple {
    --ripple-1: var(--color-brand-500);
    --ripple-2: var(--color-brand-400);
    --ripple-3: var(--color-brand-300);
    --ripple-4: var(--color-brand-200);
    --ripple-5: var(--color-brand-100);
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 9999;
    pointer-events: none;
}

.dark .page-ripple {
    --ripple-1: var(--color-brand-500);
    --ripple-2: var(--color-brand-600);
    --ripple-3: var(--color-brand-700);
    --ripple-4: var(--color-brand-800);
    --ripple-5: var(--color-brand-950);
}

@media (prefers-reduced-motion: reduce) {
    .page-ripple { display: none !important; }
}
</style>
