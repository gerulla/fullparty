<script setup lang="ts">
import type { CharacterCandidate } from '../../Types/CharacterOnboarding'
defineProps<{ character: CharacterCandidate }>()
</script>

<template>
    <div class="flex min-w-0 items-center gap-4 text-left">
        <UAvatar :src="character.avatar_url ?? undefined" :alt="character.name" size="2xl" class="shrink-0 bg-elevation-3 dark:bg-elevation-5" />
        <div class="min-w-0">
            <p class="font-semibold text-highlighted wrap-anywhere">{{ character.name }}</p>
            <p class="mt-1 text-sm text-muted">{{ [character.world, character.datacenter].filter(Boolean).join(' · ') }}</p>
        </div>
    </div>
</template>
