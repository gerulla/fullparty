import type { ComputedRef, InjectionKey } from 'vue'
import type { ResourceReaderSummary } from './GroupResources'

export type ResourceContentContext = {
    resources: ComputedRef<ResourceReaderSummary[]>
    href: (resource: ResourceReaderSummary) => string
}
export const resourceContentKey: InjectionKey<ResourceContentContext> = Symbol('resourceContent')
export type ResourceVideo = { provider: 'youtube' | 'twitch'; kind: 'video' | 'channel' | 'clip'; id: string; start: number; url: string }
