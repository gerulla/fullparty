import { computed, defineAsyncComponent, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { AuthPreviewScreen } from '@/Types/AuthPreview'

const resetPassword = defineAsyncComponent(() => import('@/Pages/auth/ResetPassword.vue'))
const verifyEmail = defineAsyncComponent(() => import('@/Pages/auth/VerifyEmail.vue'))
const linkSocial = defineAsyncComponent(() => import('@/Pages/auth/LinkSocial.vue'))
const pluginDevice = defineAsyncComponent(() => import('@/Pages/auth/XivPlugin/DeviceCode.vue'))
const pluginAuthorize = defineAsyncComponent(() => import('@/Pages/auth/XivPlugin/Authorize.vue'))
const banned = defineAsyncComponent(() => import('@/Pages/auth/Banned.vue'))

export function useAuthPreview() {
    const page = usePage()
    const { t } = useI18n({ useScope: 'global' })
    const enabled = computed(() => page.props.theme_preview_enabled === true)
    const screen = ref<AuthPreviewScreen>('live')
    const active = computed(() => enabled.value && screen.value !== 'live')
    const standalone = computed(() => active.value && screen.value === 'banned')
    const component = computed(() => {
        if (screen.value === 'reset_password') return resetPassword
        if (screen.value.startsWith('verify_email')) return verifyEmail
        if (screen.value === 'plugin_authorize') return pluginAuthorize
        if (screen.value.startsWith('plugin_')) return pluginDevice
        if (screen.value === 'banned') return banned
        return linkSocial
    })
    const props = computed(() => {
        const email = 'preview@example.invalid'
        if (screen.value === 'reset_password') return { email, token: 'preview-not-a-reset-token' }
        if (screen.value.startsWith('verify_email')) {
            return { email, status: screen.value === 'verify_email_resent' ? 'verification-link-sent' : null }
        }
        if (screen.value === 'plugin_authorize') {
            return {
                client: { id: 'preview-client', name: 'FullParty XIV Plugin' },
                scopes: [{ id: 'xivplugin:read', description: t('xivplugin.scopes.xivplugin_read') }],
                authToken: 'preview-not-an-authorization-token',
                state: null,
                userCode: 'ABCD-EFGH',
            }
        }
        if (screen.value.startsWith('plugin_')) {
            return {
                prefilledUserCode: screen.value === 'plugin_invalid' ? 'ABCD-EFGH' : '',
                codeError: screen.value === 'plugin_invalid' ? t('xivplugin.device.invalid_code') : null,
                status: screen.value === 'plugin_approved' ? 'authorization-approved'
                    : screen.value === 'plugin_denied' ? 'authorization-denied' : null,
            }
        }
        if (screen.value === 'banned') {
            return { discordUrl: page.props.site_links?.discord ?? 'https://example.invalid/discord' }
        }
        return {
            email,
            token: '0'.repeat(64),
            provider: 'Discord',
            expired: screen.value === 'link_social_expired',
            verificationRequired: screen.value === 'link_social_verification',
            canComplete: screen.value === 'link_social_ready',
        }
    })

    return { enabled, screen, active, standalone, component, props }
}
