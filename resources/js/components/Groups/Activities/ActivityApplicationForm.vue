<script setup lang="ts">
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import ApplicationQuestionField from "@/components/Groups/Activities/ApplicationQuestionField.vue";

type QuestionOption = {
	key: string
	label: Record<string, string | null | undefined>
	meta?: {
		icon_url?: string | null
		role?: string | null
		shorthand?: string | null
	} | null
}

type ApplicationQuestion = {
	key: string
	label: Record<string, string | null | undefined>
	type: string
	source: string | null
	required?: boolean
	help_text?: Record<string, string | null | undefined> | null
	options: QuestionOption[]
}

type GuestWorldOption = {
	label: string
	value: string
}

type GuestCharacterSearchResult = {
	lodestone_id: string
	name: string
	world: string
	datacenter: string | null
	avatar_url: string | null
	profile_url: string | null
}

const props = defineProps<{
	groupSlug: string
	activityId: number
	secretKey?: string
	guestAccessToken?: string
	characters: Array<{
		id: number
		name: string
		avatar_url: string | null
		world: string | null
	}>
	questions: ApplicationQuestion[]
	application: {
		id: number
		selected_character_id: number | null
		status: string
		notes: string | null
		submitted_at: string | null
		applicant_character?: {
			lodestone_id: string
			name: string
			world: string
			datacenter: string
			avatar_url: string | null
		} | null
		answers: Record<string, unknown>
	} | null
	canApply: boolean
	canApplyAsGuest: boolean
	canEditApplication: boolean
	guestWorlds: GuestWorldOption[]
}>();

const emit = defineEmits<{
	cancel: []
}>();

const { t } = useI18n();
const toast = useToast();

const characterItems = computed(() => props.characters.map((character) => ({
	label: character.world ? `${character.name} • ${character.world}` : character.name,
	value: character.id,
	avatar_url: character.avatar_url,
})));

const guestWorldItems = computed(() => props.guestWorlds);

const form = useForm({
	selected_character_id: props.application?.selected_character_id ?? props.characters[0]?.id ?? null,
	notes: props.application?.notes ?? '',
	answers: Object.fromEntries(props.questions.map((question) => [
		question.key,
		props.application?.answers?.[question.key]
			?? (question.type === 'multi_select' ? [] : question.type === 'boolean' ? false : ''),
	])),
});

const selectedCharacter = computed(() => props.characters.find((character) => character.id === form.selected_character_id) || null);
const guestModeEnabled = computed(() => !props.canApply && props.canApplyAsGuest);
const applicationLocked = computed(() => props.application !== null && !props.canEditApplication);
const showLoginRequiredAlert = computed(() => !props.canApply && !props.canApplyAsGuest);
const showNoCharactersAlert = computed(() => props.canApply && props.characters.length === 0);
const guestSearchName = ref(props.application?.applicant_character?.name ?? '');
const guestSearchWorld = ref(props.application?.applicant_character?.world ?? '');
const guestSearchResults = ref<GuestCharacterSearchResult[]>([]);
const selectedGuestCharacter = ref<GuestCharacterSearchResult | null>(props.application?.applicant_character
	? {
		lodestone_id: props.application.applicant_character.lodestone_id,
		name: props.application.applicant_character.name,
		world: props.application.applicant_character.world,
		datacenter: props.application.applicant_character.datacenter,
		avatar_url: props.application.applicant_character.avatar_url,
		profile_url: null,
	}
	: null);
const guestSearchError = ref<string | null>(null);
const guestSearchAttempted = ref(false);
const guestSearchLoading = ref(false);
const canSubmit = computed(() => {
	if (applicationLocked.value) {
		return false;
	}

	if (props.canApply) {
		return props.characters.length > 0;
	}

	if (props.canApplyAsGuest) {
		return selectedGuestCharacter.value !== null;
	}

	return false;
});

const goToLogin = () => {
	router.get(route('login'));
};

