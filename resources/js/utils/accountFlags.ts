import type { AccountFlag, AccountFlagSeverity } from '../Types/AccountFlags'

export const accountFlagPresentation = {
    info: { priority: 1, icon: 'i-lucide-info', foreground: 'text-(--ui-color-info-700) dark:text-info' },
    warning: { priority: 2, icon: 'i-lucide-triangle-alert', foreground: 'text-(--ui-color-warning-700) dark:text-warning' },
    error: { priority: 3, icon: 'i-lucide-circle-alert', foreground: 'text-(--ui-color-error-700) dark:text-error' },
} satisfies Record<AccountFlagSeverity, { priority: number; icon: string; foreground: string }>

export function sortAccountFlags(flags: readonly AccountFlag[]): AccountFlag[] {
    return [...flags].sort((a, b) => accountFlagPresentation[b.severity].priority - accountFlagPresentation[a.severity].priority)
}
