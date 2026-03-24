<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js';
import AuthLayout from '@/Layouts/AuthLayout.vue'

const props = defineProps<{
	email: string
	status?: string | null
}>()

const { t } = useI18n({ useScope: 'global' })

const resendForm = useForm({})

const resendVerificationEmail = () => {
	resendForm.post(route('verification.send'))
}

const wasResent = computed(() => props.status === 'verification-link-sent')

defineOptions({
	layout: AuthLayout
})
</script>

<template>
	<div class="w-full max-w-xl">
		<div
			class="rounded-xl border border-gray-200 bg-white/90 p-8 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/90"
		>
			<div class="mb-6 flex items-start gap-4">
				<div
					class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-brand-700 dark:bg-brand-900/50 dark:text-brand-300"
				>
					<UIcon name="i-lucide-mail" size="32"/>
				</div>

				<div class="min-w-0">
					<h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
						{{ t('pages.verify_email.title') }}
					</h1>
					<p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
						{{ t('pages.verify_email.subtitle') }}
					</p>
				</div>
			</div>

			<div
				v-if="wasResent"
				class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300"
			>
				{{ t('pages.verify_email.resent_success') }}
			</div>

			<div class="space-y-4">
				<div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/60">
					<p class="text-sm text-gray-600 dark:text-gray-300">
						{{ t('pages.verify_email.sent_to') }}
					</p>
					<p class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
						{{ email }}
					</p>
				</div>

				<p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
					{{ t('pages.verify_email.instructions') }}
				</p>

				<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
					<h2 class="text-sm font-semibold text-gray-900 dark:text-white">
						{{ t('pages.verify_email.help_title') }}
					</h2>
					<ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
						<li>• {{ t('pages.verify_email.help_spam') }}</li>
						<li>• {{ t('pages.verify_email.help_delay') }}</li>
						<li>• {{ t('pages.verify_email.help_resend') }}</li>
					</ul>
				</div>
			</div>

			<div class="mt-8 flex flex-col gap-3 sm:flex-row">
				<button
					type="button"
					@click="resendVerificationEmail"
					:disabled="resendForm.processing"
					class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
				>
					{{
						resendForm.processing
							? t('pages.verify_email.resending')
							: t('pages.verify_email.resend_button')
					}}
				</button>

				<Link
					:href="route('logout')"
					method="post"
					as="button"
					class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
				>
					{{ t('pages.verify_email.logout_button') }}
				</Link>
			</div>
		</div>
	</div>
</template>