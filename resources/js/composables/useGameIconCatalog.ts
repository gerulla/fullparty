import axios from 'axios'
import { shallowRef } from 'vue'
import { route } from 'ziggy-js'
import type { GameIcon } from '@/Types/GameIcons'

const icons = shallowRef<GameIcon[]>([])
let pending: Promise<GameIcon[]> | null = null

export function useGameIconCatalog() {
    async function load(): Promise<GameIcon[]> {
        if (icons.value.length) return icons.value
        if (!pending) {
            pending = axios.get<{ icons: GameIcon[] }>(route('editor.game-icons'))
                .then(response => (icons.value = response.data.icons))
                .finally(() => { pending = null })
        }
        return pending
    }
    return { icons, load }
}
