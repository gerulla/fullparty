import './adminTheme.css'

const input = {
    base: 'rounded-lg bg-elevation-1 text-default placeholder:text-muted shadow-xs ring-accented focus-visible:ring-primary dark:bg-elevation-3 dark:shadow-none',
    leadingIcon: 'text-muted',
    trailingIcon: 'text-muted',
}
const floatingSurface = 'admin-theme rounded-xl bg-elevation-1 shadow-lg ring-default dark:bg-elevation-4'
const menu = { content: floatingSurface, item: 'rounded-md' }

export const adminUi = {
    card: { root: 'min-w-0 rounded-xl bg-elevation-1 shadow-sm ring-default dark:bg-elevation-3' },
    alert: { root: 'rounded-lg' },
    button: { base: 'rounded-lg' },
    badge: { base: 'rounded-md' },
    input,
    inputNumber: input,
    textarea: input,
    inputTags: { ...input, item: 'rounded-md' },
    select: { ...input, ...menu },
    selectMenu: { ...input, ...menu },
    checkbox: { base: 'rounded-sm' },
    dropdownMenu: menu,
    contextMenu: menu,
    popover: { content: floatingSurface },
    tooltip: { content: `${floatingSurface} text-default` },
    modal: { content: 'rounded-2xl bg-elevation-1 shadow-xl ring-default dark:bg-elevation-3' },
    tabs: {
        list: 'rounded-xl bg-elevation-2 dark:bg-elevation-4',
        trigger: 'rounded-lg',
        indicator: 'rounded-lg',
    },
    skeleton: { base: 'rounded-md' },
}

// Dialogs can supply their own width/UI slots. Keep the palette on the teleported dialog itself.
export const adminUiProps = { modal: { class: 'admin-theme admin-dialog' } }
