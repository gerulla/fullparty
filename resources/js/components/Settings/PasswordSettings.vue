<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { usePasswordVisibility } from "@/composables/usePasswordVisibility";

const { t } = useI18n();
const passwordVisibility = usePasswordVisibility(['current_password', 'password', 'password_confirmation'] as const);
const passwordForm = useForm({
	current_password: '',
	password: '',
	password_confirmation: '',
});

const submitPasswordForm = () => {
	passwordForm.post(route('settings.password'), {
		preserveScroll: true,
		onSuccess: () => {
			passwordForm.reset();
			passwordForm.clearErrors();
		},
		onFinish: () => {
			passwordForm.password = '';
			passwordForm.password_confirmation = '';
		},
	});
};

</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex items-center gap-2 font-semibold">
				<UIcon name="i-lucide-key-round" class="size-5" />
				<p>{{ t('settings.account.password') }}</p>
			</div>
		</template>
			<div class="w-full">
				<form class="flex w-full flex-col items-start gap-4" @submit.prevent="submitPasswordForm">
					<UFormField
						class="w-full"
						:label="t('settings.account.old_password')"
						:error="passwordForm.errors.current_password"
					>
						<UInput
							v-model="passwordForm.current_password"
							:type="passwordVisibility.inputType('current_password')"
							size="xl"
							class="w-full"
							:placeholder="t('settings.account.old_password')"
							autocomplete="current-password"
							:ui="{ trailing: 'pe-1' }"
						>
							<template #trailing>
								<UButton
									type="button"
									color="neutral"
									variant="ghost"
									size="sm"
									:icon="passwordVisibility.icon('current_password')"
									:aria-label="t('settings.account.old_password')"
									@click="passwordVisibility.toggle('current_password')"
								/>
							</template>
						</UInput>
					</UFormField>

					<div class="grid w-full grid-cols-1 gap-4 xl:grid-cols-2">
						<UFormField
							class="w-full"
							:label="t('settings.account.new_password')"
							:error="passwordForm.errors.password"
						>
							<UInput
								v-model="passwordForm.password"
								:type="passwordVisibility.inputType('password')"
								size="xl"
								class="w-full"
								:placeholder="t('settings.account.new_password')"
								autocomplete="new-password"
								:ui="{ trailing: 'pe-1' }"
							>
								<template #trailing>
									<UButton
										type="button"
										color="neutral"
										variant="ghost"
										size="sm"
										:icon="passwordVisibility.icon('password')"
										:aria-label="t('settings.account.new_password')"
										@click="passwordVisibility.toggle('password')"
									/>
								</template>
							</UInput>
						</UFormField>

						<UFormField
							class="w-full"
							:label="t('settings.account.new_password_confirmation')"
							:error="passwordForm.errors.password_confirmation"
						>
							<UInput
								v-model="passwordForm.password_confirmation"
								:type="passwordVisibility.inputType('password_confirmation')"
								size="xl"
								class="w-full"
								:placeholder="t('settings.account.new_password_confirmation')"
								autocomplete="new-password"
								:ui="{ trailing: 'pe-1' }"
							>
								<template #trailing>
									<UButton
										type="button"
										color="neutral"
										variant="ghost"
										size="sm"
										:icon="passwordVisibility.icon('password_confirmation')"
										:aria-label="t('settings.account.new_password_confirmation')"
										@click="passwordVisibility.toggle('password_confirmation')"
									/>
								</template>
							</UInput>
						</UFormField>
					</div>

					<UButton
						type="submit"
						:label="t('settings.account.update_password')"
						size="lg"
						color="neutral"
						:loading="passwordForm.processing"
					/>
				</form>
			</div>
	</UCard>
</template>
