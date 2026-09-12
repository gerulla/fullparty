<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useElementSize } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { parseResourceVideo, resourceVideoPlayer } from '@/utils/resourceVideo'

const props = withDefaults(defineProps<{ url: string; title?: string; preview?: boolean }>(), { title: '', preview: false })
const { t } = useI18n()
const l = (key: string, params = {}) => t(`groups.resources.content.${key}`, params)
const root = ref<HTMLElement>()
const { width } = useElementSize(root)
const playing = ref(false)
const video = computed(() => parseResourceVideo(props.url))
const provider = computed(() => video.value?.provider === 'youtube' ? 'YouTube' : 'Twitch')
const title = computed(() => props.title || (video.value?.kind === 'channel' ? video.value.id : l('video_embed')))
const smallTwitch = computed(() => video.value?.provider === 'twitch' && width.value > 0 && width.value < 400)
const player = computed(() => video.value && typeof window !== 'undefined' ? resourceVideoPlayer(video.value, window.location.hostname) : '')
watch(() => props.url, () => { playing.value = false })
</script>

<template>
    <section v-if="video" ref="root" class="resource-video overflow-hidden border border-default bg-elevated" :class="{ 'resource-video-twitch': video.provider === 'twitch' }">
        <div class="resource-video-stage relative flex min-h-[200px] items-center justify-center overflow-hidden bg-[#111116]" :class="video.provider === 'twitch' && !smallTwitch ? 'min-h-[300px]' : ''">
            <iframe v-if="playing && !preview && !smallTwitch" :src="player" :title="title" class="absolute inset-0 size-full border-0" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" />
            <template v-else>
                <img v-if="video.provider === 'youtube'" :src="`https://i.ytimg.com/vi/${video.id}/hqdefault.jpg`" alt="" loading="lazy" class="absolute inset-0 size-full object-cover opacity-60" />
                <div class="resource-video-shade absolute inset-0" />
                <div class="relative flex flex-col items-center gap-4 p-6 text-center text-white">
                    <span class="text-xs font-semibold uppercase tracking-[.2em] text-white/70">{{ provider }}</span>
                    <span v-if="preview" class="flex size-16 items-center justify-center border border-white/30 bg-white/10"><UIcon name="i-lucide-play" class="size-7" /></span>
                    <a v-else-if="smallTwitch" :href="video.url" target="_blank" rel="noopener noreferrer" class="resource-video-action flex items-center gap-2 border border-white/40 bg-white/10 px-5 py-3 font-medium"><UIcon name="i-lucide-external-link" class="size-5" />{{ l('watch_on', { provider }) }}</a>
                    <button v-else type="button" class="resource-video-action flex size-16 cursor-pointer items-center justify-center border border-white/40 bg-white/10 transition hover:scale-105 hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white" :aria-label="l('play_video', { title })" @click="playing = true"><UIcon name="i-lucide-play" class="ml-1 size-7" /></button>
                    <span class="max-w-lg text-lg font-semibold leading-snug">{{ title }}</span>
                </div>
            </template>
        </div>
        <footer class="flex items-center justify-between gap-3 border-t border-default px-4 py-3 text-sm">
            <span class="flex min-w-0 items-center gap-2 font-medium text-highlighted"><UIcon :name="video.provider === 'youtube' ? 'i-lucide-youtube' : 'i-lucide-twitch'" class="size-4 shrink-0" /><span class="truncate">{{ title }}</span></span>
            <a v-if="!preview" :href="video.url" target="_blank" rel="noopener noreferrer" class="resource-video-source flex shrink-0 items-center gap-1.5 text-xs text-muted hover:text-primary">{{ provider }}<UIcon name="i-lucide-arrow-up-right" class="size-3.5" /></a>
        </footer>
    </section>
</template>

<style scoped>
.resource-video-stage { aspect-ratio: 16 / 9; }
.resource-video-shade { background: linear-gradient(180deg, #09090f22, #09090fcc); }
.resource-video-twitch .resource-video-shade { background: radial-gradient(ellipse at 80% 0%, #9146ff66, transparent 65%), linear-gradient(135deg, #24143c, #111116); }
.resource-video :deep(img) { margin: 0; height: 100%; }
.resource-video a { text-decoration: none; }
.resource-video-action { color: white; }
.resource-video-source { color: var(--ui-text-muted); }
</style>
