import type { AuditLogCategory } from '@/Types/Audit'

export const auditCategoryIcons: Record<AuditLogCategory, string> = {
    runs: 'i-lucide-calendar-days',
    members: 'i-lucide-users',
    resources: 'i-lucide-book-open',
    settings: 'i-lucide-settings',
}

const severityStyles = {
    info: { color: 'neutral', icon: 'i-lucide-info' },
    moderation_change: { color: 'brand', icon: 'i-lucide-shield' },
    severe_change: { color: 'warning', icon: 'i-lucide-triangle-alert' },
    critical: { color: 'error', icon: 'i-lucide-octagon-alert' },
} as const

export function auditSeverityStyle(severity: string) {
    return severityStyles[severity as keyof typeof severityStyles] ?? severityStyles.info
}
