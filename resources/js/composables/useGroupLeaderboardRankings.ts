import axios from 'axios'
import { onBeforeUnmount, reactive, watch } from 'vue'
import { route } from 'ziggy-js'
import type {
    GroupLeaderboardCategory, GroupLeaderboardCountEntry, GroupLeaderboardPayload,
    GroupLeaderboardRankingController,
} from '@/Types/GroupLeaderboard'

export function useGroupLeaderboardRankings(
    slug: () => string,
    payload: () => GroupLeaderboardPayload | null | undefined,
): GroupLeaderboardRankingController[] {
    const cache = new Map<string, GroupLeaderboardCountEntry[]>()
    const requests = new Map<GroupLeaderboardCategory, AbortController>()
    const categories: GroupLeaderboardCategory[] = ['overall', 'raid_leaders', 'hosts']

    async function load(board: GroupLeaderboardRankingController) {
        requests.get(board.category)?.abort()
        requests.delete(board.category)
        board.error = false
        if (!payload()) return

        const key = `${board.category}:${board.period}:${board.limit}`
        const cached = cache.get(key)
        if (cached) {
            board.entries = cached
            board.loading = false
            return
        }

        const request = new AbortController()
        requests.set(board.category, request)
        board.loading = true
        try {
            const response = await axios.get(route('groups.dashboard.leaderboard.ranking', slug()), {
                params: { category: board.category, period: board.period, limit: board.limit },
                signal: request.signal,
            })
            if (request.signal.aborted) return
            const entries = response.data.data as GroupLeaderboardCountEntry[]
            cache.set(key, entries)
            board.entries = entries
        } catch {
            if (!request.signal.aborted) board.error = true
        } finally {
            if (!request.signal.aborted) {
                board.loading = false
                requests.delete(board.category)
            }
        }
    }

    const boards = categories.map(category => {
        const board: GroupLeaderboardRankingController = reactive({
            category, period: 'all_time', limit: '10', entries: [], loading: false, error: false,
            setPeriod(period) { board.period = period; void load(board) },
            setLimit(limit) { board.limit = limit; void load(board) },
            retry() { void load(board) },
        })
        return board
    })

    function cancelRequests() {
        requests.forEach(request => request.abort())
        requests.clear()
    }

    watch([slug, payload], ([, data]) => {
        cancelRequests()
        cache.clear()
        if (!data) return
        for (const board of boards) {
            cache.set(`${board.category}:all_time:10`, data.rankings[board.category])
            void load(board)
        }
    }, { immediate: true })

    onBeforeUnmount(cancelRequests)
    return boards
}
