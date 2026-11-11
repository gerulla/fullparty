import './resourceWorkspaceTheme.css'

// Scoped Nuxt UI defaults also reach the editor's teleported menus and dialogs.
const surface = 'resource-workspace-theme bg-elevation-1 dark:bg-elevation-3 ring-default shadow-lg'

export const resourceWorkspaceInputUi = {
    base: 'rounded-lg bg-elevation-1 text-default placeholder:text-muted ring-default shadow-xs focus-visible:ring-primary aria-invalid:ring-error dark:bg-elevation-3 dark:shadow-none',
    leadingIcon: 'text-muted',
    trailingIcon: 'text-muted',
}

export const resourceWorkspaceMenuUi = {
    content: `${surface} rounded-lg`,
    item: 'rounded-md',
}

export const resourceWorkspaceUi = {
    button: { base: 'rounded-lg' },
    badge: { base: 'rounded-md' },
    alert: { root: 'rounded-lg' },
    input: resourceWorkspaceInputUi,
    inputNumber: resourceWorkspaceInputUi,
    textarea: resourceWorkspaceInputUi,
    inputTags: {
        ...resourceWorkspaceInputUi,
        base: `${resourceWorkspaceInputUi.base} focus-within:ring-primary has-[[aria-invalid=true]]:ring-error`,
        item: 'rounded-md',
    },
    select: { ...resourceWorkspaceInputUi, ...resourceWorkspaceMenuUi },
    selectMenu: { ...resourceWorkspaceInputUi, ...resourceWorkspaceMenuUi },
    dropdownMenu: resourceWorkspaceMenuUi,
    contextMenu: resourceWorkspaceMenuUi,
    popover: { content: `${surface} rounded-lg` },
    tooltip: { content: `${surface} rounded-md text-default` },
    modal: { content: 'rounded-xl bg-elevation-1 dark:bg-elevation-2 ring-default shadow-xl' },
    tabs: { trigger: 'rounded-lg', indicator: 'rounded-lg' },
    progress: { base: 'rounded-full', indicator: 'rounded-full' },
}

export const resourceWorkspaceProps = {
    // Keep the theme on modal content even when a dialog supplies its own size/UI.
    modal: { class: 'resource-workspace-theme resource-workspace-dialog' },
}
