import type { QueueApplicationAnswerDisplayItem } from '../Types/ActivityQueue';

const roles = [
    { role: 'tank', key: 'tank', icon: 'tank', border: 'ring-blue-500/70' },
    { role: 'healer', key: 'healer', icon: 'healer', border: 'ring-green-500/70' },
    { role: 'melee dps', key: 'melee', icon: 'melee_dps', border: 'ring-red-500/70' },
    { role: 'physical ranged dps', key: 'physical_ranged', icon: 'physrange_dps', border: 'ring-yellow-500/70' },
    { role: 'magic ranged dps', key: 'magic_ranged', icon: 'magic_range_dps', border: 'ring-purple-500/70' },
] as const;

export function preferredClassChips(items: QueueApplicationAnswerDisplayItem[], completeRoles: string[] = []) {
    const chips = roles.flatMap(({ role, key, icon, border }) => {
        const selected = items.filter(item => item.role === role);
        if (!selected.length) return [];

        if (completeRoles.includes(role)) {
            return [{
                key: `omni-${key}`, label: '', omniKey: key as string | null,
                icon: `/role-icons/${icon}.png`, border,
                classNames: selected.map(item => item.label),
            }];
        }

        return selected.map(item => ({
            key: `${role}-${item.label}`, label: item.label, omniKey: null as string | null,
            icon: item.flat_icon_url || item.icon_url || undefined, border,
            classNames: [item.label],
        }));
    });

    // Keep custom or "Any" options visible without treating them as a complete role.
    return [...chips, ...items.filter(item => !roles.some(({ role }) => role === item.role)).map(item => ({
        key: `other-${item.label}`, label: item.label, omniKey: null as string | null,
        icon: item.flat_icon_url || item.icon_url || undefined, border: 'ring-default',
        classNames: [item.label],
    }))];
}
