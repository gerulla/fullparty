<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { useOverlay } from '@nuxt/ui/composables'
import { route } from 'ziggy-js'
import AssignedHolsterModal from './AssignedHolsterModal.vue'

const props = defineProps<{ fieldId: number; label: string }>()
const page = usePage<{ group: { slug: string }; activity: { id: number } }>()
const overlay = useOverlay()

function showLoadout() {
    const url = route('groups.activities.holster-loadout', {
        group: page.props.group.slug, activity: page.props.activity.id, field: props.fieldId,
    }, false)
    overlay.create(AssignedHolsterModal, { destroyOnClose: true }).open({ url })
}
</script>

<template>
    <button type="button" aria-haspopup="dialog"
        class="min-w-0 cursor-pointer rounded-sm text-start text-brand-700 underline decoration-current/50 underline-offset-2 hover:decoration-current focus-visible:outline-2 dark:text-brand-300"
        @click.stop="showLoadout">{{ label }}</button>
</template>
