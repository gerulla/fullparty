<script setup lang="ts">
import AppLocaleSelect from "@/components/Navigation/AppLocaleSelect.vue";
import AppearanceToggle from '@/components/Navigation/AppearanceToggle.vue'
import { useI18n } from 'vue-i18n'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
import { useUiTheme } from '@/composables/useUiTheme'
import { useAppearance } from '@/composables/useAppearance'
import AuthPreviewMenu from '@/components/Auth/AuthPreviewMenu.vue'
import { useAuthPreview } from '@/composables/useAuthPreview'
import { watch } from 'vue'

const { previewTheme } = useUiTheme('medas')

const char1 = '/ft.jpg'
const logoUrl = '/logos/full.png'

const { t } = useI18n({ useScope: 'global' })
const { currentUiLocale } = usePersistentLocale();
const { isDark } = useAppearance()
const { enabled: previewEnabled, screen: previewScreen, active: previewActive, standalone: previewStandalone, component: previewComponent, props: previewProps } = useAuthPreview()

watch(previewStandalone, standalone => previewTheme(standalone ? 'classic' : 'medas'))
</script>

<template>
	<UApp :locale="currentUiLocale" :class="{ 'font-inter': !previewStandalone }">
		<div v-if="previewStandalone" inert>
			<component :is="previewComponent" :key="previewScreen" v-bind="previewProps" />
		</div>
		<div v-else class="relative min-h-screen overflow-hidden bg-elevation-2 text-default font-inter dark:bg-default">
			<div class="absolute inset-0 lg:hidden">
				<img class="h-full w-full object-cover" :src="char1" alt="">
				<div class="absolute inset-0 bg-neutral-950/62" />
				<div class="absolute inset-0 bg-linear-to-t from-neutral-950 via-neutral-950/35 to-transparent" />
			</div>

			<div class="relative z-10 flex min-h-screen flex-col lg:grid lg:grid-cols-12">
				<div class="hidden lg:col-span-7 lg:block">
					<img class="h-full min-h-screen w-full object-cover" :src="char1" alt="">
				</div>

				<div class="flex min-h-screen items-center justify-center overflow-y-auto px-4 py-20 sm:px-6 lg:col-span-5 lg:bg-elevation-2 lg:px-10 lg:py-12 dark:lg:bg-default">
					<div class="w-full max-w-md border border-default bg-elevation-2 p-5 shadow-2xl shadow-neutral-950/30 backdrop-blur-md sm:p-7 lg:max-w-lg lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none lg:backdrop-blur-none dark:bg-default/82 dark:lg:bg-transparent">
						<div class="mb-6 w-full text-center lg:text-left">
							<h1 class="mx-auto w-56 max-w-full sm:w-64 lg:mx-0">
								<span class="flex w-full" :class="{ 'bg-brand-950 mask-contain mask-center mask-no-repeat': !isDark }"
									:style="isDark ? undefined : { maskImage: `url(${logoUrl})` }">
									<img :src="logoUrl" :alt="t('meta.title')" class="h-auto w-full object-contain" :class="{ 'opacity-0': !isDark }">
								</span>
							</h1>
						</div>
						<template v-if="previewActive">
							<p class="mb-4 rounded-md border border-default bg-elevated p-3 text-xs text-muted" role="status">
								{{ t('auth.preview.notice') }}
							</p>
							<div inert>
								<component :is="previewComponent" :key="previewScreen" v-bind="previewProps" />
							</div>
						</template>
						<slot v-else />
					</div>
				</div>
			</div>

			<div class="auth-appearance-controls fixed top-0 right-0 z-30 flex items-center gap-2 p-4">
				<AppearanceToggle />
				<AppLocaleSelect variant="ghost" />
			</div>
		</div>
		<div v-if="previewEnabled" class="fixed left-0 z-30 p-4" :class="previewStandalone ? 'bottom-0' : 'top-0'">
			<AuthPreviewMenu v-model="previewScreen" :standalone="previewStandalone" />
		</div>
	</UApp>
</template>

<style scoped>
@reference '../../css/app.css';

@variant max-lg {
    .auth-appearance-controls :deep(button[data-slot='base']) {
        color: var(--color-white);
        background-color: color-mix(in oklab, var(--color-neutral-950) 60%, transparent);
    }

    .auth-appearance-controls :deep(button[data-slot='base']:is(:hover, :active, [data-state='open'])) {
        background-color: color-mix(in oklab, var(--color-neutral-900) 85%, transparent);
    }
}

.auth-appearance-controls :deep([data-slot='leadingIcon']),
.auth-appearance-controls :deep([data-slot='trailingIcon']) {
    color: inherit;
}
</style>
