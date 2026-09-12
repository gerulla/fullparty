import type { MemberNoteSeverity } from '../Types/Groups';

const presentations = {
    commendation: {
        color: 'success', icon: 'i-lucide-star',
        badgeClass: '',
        borderClass: 'border-r-2 border-r-success',
    },
    info: { color: 'info', icon: 'i-lucide-info', badgeClass: '', borderClass: 'border-r-2 border-r-info' },
    warning: { color: 'warning', icon: 'i-lucide-triangle-alert', badgeClass: '', borderClass: 'border-r-2 border-r-warning' },
    critical: { color: 'error', icon: 'i-lucide-octagon-alert', badgeClass: '', borderClass: 'border-r-2 border-r-error' },
} as const;

export const memberNotePresentation = (severity: MemberNoteSeverity) => presentations[severity];

export const memberNoteSeverityDots = (severities: readonly MemberNoteSeverity[]) => {
    const order: MemberNoteSeverity[] = ['info', 'commendation', 'warning', 'critical'];
    return order.filter(severity => severities.includes(severity))
        .map(severity => ({ severity, color: presentations[severity].color }));
};

export const memberNoteIndicator = (severity?: MemberNoteSeverity | null) => {
    if (severity === 'critical') return { color: 'error', dot: 'bg-error' } as const;
    if (severity === 'warning') return { color: 'warning', dot: 'bg-warning' } as const;
    if (severity === 'commendation') return { color: 'success', dot: 'bg-success' } as const;
    return null;
};
