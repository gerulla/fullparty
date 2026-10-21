import type { CharacterImportProgress, CharacterImportResult, CharacterImportStage } from '@/Types/CharacterOnboarding'

const stages: CharacterImportStage[] = ['profile', 'classes', 'phantom_jobs', 'achievements', 'fflogs', 'saving']

/** Read the refresh endpoint's newline-delimited events, including split UTF-8/network chunks. */
export async function readCharacterImportStream(
    stream: ReadableStream<Uint8Array>,
    onProgress: (progress: CharacterImportProgress) => Promise<void>,
): Promise<CharacterImportResult> {
    const reader = stream.getReader()
    const decoder = new TextDecoder()
    let pending = ''
    let result: CharacterImportResult | null = null

    async function readLine(line: string) {
        if (!line.trim()) return
        const event = JSON.parse(line)
        if (event.type === 'progress' && stages.includes(event.stage) && Number.isFinite(event.percent)) {
            await onProgress({ stage: event.stage, percent: Math.min(99, Math.max(0, event.percent)) })
        } else if (event.type === 'complete' && event.character && Number.isInteger(event.character.id) && typeof event.partial === 'boolean') {
            result = { character: event.character, partial: event.partial }
        } else {
            throw new Error('Character import stream failed.')
        }
    }

    try {
        while (true) {
            const { value, done } = await reader.read()
            pending += decoder.decode(value, { stream: !done })
            let newline: number
            while ((newline = pending.indexOf('\n')) !== -1) {
                await readLine(pending.slice(0, newline))
                pending = pending.slice(newline + 1)
            }
            if (done) break
            if (pending.length > 65536) throw new Error('Invalid character import stream.')
        }
        await readLine(pending)
        if (!result) throw new Error('Character import stream ended before completion.')
        return result
    } finally {
        await reader.cancel().catch(() => {})
        reader.releaseLock()
    }
}
