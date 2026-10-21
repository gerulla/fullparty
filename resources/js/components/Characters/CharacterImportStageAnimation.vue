<script setup lang="ts">
import { computed } from 'vue'
import type { CharacterImportStage } from '../../Types/CharacterOnboarding'
import { CHARACTER_IMPORT_INTRO_MS } from '../../utils/characterImportTiming'
import { characterImportIllustrations as illustrations } from '../../utils/characterImportArtwork'

const props = defineProps<{ stage: CharacterImportStage }>()
const fanIcons = computed(() => props.stage === 'classes'
    ? [
        { position: 'left', src: illustrations.healer },
        { position: 'center', src: illustrations.tank },
        { position: 'right', src: illustrations.dps },
    ]
    : [
        { position: 'left', src: illustrations.thief },
        { position: 'center', src: illustrations.berserker },
        { position: 'right', src: illustrations.ranger },
    ])
</script>

<template>
    <div class="import-animation grayscale" :style="{ '--intro-duration': `${CHARACTER_IMPORT_INTRO_MS}ms` }" aria-hidden="true">
        <div class="idle-pulse">
            <div v-if="stage === 'profile'" class="icon-stack">
                <img :src="illustrations.portrait" alt="" draggable="false" class="hero-art profile-circle" />
                <img :src="illustrations.profile" alt="" draggable="false" class="hero-art profile-card" />
            </div>

            <div v-else-if="stage === 'classes' || stage === 'phantom_jobs'" class="icon-fan">
                <img
                    v-for="icon in fanIcons"
                    :key="icon.position"
                    :src="icon.src"
                    alt=""
                    width="96"
                    height="96"
                    draggable="false"
                    class="fan-piece"
                    :class="`fan-${icon.position}`"
                />
            </div>

            <div v-else-if="stage === 'achievements'" class="trophy-entrance">
                <div class="trophy-spin">
                    <img :src="illustrations.achievements" alt="" draggable="false" class="hero-art" />
                </div>
            </div>

            <div v-else-if="stage === 'fflogs'" class="crossed-swords">
                <img
                    v-for="side in ['left', 'right']"
                    :key="side"
                    :src="illustrations.sword"
                    alt=""
                    draggable="false"
                    class="sword"
                    :class="`sword-${side}`"
                />
            </div>
            <svg v-else class="saving-document saving-entrance" viewBox="0 0 120 120" fill="none" focusable="false">
                <path d="M31 10H71L93 32V103Q93 110 86 110H31Q24 110 24 103V17Q24 10 31 10Z" fill="#f5f5f5" stroke="#404040" stroke-width="3" stroke-linejoin="round" />
                <path d="M71 10V27Q71 32 76 32H93" fill="#d4d4d4" stroke="#404040" stroke-width="3" stroke-linejoin="round" />
                <path
                    v-for="(end, index) in [76, 81, 73, 64]"
                    :key="index"
                    class="document-line"
                    :d="`M37 ${49 + index * 13}H${end}`"
                    pathLength="1"
                    stroke="#737373"
                    stroke-width="4"
                    stroke-linecap="round"
                    :style="{ '--line-delay': `${400 + index * 250}ms` }"
                />
            </svg>
        </div>
    </div>
</template>

<style scoped>
.import-animation {
    position: relative;
    display: grid;
    width: 16rem;
    max-width: 100%;
    height: 8rem;
    place-items: center;
}

.idle-pulse {
    display: grid;
    width: 100%;
    height: 100%;
    place-items: center;
    animation: idle-pulse 1.8s ease-in-out var(--intro-duration) infinite;
}

.hero-art {
    display: block;
    width: 7.5rem;
    height: 7.5rem;
    object-fit: contain;
}

.icon-stack {
    display: grid;
    place-items: center;
}

.icon-stack > * {
    grid-area: 1 / 1;
}

.profile-circle {
    opacity: 0;
    animation: profile-circle var(--intro-duration) both;
}

.profile-card {
    animation: profile-card var(--intro-duration) both;
}

.icon-fan {
    position: relative;
    width: 100%;
    height: 100%;
}