const goToCharacters = () => {
	router.get(route('account.characters'));
};

const searchGuestCharacters = async () => {
	guestSearchAttempted.value = true;
	guestSearchError.value = null;
	selectedGuestCharacter.value = null;
	guestSearchResults.value = [];

	if (guestSearchName.value.trim() === '' || guestSearchWorld.value.trim() === '') {
		guestSearchError.value = t('groups.activities.application.form.guest_search_missing_fields');
		return;
	}

	guestSearchLoading.value = true;

	try {
		const response = await window.axios.get(route('groups.activities.application.search-characters', {
			group: props.groupSlug,
			activity: props.activityId,
			secretKey: props.secretKey || undefined,
			name: guestSearchName.value.trim(),
			world: guestSearchWorld.value,
		}), {
			headers: {
				Accept: 'application/json',
			},
		});

		guestSearchResults.value = Array.isArray(response.data?.data)
			? response.data.data
			: [];
	} catch (error: any) {
		guestSearchResults.value = [];

		if (error?.response?.status === 422) {
			guestSearchError.value = error.response.data?.message
				|| t('groups.activities.application.form.guest_search_invalid');
		} else {
			guestSearchError.value = error?.response?.data?.message
				|| t('groups.activities.application.form.guest_search_failed');
		}

		toast.add({
			title: t('general.error'),
			description: guestSearchError.value,
			icon: 'i-lucide-circle-alert',
			color: 'error',
		});
	} finally {
		guestSearchLoading.value = false;
	}
};

const selectGuestCharacter = (character: GuestCharacterSearchResult) => {
	selectedGuestCharacter.value = character;
};

