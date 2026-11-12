<script setup lang="ts">
import {Head, router, usePage} from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import V2Layout from '@/Layouts/V2Layout.vue'
import type { V2HomeCharacter, V2HomeProps } from '../../Types/V2Home'
import CharacterSelector from "@/components/Home/CharacterSelector.vue";
import CharacterApplicationsBox from "@/components/Home/CharacterApplicationsBox.vue";
import UserGroupsBox from "@/components/Home/UserGroupsBox.vue";
import HomeRunSection from "@/components/Home/HomeRunSection.vue";
import FromYourGroups from "@/components/Home/FromYourGroups.vue";
import HomePreviewControls from '@/components/Home/HomePreviewControls.vue'
import HomeEmptyState from '@/components/Home/HomeEmptyState.vue'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
const upcomingRunsIllustration = '/images/empty-states/upcoming-runs.png'
const props = defineProps<V2HomeProps>()
const page = usePage()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const refreshing = ref(false)
const simulateEmptyAccount = ref(false)
const emptyAccount = computed(() => canPreview.value && simulateEmptyAccount.value)

// Inertia fetches each deferred prop after the initial page render.
const user = computed(() => props.user
    ? emptyAccount.value ? { ...props.user, characters: [] } : props.user
    : null)
const characters = computed(() => user.value?.characters ?? [])
const applications = computed(() => emptyAccount.value ? [] : props.applications ?? [])
const applicationHistory = computed(() => emptyAccount.value ? [] : props.applicationHistory ?? [])
const groups = computed(() => emptyAccount.value ? [] : props.groups ?? [])
const upcomingRuns = computed(() => emptyAccount.value ? [] : props.upcomingRuns ?? [])
const hostedRuns = computed(() => emptyAccount.value ? [] : props.hostedRuns ?? [])
const groupRuns = computed(() => emptyAccount.value ? [] : props.groupRuns ?? [])
const loading = computed(() => ({
    user: refreshing.value || (!emptyAccount.value && props.user === undefined),
    applications: refreshing.value || (!emptyAccount.value && props.applications === undefined),
    applicationHistory: refreshing.value || (!emptyAccount.value && props.applicationHistory === undefined),
    groups: refreshing.value || (!emptyAccount.value && props.groups === undefined),
    upcomingRuns: refreshing.value || (!emptyAccount.value && props.upcomingRuns === undefined),
    groupRuns: refreshing.value || (!emptyAccount.value && props.groupRuns === undefined),
}))

function refreshHomepage() {
    if (!canPreview.value || refreshing.value) return

    // Cancel any previous deferred requests before starting a fresh loading cycle.
    router.cancelAll({ async: true, sync: false, prefetch: false })
    simulateEmptyAccount.value = false
    refreshing.value = true
    // A full Inertia response clears deferred props, then reloads their groups independently.
    router.reload({
        fresh: true,
        onFinish: () => { refreshing.value = false },
    })
}

const selectedCharacterId = ref<number | null>(null)
const selected_character = computed({
	get: () => characters.value.find(character => character.id === selectedCharacterId.value)
		?? characters.value.find(character => character.is_primary)
		?? characters.value[0]
		?? null,
	set: (character: V2HomeCharacter | null) => {
		selectedCharacterId.value = character?.id ?? null
	},
})

</script>

<template>
    <Head :title="`${t('navigation.sidebar.dashboard')} `" />

    <HomePreviewControls v-if="canPreview" :refreshing="refreshing" :empty-account="emptyAccount"
        @refresh="refreshHomepage" @toggle-empty="simulateEmptyAccount = !simulateEmptyAccount" />

    <div class="home-theme flex w-full flex-col gap-8 xl:flex-row xl:gap-0">
		<div class="flex w-full min-w-0 flex-col items-stretch gap-6 self-start xl:sticky xl:top-[calc(var(--ui-header-height)+2rem)] xl:w-[30%] xl:pr-8">
			<CharacterSelector v-model="selected_character" :user="user" :loading="loading.user" />
			<CharacterApplicationsBox :applications="applications" :history="applicationHistory" :loading="loading.user || loading.applications" :history-loading="loading.user || loading.applicationHistory" :character="selected_character" />
			<div class="hidden xl:block">
				<UserGroupsBox :groups="groups" :loading="loading.groups" />
			</div>
		</div>
		<div class="flex w-full min-w-0 flex-col items-stretch gap-8 xl:w-[70%]">
			<HomeRunSection :runs="upcomingRuns" :loading="loading.upcomingRuns" find-more
                :title="t('dashboard.upcoming_runs.title')" :loading-label="t('dashboard.upcoming_runs.loading')">
                <template #empty>
                    <HomeEmptyState spacious
                        :title="t('dashboard.empty_states.upcoming.title')"
                        :description="t('dashboard.empty_states.upcoming.description')"
                        :action-label="t('dashboard.empty_states.find_runs')"
                        :action-to="route('dashboard.runs.index')">
                        <template #illustration>
                            <img :src="upcomingRunsIllustration" alt="" aria-hidden="true"
                                class="h-40 w-48 object-contain" width="192" height="160" loading="lazy" decoding="async">
                        </template>
                    </HomeEmptyState>
                </template>
            </HomeRunSection>
            <HomeRunSection v-if="hostedRuns.length" :runs="hostedRuns"
                :title="t('dashboard.hosted_runs.title')" />
			<FromYourGroups :groups="groupRuns" :loading="loading.groupRuns" />
		</div>
	</div>
</template>

<style scoped>
@reference '../../../css/app.css';

.home-theme {
    --ui-primary: var(--color-brand-700);
    --ui-brand: var(--color-brand-700);
}

/* All homepage brand controls, including their hover/focus states, share this contrast. */
.dark .home-theme {
    --ui-primary: var(--color-brand-400);
    --ui-brand: var(--color-brand-400);
}
</style>