.fan-piece {
    --fan-x: 0rem;
    --fan-y: 0rem;
    --fan-tilt: 0deg;
    position: absolute;
    top: 50%;
    left: 50%;
    width: 6rem;
    height: 6rem;
    object-fit: contain;
    transform: translate(calc(-50% + var(--fan-x)), calc(-50% + var(--fan-y))) rotate(var(--fan-tilt));
    animation: fan-entrance var(--intro-duration) cubic-bezier(0.22, 1, 0.36, 1) both;
}

.fan-left {
    --fan-x: -4.25rem;
    --fan-y: 0.5rem;
    --fan-tilt: -16deg;
}

.fan-right {
    --fan-x: 4.25rem;
    --fan-y: 0.5rem;
    --fan-tilt: 16deg;
}

.fan-center {
    z-index: 1;
    width: 7.2rem;
    height: 7.2rem;
}

.trophy-entrance {
    perspective: 32rem;
    animation: trophy-entrance var(--intro-duration) both;
}

.trophy-spin {
    animation: trophy-spin 1s ease-in-out var(--intro-duration) infinite;
}

.crossed-swords {
    display: grid;
    place-items: center;
    width: 8rem;
    max-width: 100%;
    height: 8rem;
}

.sword {
    grid-area: 1 / 1;
    display: block;
    width: 8.5rem;
    height: 8.5rem;
    object-fit: contain;
    transform: rotate(calc(var(--sword-direction) * 42deg));
    animation: swords-cross var(--intro-duration) cubic-bezier(0.22, 1, 0.36, 1) both;
}

.sword-left {
    --sword-direction: -1;
}

.sword-right {
    --sword-direction: 1;
}

.saving-document {
    width: 7.5rem;
    height: 7.5rem;
}

.document-line {
    stroke-dasharray: 1;
    animation: document-write 300ms ease-out var(--line-delay) both;
}

.saving-entrance {
    animation: saving-entrance var(--intro-duration) ease-out both;
}

@keyframes idle-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

@keyframes profile-circle {
    0% { opacity: 0; transform: scale(0) rotate(0); }
    33%, 66% { opacity: 1; transform: scale(1) rotate(0); }
    100% { opacity: 0; transform: scale(0.35) rotate(180deg); }
}

@keyframes profile-card {
    0%, 66% { opacity: 0; transform: scale(0.35) rotate(-180deg); }
    100% { opacity: 1; transform: scale(1) rotate(0); }
}

@keyframes fan-entrance {
    0% { opacity: 0; transform: translate(-50%, -50%) scale(0); }
    25% { opacity: 1; transform: translate(-50%, -50%) scale(0.6); }
    75% { opacity: 1; transform: translate(calc(-50% + var(--fan-x)), calc(-50% + var(--fan-y))) rotate(var(--fan-tilt)) scale(1.04); }
    100% { opacity: 1; transform: translate(calc(-50% + var(--fan-x)), calc(-50% + var(--fan-y))) rotate(var(--fan-tilt)) scale(1); }
}

@keyframes trophy-entrance {
    0% { opacity: 0; transform: scale(0.1); }
    23% { opacity: 1; transform: scale(1.3); }
    60% { opacity: 1; transform: scale(0.94); }
    100% { opacity: 1; transform: scale(1); }
}

@keyframes trophy-spin {
    0% { transform: rotateY(0deg); }
    35%, 100% { transform: rotateY(360deg); }
}

@keyframes swords-cross {
    0% { opacity: 0; transform: translateX(calc(var(--sword-direction) * 3rem)) rotate(calc(var(--sword-direction) * 70deg)) scale(0.8); }
    20% { opacity: 1; }
    65% { opacity: 1; transform: translateX(0) rotate(calc(var(--sword-direction) * 46deg)) scale(1.04); }
    100% { opacity: 1; transform: translateX(0) rotate(calc(var(--sword-direction) * 42deg)) scale(1); }
}

@keyframes document-write {
    from { opacity: 0; stroke-dashoffset: 1; }
    to { opacity: 1; stroke-dashoffset: 0; }
}

@keyframes saving-entrance {
    0% { opacity: 0; transform: translateY(0.5rem) scale(0.5); }
    60% { opacity: 1; transform: translateY(0) scale(1.05); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}

@media (prefers-reduced-motion: reduce) {
    .import-animation *,
    .import-animation *::before,
    .import-animation *::after {
        animation: none;
    }
}
</style>
