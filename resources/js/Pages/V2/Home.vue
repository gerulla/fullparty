<script setup lang="ts">
import {Head, Link, router, usePage} from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import type { V2HomeCharacter, V2HomeProps } from '../../Types/V2Home'
import CharacterSelector from "@/components/Home/CharacterSelector.vue";
import {TabsItem} from "@nuxt/ui/components/Tabs.vue";
import CharacterApplicationsBox from "@/components/Home/CharacterApplicationsBox.vue";
import UserGroupsBox from "@/components/Home/UserGroupsBox.vue";
import RecommendedRuns from "@/components/Home/RecommendedRuns.vue";
import FromYourGroups from "@/components/Home/FromYourGroups.vue";
import HomePreviewControls from '@/components/Home/HomePreviewControls.vue'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
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
const recommendedRuns = computed(() => props.recommendedRuns ?? [])
const groupRuns = computed(() => emptyAccount.value ? [] : props.groupRuns ?? [])
const loading = computed(() => ({
    user: refreshing.value || (!emptyAccount.value && props.user === undefined),
    applications: refreshing.value || (!emptyAccount.value && props.applications === undefined),
    applicationHistory: refreshing.value || (!emptyAccount.value && props.applicationHistory === undefined),
    groups: refreshing.value || (!emptyAccount.value && props.groups === undefined),
    recommendedRuns: refreshing.value || props.recommendedRuns === undefined,
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

const items = [
	{
		label: 'Account',
		description: 'Make changes to your account here. Click save when you\'re done.',
		slot: 'account' as const
	},
	{
		label: 'Password',
		description: 'Change your password here. After saving, you\'ll be logged out.',
		slot: 'password' as const
	}
] satisfies TabsItem[]
</script>

<template>
    <Head :title="`${t('navigation.sidebar.dashboard')} `" />

    <HomePreviewControls v-if="canPreview" :refreshing="refreshing" :empty-account="emptyAccount"
        @refresh="refreshHomepage" @toggle-empty="simulateEmptyAccount = !simulateEmptyAccount" />

    <div class="flex w-full flex-col gap-8 xl:flex-row xl:gap-0">
		<div class="flex w-full min-w-0 flex-col items-stretch gap-6 self-start xl:sticky xl:top-[calc(var(--ui-header-height)+2rem)] xl:w-[30%] xl:pr-8">
			<CharacterSelector v-model="selected_character" :user="user" :loading="loading.user" />
			<CharacterApplicationsBox :applications="applications" :history="applicationHistory" :loading="loading.user || loading.applications" :history-loading="loading.user || loading.applicationHistory" :character="selected_character" />
			<div class="hidden xl:block">
				<UserGroupsBox :groups="groups" :loading="loading.groups" />
			</div>
		</div>
		<div class="flex w-full min-w-0 flex-col items-stretch gap-8 xl:w-[70%]">
			<FromYourGroups :groups="groupRuns" :loading="loading.groupRuns" class="xl:order-2" />
			<RecommendedRuns :runs="recommendedRuns" :loading="loading.recommendedRuns" class="xl:order-1" />
		</div>
	</div>
</template>

<style scoped>
@reference '../../../css/app.css';

</style>
