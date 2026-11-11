import './availabilityTheme.css'

// Keep menus and calendar popovers in the same theme when they teleport outside the page.
const surface = 'availability-theme rounded-lg bg-elevation-1 dark:bg-elevation-3 ring-default shadow-lg'
const input = {
    base: 'rounded-lg bg-elevation-1 text-default placeholder:text-muted ring-default shadow-xs focus-visible:ring-primary aria-invalid:ring-error dark:bg-elevation-3 dark:shadow-none',
    leadingIcon: 'text-muted',
    trailingIcon: 'text-muted',
}
const menu = { content: surface, item: 'rounded-md' }

export const availabilityUi = {
    card: { root: 'overflow-hidden rounded-xl bg-elevation-1 ring-default shadow-sm dark:bg-elevation-2 dark:shadow-none' },
    button: { base: 'rounded-lg' },
    badge: { base: 'rounded-md' },
    input,
    select: { ...input, ...menu },
    selectMenu: { ...input, ...menu },
    popover: { content: surface },
    tooltip: { content: `${surface} text-default` },
    calendar: { cellTrigger: 'rounded-md' },
    checkbox: { base: 'rounded-sm' },
    modal: { content: 'rounded-xl bg-elevation-1 dark:bg-elevation-2 ring-default shadow-xl' },
    tabs: {
        list: 'rounded-lg bg-elevation-2 dark:bg-elevation-3',
        trigger: 'rounded-md',
        indicator: 'rounded-md',
    },
    skeleton: { base: 'rounded-md' },
}

export const availabilityThemeProps = {
    // Preserve the theme on dialogs that supply their own content sizing.
    modal: { class: 'availability-theme availability-dialog' },
}
