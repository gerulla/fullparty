<script setup lang="ts">
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
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

const props = defineProps<{
	groupSlug: string
	activityId: number
	secretKey?: string
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
		answers: Record<string, unknown>
	} | null
	canApply: boolean
}>();

const emit = defineEmits<{
	cancel: []
}>();

const { t } = useI18n();

const characterItems = computed(() => props.characters.map((character) => ({
	label: character.world ? `${character.name} • ${character.world}` : character.name,
	value: character.id,
	avatar_url: character.avatar_url,
})));

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

const submit = () => {
	const targetRoute = props.application
		? 'groups.activities.application.update'
		: 'groups.activities.application.store';

	const routeParams = {
		group: props.groupSlug,
		activity: props.activityId,
		secretKey: props.secretKey || undefined,
	};

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
			<section class="space-y-5">
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
						:disabled="!canApply || characters.length === 0"
						:placeholder="t('groups.activities.application.form.character_placeholder')"
					/>
				</UFormField>
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
						:disabled="!canApply"
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
						:disabled="!canApply"
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
					type="submit"
					color="neutral"
					size="lg"
					:icon="application ? 'i-lucide-save' : 'i-lucide-send'"
					:label="application
						? t('groups.activities.application.form.update')
						: t('groups.activities.application.form.submit')"
					:disabled="!canApply || characters.length === 0"
					:loading="form.processing"
				/>
			</div>

			<UAlert
				v-if="!canApply"
				color="warning"
				variant="soft"
				icon="i-lucide-log-in"
				:title="t('groups.activities.application.form.login_required_title')"
				:description="t('groups.activities.application.form.login_required_description')"
			/>

			<UAlert
				v-else-if="characters.length === 0"
				color="warning"
				variant="soft"
				icon="i-lucide-user-round-x"
				:title="t('groups.activities.application.form.no_characters_title')"
				:description="t('groups.activities.application.form.no_characters_description')"
			/>
		</form>
	</UCard>
</template>
