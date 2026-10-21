export type AccountFlagSeverity = 'info' | 'warning' | 'error'

export type AccountFlag = {
    id: string
    severity: AccountFlagSeverity
    title_key: string
    description_key: string
    parameters: Record<string, string | number>
    url: string
}

export type AccountFlagsPageProps = {
    auth?: { user?: { id: number } | null }
    account_flags?: AccountFlag[]
}
