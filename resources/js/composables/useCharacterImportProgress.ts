import { onBeforeUnmount, ref } from 'vue'
import type { CharacterImportProgress } from '@/Types/CharacterOnboarding'
import { CHARACTER_IMPORT_MIN_STAGE_MS } from '@/utils/characterImportTiming'

export function useCharacterImportProgress() {
    const progress = ref<CharacterImportProgress>({ stage: 'profile', percent: 0 })
    let lastStageAt = 0
    let generation = 0
    let timer: ReturnType<typeof setTimeout> | undefined
    let releasePause: (() => void) | undefined

    function cancel() {
        generation++
        clearTimeout(timer)
        releasePause?.()
        releasePause = undefined
    }

    function reset() {
        cancel()
        progress.value = { stage: 'profile', percent: 0 }
        lastStageAt = Date.now()
    }

    function pause(milliseconds: number) {
        return new Promise<void>(resolve => {
            releasePause = resolve
            timer = setTimeout(() => { releasePause = undefined; resolve() }, milliseconds)
        })
    }

    async function update(next: CharacterImportProgress) {
        const current = generation
        if (next.stage !== progress.value.stage) {
            // Pace the display only; backend work continues independently.
            await pause(Math.max(0, CHARACTER_IMPORT_MIN_STAGE_MS - (Date.now() - lastStageAt)))
            if (current !== generation) return
            lastStageAt = Date.now()
        }
        progress.value = { ...next, percent: Math.max(progress.value.percent, next.percent) }
    }

    async function finish() {
        const current = generation
        await update({ stage: 'saving', percent: 100 })
        if (current !== generation) return
        await pause(Math.max(0, CHARACTER_IMPORT_MIN_STAGE_MS - (Date.now() - lastStageAt)))
    }

    async function preview(onComplete: () => void) {
        reset()
        const current = generation
        const sequence: CharacterImportProgress[] = [
            { stage: 'classes', percent: 25 },
            { stage: 'phantom_jobs', percent: 45 },
            { stage: 'fflogs', percent: 60 },
            { stage: 'achievements', percent: 80 },
            { stage: 'saving', percent: 95 },
        ]
        for (const next of sequence) {
            await pause(3000)
            if (current !== generation) return
            progress.value = next
            lastStageAt = Date.now()
        }
        await pause(3000)
        if (current !== generation) return
        await finish()
        if (current === generation) onComplete()
    }

    onBeforeUnmount(cancel)
    return { progress, reset, update, finish, preview }
}
