import type { BozjaHolsterSummary } from '@/Types/Bozja'

export const holsterRoles = [
    { value: 'tank', label: 'general.roles.tank', icon: '/role-icons/tank.png' },
    { value: 'healer', label: 'general.roles.healer', icon: '/role-icons/healer.png' },
    { value: 'melee dps', label: 'general.roles.melee_dps', icon: '/role-icons/melee_dps.png' },
    { value: 'physical ranged dps', label: 'general.roles.physical_ranged_dps', icon: '/role-icons/physrange_dps.png' },
    { value: 'magic ranged dps', label: 'general.roles.magic_ranged_dps', icon: '/role-icons/magic_range_dps.png' },
] satisfies { value: NonNullable<BozjaHolsterSummary['role']>; label: string; icon: string }[]
