import { ref } from 'vue'
import { useOverlay } from '@nuxt/ui/composables'
import CharacterVerificationGuideModal from '@/components/Characters/CharacterVerificationGuideModal.vue'

export function useCharacterVerificationGuide() {
    const overlay = useOverlay()
    const verificationGuideOpen = ref(false)

    async function showVerificationGuide(event: MouseEvent) {
        if (verificationGuideOpen.value) return

        const returnFocusTo = event.currentTarget as HTMLElement
        verificationGuideOpen.value = true
        try {
            const modal = overlay.create(CharacterVerificationGuideModal, {
                destroyOnClose: true,
                props: { onRestoreFocus: () => { if (returnFocusTo.isConnected) returnFocusTo.focus() } },
            })
            await modal.open().result
        } finally {
            verificationGuideOpen.value = false
        }
    }

    return { showVerificationGuide, verificationGuideOpen }
}
