<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js';
import AuthLayoutV2 from '@/Layouts/AuthLayoutV2.vue'
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
	layout: AuthLayoutV2
})
</script>

<template>
	<SeoHead
		:title="t('auth.verify_email.title')"
		:description="t('meta.seo.auth.verify_email_description')"
		noindex
	/>

	<section class="space-y-5" aria-labelledby="verify-email-title">
		<header class="text-center">
			<div class="mx-auto mb-3 flex size-11 items-center justify-center rounded-xl bg-elevation-3 text-brand dark:bg-elevation-5" aria-hidden="true">
				<UIcon name="i-lucide-mail" class="size-6" />
			</div>
			<h2 id="verify-email-title" class="text-2xl font-semibold text-toned">
				{{ t('auth.verify_email.title') }}
			</h2>
			<p class="mt-2 text-sm leading-6 text-muted">
				{{ t('auth.verify_email.subtitle') }}
			</p>
		</header>

		<div class="rounded-lg bg-elevation-1 p-4 shadow-sm ring-1 ring-default/25 dark:bg-elevation-3">
			<p class="text-xs leading-5 text-muted">{{ t('auth.verify_email.sent_to') }}</p>
			<p class="mt-1 break-all text-sm font-semibold text-highlighted">{{ email }}</p>
			<p class="mt-3 border-t border-muted/40 pt-3 text-sm leading-6 text-muted">
				{{ t('auth.verify_email.instructions') }}
			</p>
		</div>

		<div v-if="wasResent" role="status" aria-live="polite">
			<UAlert
				color="success"
				variant="subtle"
				icon="i-lucide-mail-check"
				:description="t('auth.verify_email.resent_success')"
				class="rounded-lg bg-success-50 text-success-800 ring-success-200 dark:bg-success/10 dark:text-success dark:ring-success/25"
			/>
		</div>

		<section class="rounded-lg bg-elevation-3 p-4 dark:bg-elevation-2" aria-labelledby="verify-email-help-title">
			<h3 id="verify-email-help-title" class="text-sm font-semibold text-default">
				{{ t('auth.verify_email.help_title') }}
			</h3>
			<ul class="mt-2 space-y-2 text-xs leading-5 text-muted">
				<li class="flex items-start gap-2">
					<UIcon name="i-lucide-inbox" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
					<span>{{ t('auth.verify_email.help_spam') }}</span>
				</li>
				<li class="flex items-start gap-2">
					<UIcon name="i-lucide-clock-3" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
					<span>{{ t('auth.verify_email.help_delay') }}</span>
				</li>
			</ul>
			<UButton
				type="button"
				color="brand"
				size="md"
				icon="i-lucide-send"
				:loading="resendForm.processing"
				:label="t(resendForm.processing ? 'auth.verify_email.resending' : 'auth.verify_email.resend_button')"
				class="mt-4 w-full justify-center rounded-md sm:w-auto"
				:ui="{ label: 'whitespace-normal text-center' }"
				@click="resendVerificationEmail"
			/>
		</section>

		<footer class="flex flex-col items-center gap-2 pt-1 text-sm">
			<Link v-if="pendingSocialLinkUrl" :href="pendingSocialLinkUrl"
				class="rounded-md px-3 py-2 text-center text-brand transition hover:bg-elevation-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
				{{ t('auth.link_social.return_to_link') }}
			</Link>
			<Link :href="route('logout')" method="post" as="button"
				class="inline-flex items-center justify-center gap-2 rounded-md px-3 py-2 text-muted transition hover:bg-elevation-3 hover:text-default focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
				<UIcon name="i-lucide-arrow-left" class="size-4 shrink-0" aria-hidden="true" />
				{{ t('auth.back_to_login') }}
			</Link>
		</footer>
	</section>
</template>
