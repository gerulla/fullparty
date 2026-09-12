<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import SeoHead from '@/components/Shared/SeoHead.vue'
import LoginWithGoogle from '@/components/LoginWithGoogle.vue'
import LoginWithDiscord from '@/components/LoginWithDiscord.vue'
import LoginWithXIVAuth from '@/components/LoginWithXIVAuth.vue'
import { usePasswordVisibility } from '@/composables/usePasswordVisibility'

const props = defineProps<{
    token: string
    provider: string | null
    email: string | null
    expired: boolean
    verificationRequired: boolean
    canComplete: boolean
}>()

defineOptions({ layout: AuthLayout })

const { t } = useI18n()
const passwordVisibility = usePasswordVisibility(['password'] as const)
const form = useForm({ login: props.email ?? '', password: '', remember: false })
const action = useForm({})
const page = usePage()
const linkError = computed(() => (form.errors as Record<string, string>).link ?? (action.errors as Record<string, string>).link ?? page.props.errors?.link)
const authenticationError = computed(() => (action.errors as Record<string, string>).login ?? page.props.errors?.login)
const submit = () => form.post(route('social-link.login', props.token), { onFinish: () => form.reset('password') })
const complete = () => action.post(route('social-link.complete', props.token))
const cancel = () => action.delete(route('social-link.cancel', props.token))
</script>

<template>
    <SeoHead :title="t('auth.link_social.title', { provider: provider ?? '' })" noindex />
    <section class="space-y-5">
        <div class="space-y-2">
            <UIcon name="i-lucide-link" class="size-6 text-brand" />
            <h2 class="text-xl font-semibold text-highlighted">
                {{ expired ? t('auth.link_social.expired_title') : t('auth.link_social.title', { provider }) }}
            </h2>
            <p class="text-sm leading-6 text-muted">
                {{ expired ? t('auth.link_social.expired') : t('auth.link_social.description', { provider }) }}
            </p>
        </div>

        <UAlert v-if="linkError || authenticationError" color="error" variant="subtle"
            icon="i-lucide-circle-alert" :description="linkError || authenticationError" />

        <template v-if="!expired">
            <template v-if="verificationRequired || canComplete">
                <p class="text-sm leading-6 text-muted">{{ t(canComplete ? 'auth.link_social.verified' : 'auth.link_social.verify_first') }}</p>
                <UButton v-if="verificationRequired" :to="route('verification.notice')" color="brand"
                    icon="i-lucide-mail" size="xl" class="w-full justify-center">
                    {{ t('auth.verify_email.title') }}
                </UButton>
                <UButton v-else color="brand" icon="i-lucide-link" size="xl" class="w-full justify-center"
                    :loading="action.processing" @click="complete">
                    {{ t('auth.link_social.complete', { provider }) }}
                </UButton>
            </template>

            <template v-else>
                <form class="space-y-4" @submit.prevent="submit">
                    <UFormField name="login" :label="t('general.email_or_username')" :error="form.errors.login">
                        <UInput v-model="form.login" name="login" autocomplete="username" size="xl" class="w-full" required />
                    </UFormField>
                    <UFormField name="password" :label="t('auth.password')" :error="form.errors.password">
                        <UInput v-model="form.password" name="password" autocomplete="current-password" required
                            :type="passwordVisibility.inputType('password')" size="xl" class="w-full" :ui="{ trailing: 'pe-1' }">
                            <template #trailing>
                                <UButton type="button" color="neutral" variant="ghost" size="sm"
                                    :icon="passwordVisibility.icon('password')" :aria-label="t('auth.password')"
                                    @click="passwordVisibility.toggle('password')" />
                            </template>
                        </UInput>
                    </UFormField>
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <UCheckbox v-model="form.remember" :label="t('auth.remember_me')" />
                        <Link :href="route('password.request')" class="text-brand">{{ t('auth.forgot_password') }}</Link>
                    </div>
                    <UButton type="submit" color="brand" size="xl" icon="i-lucide-link" class="w-full justify-center"
                        :loading="form.processing" :ui="{ label: 'whitespace-normal text-center' }">
                        {{ t('auth.link_social.submit', { provider }) }}
                    </UButton>
                </form>

                <USeparator :label="t('auth.link_social.other_method')"
                    :ui="{ container: 'max-w-[80%] shrink-0', label: 'whitespace-normal text-center' }" />
                <div class="space-y-2">
                    <LoginWithGoogle :href="route('google.redirect', { link: token })" />
                    <LoginWithDiscord :href="route('discord.redirect', { link: token })" />
                    <LoginWithXIVAuth :href="route('xivauth.redirect', { link: token })" />
                </div>
                <p class="text-xs leading-5 text-muted">{{ t('auth.link_social.social_consent', { provider }) }}</p>
            </template>
        </template>

        <UButton color="neutral" variant="ghost" class="w-full justify-center" :loading="action.processing" @click="cancel">
            {{ expired ? t('auth.back_to_login') : t('auth.link_social.cancel') }}
        </UButton>
    </section>
</template>
