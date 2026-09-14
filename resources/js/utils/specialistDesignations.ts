import type { ContextMenuItem } from '@nuxt/ui';
import type { ActivitySlot, SlotDesignation } from '../Types/ActivityRoster';

const definitions = [
    { key: 'duelist', column: 'is_duelist', icon: 'i-lucide-sword', color: 'error',
        menuClass: 'text-red-500',
        iconClass: 'text-red-500 drop-shadow-[0_4px_10px_rgba(239,68,68,0.85)]', badgeClass: 'bg-red-500/10 text-red-500' },
    { key: 'trapper', column: 'is_trapper', icon: 'i-lucide-circle-gauge', color: 'warning',
        menuClass: 'text-orange-500',
        iconClass: 'text-orange-500 drop-shadow-[0_4px_10px_rgba(249,115,22,0.85)]', badgeClass: 'bg-orange-500/10 text-orange-500' },
    { key: 'darter', column: 'is_darter', icon: 'gravity-ui:target-dart', color: 'success',
        menuClass: 'text-green-600',
        iconClass: 'text-green-600 drop-shadow-[0_4px_10px_rgba(22,163,74,0.85)]', badgeClass: 'bg-green-600/10 text-green-600' },
] as const;

export function availableSpecialistDesignations(designations: SlotDesignation[]) {
    return definitions.filter(definition => designations.includes(definition.key));
}

export function specialistDesignationMarkers(slot: ActivitySlot, hasRightCornerMarker = false) {
    const positions = ['-right-2 -top-2', 'right-5 -top-2', 'right-12 -top-2', 'right-19 -top-2'];
    const offset = hasRightCornerMarker ? 1 : 0;
    return definitions.filter(definition => slot[definition.column]).map((definition, index) => ({
        ...definition,
        iconClass: `${definition.iconClass} scale-77`,
        labelKey: `groups.activities.management.roster.${definition.key}_badge`,
        wrapperClass: positions[index + offset],
        rotationClass: '',
    }));
}

export function specialistDesignationActions(
    slot: ActivitySlot,
    t: (key: string) => string,
    onSelect: (slotId: number, designation: SlotDesignation) => void,
    disabled: boolean,
): ContextMenuItem[] {
    return availableSpecialistDesignations(slot.available_designations ?? []).map(definition => ({
        label: t(`groups.activities.management.roster.${slot[definition.column] ? 'unmark' : 'mark'}_${definition.key}_action`),
        icon: definition.icon,
        color: definition.color,
        ui: { itemLabel: definition.menuClass, itemLeadingIcon: definition.menuClass },
        disabled: disabled || slot.is_bench || slot.is_fill_in || !slot.assigned_character_id,
        onSelect: () => onSelect(slot.id, definition.key),
    }));
}
