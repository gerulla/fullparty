import type { IntegrationPermissionGroup } from '@/Types/IntegrationPermissions';

export function permissionGroupState(group: IntegrationPermissionGroup, selected: string[]): boolean | 'indeterminate' {
    const count = group.permissions.filter(permission => selected.includes(permission)).length;
    return count === 0 ? false : count === group.permissions.length ? true : 'indeterminate';
}

export function togglePermissionGroup(group: IntegrationPermissionGroup, selected: string[], enabled: boolean): string[] {
    const remaining = selected.filter(permission => !group.permissions.includes(permission));
    return enabled ? [...new Set([...remaining, ...group.permissions])] : remaining;
}
