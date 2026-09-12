<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js';
import AuthLayout from '@/Layouts/AuthLayout.vue'
import SeoHead from "@/components/Shared/SeoHead.vue";

const props = defineProps<{
	email: string
	status?: string | null
	pendingSocialLinkUrl?: string | null
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
	<SeoHead
		:title="t('auth.verify_email.title')"
		:description="t('meta.seo.auth.verify_email_description')"
		noindex
	/>

	<div class="w-full max-w-xl">
		<div
			class="border border-default bg-background px-8 py-8"
		>
			<div class="mb-6 flex items-start gap-4">
				<div
					class="flex h-14 w-14 shrink-0 items-center justify-center border border-default bg-muted/30 text-toned"
				>
					<UIcon name="i-lucide-mail" size="32"/>
				</div>

				<div class="min-w-0">
					<h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
						{{ t('auth.verify_email.title') }}
					</h1>
					<p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
						{{ t('auth.verify_email.subtitle') }}
					</p>
				</div>
			</div>

			<div
				v-if="wasResent"
				class="mb-6 border border-success/40 bg-success/10 px-4 py-3 text-sm text-success"
			>
				{{ t('auth.verify_email.resent_success') }}
			</div>

			<div class="space-y-4">
				<div class="border border-default bg-muted/20 p-4">
					<p class="text-sm text-gray-600 dark:text-gray-300">
						{{ t('auth.verify_email.sent_to') }}
					</p>
					<p class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
						{{ email }}
					</p>
				</div>

				<p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
					{{ t('auth.verify_email.instructions') }}
				</p>

				<div class="border border-default bg-muted/10 p-4">
					<h2 class="text-sm font-semibold text-gray-900 dark:text-white">
						{{ t('auth.verify_email.help_title') }}
					</h2>
					<ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
						<li>• {{ t('auth.verify_email.help_spam') }}</li>
						<li>• {{ t('auth.verify_email.help_delay') }}</li>
						<li>• {{ t('auth.verify_email.help_resend') }}</li>
					</ul>
				</div>
			</div>

			<Link v-if="pendingSocialLinkUrl" :href="pendingSocialLinkUrl" class="mt-6 block text-brand text-sm">
				{{ t('auth.link_social.return_to_link') }}
			</Link>
			<div class="mt-8 flex flex-col gap-3 sm:flex-row">
				<button
					type="button"
					@click="resendVerificationEmail"
					:disabled="resendForm.processing"
					class="inline-flex w-full items-center justify-center bg-brand px-5 py-3 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
				>
					{{
						resendForm.processing
							? t('auth.verify_email.resending')
							: t('auth.verify_email.resend_button')
					}}
				</button>

				<Link
					:href="route('logout')"
					method="post"
					as="button"
					class="inline-flex w-full items-center justify-center border border-default px-5 py-3 text-sm font-semibold text-toned transition hover:bg-muted/20"
				>
					{{ t('auth.back_to_login') }}
				</Link>
			</div>
		</div>
	</div>
</template>
