import { parseDate } from '@internationalized/date'
import { runSearchResultSorts } from '../Types/RunDiscovery'
import type { RunDiscoveryLookups, RunSearchFilters, RunSearchLookups, RunSearchState } from '../Types/RunDiscovery'

export function emptyRunSearchFilters(): RunSearchFilters {
    return {
        saved_only: false, beginner_friendly: false, application_status: null,
        role_category: 'any', time_of_day: 'any', region: '', datacenter: '',
        language: '', run_style: '', intensity: null,
    }
}

export function emptyRunSearch(): RunSearchState {
    return {
        activity: '', group: '', sort: 'starting_soonest',
        dates: { mode: 'dates', from: null, to: null, tolerance: 0, days: 'any' },
        filters: emptyRunSearchFilters(),
    }
}

export function copyRunSearch(state: RunSearchState): RunSearchState {
    return { ...state, dates: { ...state.dates }, filters: { ...state.filters } }
}

function validDate(value: string | null): string | null {
    if (!value) return null
    try { return parseDate(value).toString() } catch { return null }
}

function activeFilters(filters: RunSearchFilters) {
    return Object.entries(filters).filter(([, value]) => value && value !== 'any')
}

export function activeRunFilterCount(filters: RunSearchFilters): number {
    return activeFilters(filters).length
}

export function hasRunSearch(params: URLSearchParams, state: RunSearchState): boolean {
    return ['search', 'activity_type', 'activity_type_id', 'group', 'date_range', 'sort'].some(key => params.has(key))
        || activeRunFilterCount(state.filters) > 0
}

export function readRunSearchTimezone(params: URLSearchParams, fallback: string): string {
    const timezone = params.get('timezone')
    if (!timezone || /^[+-]/.test(timezone)) return fallback
    try {
        new Intl.DateTimeFormat('en', { timeZone: timezone })
        return timezone
    } catch {
        return fallback
    }
}

function choice<const T extends string>(value: string | null, options: readonly T[]): T | null {
    return options.find(option => option === value) ?? null
}

export function readRunSearch(params: URLSearchParams, lookups: RunSearchLookups & Partial<RunDiscoveryLookups>): RunSearchState {
    const state = emptyRunSearch()
    state.sort = choice(params.get('sort'), runSearchResultSorts) ?? state.sort
    const id = params.get('activity_type_id')
    state.activity = (id !== null
        ? lookups.activity_types.find(item => String(item.id) === id)
        : lookups.activity_types.find(item => item.value === params.get('activity_type')))?.value ?? ''
    state.group = lookups.groups.find(item => item.value === params.get('group'))?.value ?? ''
    state.dates.mode = params.get('date_range') === 'flexible' ? 'flexible' : 'dates'
    state.dates.from = validDate(params.get('date_from'))
    state.dates.to = validDate(params.get('date_to'))
    if (!state.dates.from || (state.dates.to && state.dates.to < state.dates.from)) state.dates.to = null
    const tolerance = Number(params.get('date_flex_days'))
    state.dates.tolerance = [0, 1, 2, 3, 7, 14].includes(tolerance) ? tolerance : 0
    const days = params.get('flexible_days')
    state.dates.days = days === 'weekend' || days === 'weekday' ? days : 'any'
    const region = lookups.regions?.find(option => option.value === params.get('region'))?.value ?? ''
    state.filters = {
        saved_only: ['1', 'true'].includes(params.get('saved_only') ?? ''),
        beginner_friendly: ['1', 'true'].includes(params.get('beginner_friendly') ?? ''),
        application_status: choice(params.get('application_status'), ['applications_open', 'direct_join']),
        role_category: choice(params.get('role_category'), ['tank', 'healer', 'dps']) ?? 'any',
        time_of_day: choice(params.get('time_of_day'), ['morning', 'afternoon', 'evening', 'night']) ?? 'any',
        region,
        datacenter: lookups.datacenters?.find(option => option.value === params.get('datacenter') && (!region || option.region === region))?.value ?? '',
        language: lookups.languages?.find(option => option.value === params.get('language'))?.value ?? '',
        run_style: choice(params.get('run_style'), lookups.run_styles ?? []) ?? '',
        intensity: choice(params.get('intensity'), lookups.intensities ?? []),
    }
    return state
}

export function runSearchParams(state: RunSearchState, timezone: string, page = 1): URLSearchParams {
    const params = new URLSearchParams({ search: '1', timezone, page: String(page) })
    if (state.activity) params.set('activity_type', state.activity)
    if (state.group) params.set('group', state.group)
    if (state.sort !== 'starting_soonest') params.set('sort', state.sort)
    for (const [key, value] of activeFilters(state.filters)) {
        params.set(key, value === true ? '1' : String(value))
    }
    if (state.dates.mode === 'flexible') {
        params.set('date_range', 'flexible')
        params.set('flexible_days', state.dates.days)
    } else if (state.dates.from) {
        params.set('date_range', state.dates.to ? 'custom_range' : 'custom_day')
        params.set('date_from', state.dates.from)
        if (state.dates.to) params.set('date_to', state.dates.to)
        params.set('date_flex_days', String(state.dates.tolerance))
    } else {
        params.set('date_range', 'upcoming')
    }
    return params
}
