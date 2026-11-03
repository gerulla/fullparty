import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const requested = ref(false)

export function useGroupDiscordPreview() {
    const page = usePage()
    const available = computed(() => page.props.theme_preview_enabled === true)
    const active = computed({
        get: () => available.value && requested.value,
        set: value => { requested.value = available.value && value },
    })
    return { available, active }
}
