export type RunApplicationStatus = 'pending' | 'approved' | 'on_bench' | 'declined' | 'cancelled' | 'withdrawn'
export type RunParticipationStatus = RunApplicationStatus | 'assigned' | 'checked_in' | 'late' | 'missing' | 'filled_in'
