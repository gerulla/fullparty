import { useOverlay } from '@nuxt/ui/composables'
import ReportModal from '@/components/Shared/Reports/ReportModal.vue'
import type { ReportTarget, ReportModalOptions } from '@/Types/Reports'

// Call open({ type: 'resource', id: resource.id }) from any existing button.
// The overlay is created on demand and removed when closed.
export function useReportModal() {
    const overlay = useOverlay()
    let active: Promise<boolean> | null = null
    return {
        async open(target: ReportTarget, options: ReportModalOptions = {}): Promise<boolean> {
            if (active) return active
            const modal = overlay.create(ReportModal, { destroyOnClose: true })
            active = modal.open({ target, ...options }).result.then(Boolean)
            try { return await active }
            finally { active = null }
        },
    }
}
