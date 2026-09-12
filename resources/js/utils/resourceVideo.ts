import type { ResourceVideo } from '../Types/ResourceContent'

export function parseResourceVideo(input: string): ResourceVideo | null {
    if (input.length > 2048 || /[\x00-\x20\x7f\\]/.test(input)) return null
    let url: URL
    try { url = new URL(input) } catch { return null }
    if (url.protocol !== 'https:' || url.username || url.password || url.port || /^https:\/\/[^/]*:\d+/i.test(input)) return null
    const path = url.pathname.replace(/^\/+|\/+$/g, '')
    const time = url.searchParams.get('start') ?? url.searchParams.get('t') ?? '0'
    const match = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/.exec(time)
    if (!match) return null
    const start = Number(match[1] ?? 0) * 3600 + Number(match[2] ?? 0) * 60 + Number(match[3] ?? 0)
    if (start > 864000) return null
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'].includes(url.hostname)) {
        const id = url.hostname === 'youtu.be' ? path : path === 'watch' ? url.searchParams.get('v') ?? '' : /^(?:embed|shorts|live)\/([^/]+)$/.exec(path)?.[1] ?? ''
        return /^[A-Za-z0-9_-]{11}$/.test(id) ? { provider: 'youtube', kind: 'video', id, start, url: `https://www.youtube.com/watch?v=${id}${start ? `&start=${start}` : ''}` } : null
    }
    if (url.hostname === 'clips.twitch.tv' && /^[A-Za-z0-9_-]{1,100}$/.test(path)) return { provider: 'twitch', kind: 'clip', id: path, start: 0, url: `https://clips.twitch.tv/${path}` }
    if (['twitch.tv', 'www.twitch.tv', 'm.twitch.tv'].includes(url.hostname)) {
        const video = /^videos\/([0-9]{1,20})$/.exec(path)
        if (video) return { provider: 'twitch', kind: 'video', id: video[1], start, url: `https://www.twitch.tv/videos/${video[1]}${start ? `?t=${start}s` : ''}` }
        const clip = /^[A-Za-z0-9_]+\/clip\/([A-Za-z0-9_-]{1,100})$/.exec(path)
        if (clip) return { provider: 'twitch', kind: 'clip', id: clip[1], start: 0, url: `https://clips.twitch.tv/${clip[1]}` }
        if (/^[A-Za-z0-9_]{1,25}$/.test(path) && !['directory', 'downloads', 'settings', 'subscriptions', 'inventory', 'wallet', 'search'].includes(path.toLowerCase())) return { provider: 'twitch', kind: 'channel', id: path.toLowerCase(), start: 0, url: `https://www.twitch.tv/${path.toLowerCase()}` }
    }
    return null
}

export function resourceVideoPlayer(video: ResourceVideo, hostname: string): string {
    if (video.provider === 'youtube') return `https://www.youtube-nocookie.com/embed/${video.id}?autoplay=1&playsinline=1&rel=0&start=${video.start}`
    const params = new URLSearchParams({ parent: hostname, autoplay: 'true' })
    if (video.kind === 'clip') { params.set('clip', video.id); return `https://clips.twitch.tv/embed?${params}` }
    params.set(video.kind === 'channel' ? 'channel' : 'video', video.kind === 'channel' ? video.id : `v${video.id}`)
    if (video.start) params.set('time', `${video.start}s`)
    return `https://player.twitch.tv/?${params}`
}