const submit = () => {
	const targetRoute = props.application
		? props.guestAccessToken
			? 'groups.activities.application.update-guest'
			: 'groups.activities.application.update'
		: 'groups.activities.application.store';

	const routeParams: Record<string, string | number | undefined> = {
		group: props.groupSlug,
		activity: props.activityId,
		secretKey: props.secretKey || undefined,
	};

	if (props.guestAccessToken) {
		routeParams.accessToken = props.guestAccessToken;
	}

	form.transform((data) => ({
		...data,
		selected_character_id: props.canApply ? data.selected_character_id : null,
		guest_applicant: props.canApplyAsGuest && selectedGuestCharacter.value
			? {
				lodestone_id: selectedGuestCharacter.value.lodestone_id,
				name: selectedGuestCharacter.value.name,
				world: selectedGuestCharacter.value.world,
				datacenter: selectedGuestCharacter.value.datacenter,
				avatar_url: selectedGuestCharacter.value.avatar_url,
			}
			: undefined,
	}));

	if (props.application) {
		form.put(route(targetRoute, routeParams), {
			preserveScroll: true,
		});

		return;
	}

	form.post(route(targetRoute, routeParams), {
		preserveScroll: true,
	});
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.activities.application.form.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.activities.application.form.subtitle') }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-8" @submit.prevent="submit">
			<UAlert
				v-if="showLoginRequiredAlert"
				color="warning"
				variant="soft"
				icon="i-lucide-log-in"
				:title="t('groups.activities.application.form.login_required_title')"
				:description="t('groups.activities.application.form.login_required_description')"
			>
				<template #actions>
					<UButton
						color="warning"
						variant="outline"
						size="sm"
						icon="i-lucide-log-in"
						:label="t('auth.login')"
						@click.prevent="goToLogin"
					/>
				</template>
			</UAlert>

			<UAlert
				v-else-if="showNoCharactersAlert"
				color="warning"
				variant="soft"
				icon="i-lucide-user-round-x"
				:title="t('groups.activities.application.form.no_characters_title')"
				:description="t('groups.activities.application.form.no_characters_description')"
			>
				<template #actions>
					<UButton
						color="warning"
						variant="outline"
						size="sm"
						icon="i-lucide-user-round-plus"
						:label="t('navigation.sidebar.characters')"
						@click.prevent="goToCharacters"
					/>
				</template>
			</UAlert>

			<UAlert
				v-if="applicationLocked"
				color="warning"
				variant="soft"
				icon="i-lucide-lock"
				:title="t('groups.activities.application.form.locked_title')"
				:description="t('groups.activities.application.form.locked_description')"
			/>

			<UAlert
				v-if="guestModeEnabled"
				color="info"
				variant="soft"
				icon="i-lucide-info"
				:title="t('groups.activities.application.form.guest_mode_title')"
				:description="t('groups.activities.application.form.guest_mode_description')"
			>
				<template #actions>
					<UButton
						color="info"
						variant="outline"
						size="sm"
						icon="i-lucide-log-in"
						:label="t('auth.login')"
						@click.prevent="goToLogin"
					/>
				</template>
			</UAlert>

			<section v-if="canApply" class="space-y-5">
				<UFormField
					:label="t('groups.activities.application.form.character_field')"
					:description="t('groups.activities.application.form.character_description')"
					:error="form.errors.selected_character_id"
				>
					<USelectMenu
						v-model="form.selected_character_id"
						size="lg"
						class="w-full"
						:items="characterItems"
						value-key="value"
						:avatar="selectedCharacter?.avatar_url ? { src: selectedCharacter.avatar_url, loading: 'lazy' } : undefined"
						:disabled="applicationLocked || !canApply || characters.length === 0"
						:placeholder="t('groups.activities.application.form.character_placeholder')"
					/>
				</UFormField>
			</section>

			<section v-else-if="canApplyAsGuest" class="space-y-5">
				<div class="flex flex-row items-end justify-items-stretch justify-between gap-3">
					<UFormField
						:label="t('groups.activities.application.form.guest_name_field')"
						:description="t('groups.activities.application.form.guest_name_description')"
						class="w-full"
					>
						<UInput
							v-model="guestSearchName"
							size="lg"
							class="w-full"
							:disabled="applicationLocked"
							:placeholder="t('groups.activities.application.form.guest_name_placeholder')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.application.form.guest_world_field')"
						:description="t('groups.activities.application.form.guest_world_description')"
						class="w-full"
					>
						<USelectMenu
							v-model="guestSearchWorld"
							size="lg"
							class="w-full"
							:items="guestWorldItems"
							value-key="value"
							:disabled="applicationLocked"
							:placeholder="t('groups.activities.application.form.guest_world_placeholder')"
						/>
					</UFormField>

					<div class="flex items-end">
						<UButton
							type="button"
							color="neutral"
							size="lg"
							icon="i-lucide-search"
							class="w-full justify-center"
							:label="t('groups.activities.application.form.guest_search_button')"
							:disabled="applicationLocked"
							:loading="guestSearchLoading"
							@click="searchGuestCharacters"
						/>
					</div>
				</div>

				<UAlert
					v-if="guestSearchError"
					color="warning"
					variant="soft"
					icon="i-lucide-circle-alert"
					:title="t('groups.activities.application.form.guest_search_error_title')"
					:description="guestSearchError"
				/>

				<div
					v-if="guestSearchResults.length > 0"
					class="space-y-3"
				>
					<div class="flex items-center justify-between gap-3">
						<div class="flex flex-col gap-1">
							<p class="font-medium text-toned">{{ t('groups.activities.application.form.guest_results_title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.activities.application.form.guest_results_description') }}</p>
						</div>
						<UBadge
							color="neutral"
							variant="soft"
							:label="t('groups.activities.application.form.guest_results_count', { count: guestSearchResults.length })"
						/>
					</div>

					<div class="grid grid-cols-1 gap-3">
						<button
							v-for="character in guestSearchResults"
							:key="character.lodestone_id"
							type="button"
							class="guest-search-result"
							:class="{ 'guest-search-result--selected': selectedGuestCharacter?.lodestone_id === character.lodestone_id }"
							@click="selectGuestCharacter(character)"
						>
							<div class="guest-search-result__body">
								<img
									v-if="character.avatar_url"
									:src="character.avatar_url"
									:alt="`${character.name} avatar`"
									class="guest-search-result__avatar"
								>
								<div
									v-else
									class="guest-search-result__avatar guest-search-result__avatar--placeholder"
								>
									<UIcon name="i-lucide-user-round" class="size-5 text-muted" />
								</div>

								<div class="min-w-0 flex-1">
									<p class="truncate font-medium text-toned">{{ character.name }}</p>
									<p class="text-sm text-muted">{{ character.world }}<span v-if="character.datacenter"> - {{ character.datacenter }}</span></p>
								</div>

								<UBadge
									:color="selectedGuestCharacter?.lodestone_id === character.lodestone_id ? 'success' : 'neutral'"
									:variant="selectedGuestCharacter?.lodestone_id === character.lodestone_id ? 'solid' : 'soft'"
									:label="selectedGuestCharacter?.lodestone_id === character.lodestone_id
										? t('groups.activities.application.form.guest_result_selected')
										: t('groups.activities.application.form.guest_result_select')"
								/>
							</div>
						</button>
					</div>
				</div>

				<UAlert
					v-else-if="guestSearchAttempted && !guestSearchLoading && !guestSearchError"
					color="neutral"
					variant="soft"
					icon="i-lucide-search-x"
					:title="t('groups.activities.application.form.guest_no_results_title')"
					:description="t('groups.activities.application.form.guest_no_results_description')"
				/>
			</section>

			<div
				v-if="questions.length > 0"
				class="border-t border-default"
			></div>

			<section
				v-if="questions.length > 0"
				class="space-y-5"
			>
				<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
					<ApplicationQuestionField
						v-for="question in questions"
						:key="question.key"
						v-model="form.answers[question.key]"
						:question="question"
						:error="form.errors[`answers.${question.key}`] || form.errors[question.key]"
						:disabled="applicationLocked || !canSubmit"
						:class="question.type === 'textarea' ? 'xl:col-span-2' : ''"
					/>
				</div>
			</section>

			<div class="border-t border-default"></div>

			<section class="space-y-5">
				<UFormField
					:label="t('groups.activities.application.form.notes_field')"
					:description="t('groups.activities.application.form.notes_description')"
					:error="form.errors.notes"
				>
					<UTextarea
						v-model="form.notes"
						size="lg"
						class="w-full"
						:rows="5"
						:disabled="applicationLocked || !canSubmit"
						:placeholder="t('groups.activities.application.form.notes_placeholder')"
					/>
				</UFormField>
			</section>

			<div class="flex items-center gap-3 border-t border-default pt-2">
				<UButton
					type="button"
					color="neutral"
					variant="outline"
					size="lg"
					:label="t('groups.activities.application.form.cancel')"
					@click="emit('cancel')"
				/>
				<UButton
					v-if="!applicationLocked"
					type="submit"
					color="neutral"
					size="lg"
					:icon="application ? 'i-lucide-save' : 'i-lucide-send'"
					:label="application
						? t('groups.activities.application.form.update')
						: t('groups.activities.application.form.submit')"
					:disabled="!canSubmit"
					:loading="form.processing"
				/>
			</div>
		</form>
	</UCard>
</template>

<style scoped>
@reference '../../../../css/app.css';

.guest-search-result {
	@apply w-full rounded-sm border border-default bg-default px-4 py-3 text-left transition;
}

.guest-search-result:hover {
	@apply border-primary/50 bg-elevated/40;
}

.guest-search-result--selected {
	@apply border-primary bg-primary/5;
}

.guest-search-result__body {
	@apply flex items-center gap-3;
}

.guest-search-result__avatar {
	@apply h-12 w-12 rounded-sm border border-default object-cover object-center;
}

.guest-search-result__avatar--placeholder {
	@apply flex items-center justify-center bg-muted/30;
}
</style>
