<script setup lang="ts">
import { reactive, watch } from "vue"
import { useI18n } from "vue-i18n"
import type { ActivityPartyFinderInfo } from "@/Types/ActivityManagement"

const props = defineProps<{
	open: boolean
	info: ActivityPartyFinderInfo | null
	pending: boolean
	errors: Record<string, string[]>
}>()

const emit = defineEmits<{
	"update:open": [value: boolean]
	submit: [value: { character_name: string, world: string, password: string }]
}>()

const { t } = useI18n()
const form = reactive({
	character_name: "",
	world: "",
	password: "",
})

watch(() => props.open, (open) => {
	if (!open) {
		return
	}

	form.character_name = props.info?.character_name ?? ""
	form.world = props.info?.world ?? ""
	form.password = props.info?.password ?? ""
})

const fieldError = (field: string) => props.errors[field]?.[0]

const submit = () => {
	emit("submit", {
		character_name: form.character_name,
		world: form.world,
		password: form.password,
	})
}
</script>

<template>
	<UModal
		:open="open"
		:title="t('party_finder.publish.title')"
		:description="t('party_finder.publish.description')"
		@update:open="emit('update:open', $event)"
	>
		<template #body>
			<form class="space-y-5" @submit.prevent="submit">
				<UFormField
					:label="t('party_finder.fields.character_name')"
					required
					:error="fieldError('character_name')"
				>
					<UInput
						v-model="form.character_name"
						class="w-full"
						icon="i-lucide-user-round"
						maxlength="64"
						autocomplete="off"
					/>
				</UFormField>

				<UFormField
					:label="t('party_finder.fields.world')"
					required
					:error="fieldError('world')"
				>
					<UInput
						v-model="form.world"
						class="w-full"
						icon="i-lucide-globe-2"
						maxlength="64"
						autocomplete="off"
					/>
				</UFormField>

				<UFormField
					:label="t('party_finder.fields.password')"
					:description="t('party_finder.fields.password_description')"
					required
					:error="fieldError('password')"
				>
					<UInput
						v-model="form.password"
						class="w-full"
						icon="i-lucide-key-round"
						maxlength="32"
						autocomplete="off"
					/>
				</UFormField>
			</form>
		</template>

		<template #footer>
			<div class="flex w-full justify-end gap-2">
				<UButton
					color="neutral"
					variant="outline"
					:label="t('general.cancel')"
					:disabled="pending"
					@click="emit('update:open', false)"
				/>
				<UButton
					color="primary"
					icon="i-lucide-send"
					:label="t('party_finder.publish.action')"
					:loading="pending"
					@click="submit"
				/>
			</div>
		</template>
	</UModal>
</template>
