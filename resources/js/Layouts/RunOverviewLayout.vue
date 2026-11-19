<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import V2Layout from '@/Layouts/V2Layout.vue'
import WelcomeOnboardingModal from '@/components/Home/WelcomeOnboardingModal.vue'
import { useXivAuthConflictToast } from '@/composables/useXivAuthConflictToast'

const page = usePage<{ auth: { user: unknown } }>()
useXivAuthConflictToast()
</script>

<template>
    <V2Layout show-system-banner full-bleed>
        <slot />
        <WelcomeOnboardingModal v-if="page.props.auth?.user" />
    </V2Layout>
</template>
