<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {computed, nextTick, ref, watch} from "vue";
import CharacterDetailPanel from "@/components/Characters/CharacterDetailPanel.vue";
import CharacterOverviewCard from "@/components/Characters/CharacterOverviewCard.vue";
import {Head, router, usePage} from "@inertiajs/vue3";
import {useToast} from "@nuxt/ui/composables";
import { route } from 'ziggy-js'
import V2Layout from "@/Layouts/V2Layout.vue";
import { useCharacterVerificationGuide } from '@/composables/useCharacterVerificationGuide'
import { useCharacterManagement } from '@/composables/useCharacterManagement'
import type { AccountCharacter } from '../../../Types/Characters'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
const page = usePage();
const toast = useToast()
const props = defineProps<{ characters: AccountCharacter[] }>()
const { showVerificationGuide } = useCharacterVerificationGuide()
const selectedCharacterId = computed(() => new URLSearchParams(page.url.split('?')[1]?.split('#')[0] ?? '').get('character'))
const selectedCharacter = computed(() => props.characters.find(character => String(character.id) === selectedCharacterId.value) ?? null)
const characters = computed(() => [...props.characters].sort((a, b) => Number(b.is_primary) - Number(a.is_primary)))
const backButton = ref<{ $el: HTMLButtonElement } | null>(null)
const characterActions = useCharacterManagement(() => selectedCharacter.value, () => {
    router.replace({ url: characterUrl(null), preserveState: true, preserveScroll: true })
})

function characterUrl(id: number | null) {
    const url = new URL(page.url, window.location.origin)
    if (id === null) url.searchParams.delete('character')
    else url.searchParams.set('character', String(id))
    return `${url.pathname}${url.search}${url.hash}`
}

function manageCharacter(id: number) {
    router.push({
        url: characterUrl(id), preserveState: true, preserveScroll: true,
        onFinish: async () => {
            await nextTick()
            backButton.value?.$el.focus()
        },
    })
}

function showAllCharacters() {
    const previousId = selectedCharacterId.value
    router.push({
        url: characterUrl(null), preserveState: true, preserveScroll: true,
        onFinish: async () => {
            await nextTick()
            document.getElementById(`manage-character-${previousId}`)?.focus()
        },
    })
}

const logCharacterRefreshDebug = (debug) => {
	const fflogsError = debug?.fflogs_error;

	if (!fflogsError) {
		return;
	}

	console.error('[FullParty] FF Logs progress refresh failed during character refresh.', fflogsError);
}


watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) return

		if (success.includes('character_data_refreshed')) {
			toast.add({
				title: t('characters.toasts.title'),
				description: t('characters.toasts.character_refreshed'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}

		if (success.includes('character_marked_primary')) {
			toast.add({
				title: t('characters.toasts.title'),
				description: t('characters.toasts.character_primary'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}

		if (success.includes('character_unclaimed')) {
			toast.add({
				title: t('characters.toasts.title'),
				description: t('characters.toasts.character_unclaimed'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
	},
	{ immediate: true }
)

watch(
	() => page.props.flash?.data?.character_refresh_debug,
	(debug) => logCharacterRefreshDebug(debug),
	{ immediate: true }
)
</script>

<template>
    <Head :title="t('characters.title')" />
    <div class="mx-auto w-full max-w-7xl space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-highlighted sm:text-3xl">{{ t('characters.title') }}</h1>
                <p class="mt-2 text-sm text-muted">{{ t('characters.overview.subtitle') }}</p>
            </div>
            <UButton :to="route('characters.create')" color="brand" icon="i-lucide-plus" class="rounded-md">
                {{ t('characters.add.add_button') }}
            </UButton>
        </header>

        <section v-if="selectedCharacter" class="w-full space-y-4">
            <UButton ref="backButton" color="neutral" variant="ghost" icon="i-lucide-arrow-left" class="rounded-md" :disabled="characterActions.busy" @click="showAllCharacters">
                {{ t('characters.overview.all_characters') }}
            </UButton>
            <CharacterDetailPanel :key="selectedCharacter.id" :character="selectedCharacter" :actions="characterActions" @verification-help="showVerificationGuide" />
        </section>
        <div v-else-if="characters.length" class="grid grid-cols-1 items-stretch gap-5 md:grid-cols-2 xl:grid-cols-3">
            <CharacterOverviewCard v-for="character in characters" :key="character.id" :character="character"
                @manage="manageCharacter(character.id)" />
        </div>
        <div v-else class="rounded-xl border border-elevation-3 bg-elevation-1 px-6 py-12 text-center dark:border-elevation-7 dark:bg-elevation-3">
            <UIcon name="i-lucide-users-round" class="mb-3 size-8 text-muted" aria-hidden="true" />
            <h2 class="font-semibold">{{ t('characters.overview.empty_title') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ t('characters.overview.empty_description') }}</p>
        </div>

        <aside class="flex flex-wrap items-center gap-4 rounded-xl border border-elevation-3 bg-elevation-1 p-5 dark:border-elevation-7 dark:bg-elevation-3">
            <UIcon name="i-lucide-circle-help" class="size-5 shrink-0 text-muted" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-semibold">{{ t('characters.overview.verification_title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ t('characters.overview.verification_description') }}</p>
            </div>
            <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-right" class="rounded-md" @click="showVerificationGuide">
                {{ t('characters.overview.verification_button') }}
            </UButton>
        </aside>
    </div>
</template>
