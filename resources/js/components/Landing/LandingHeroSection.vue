<script setup lang="ts">
import AppLocaleSelect from "@/components/Navigation/AppLocaleSelect.vue"
import { router, usePage } from "@inertiajs/vue3"
import { route } from "ziggy-js"
import { computed, onMounted, onUnmounted, ref } from "vue"
import { useI18n } from "vue-i18n"

const { t } = useI18n()
const page = usePage()
const mobileMenuOpen = ref(false)
const hasScrolled = ref(false)
const isAuthenticated = computed(() => Boolean(page.props.auth?.user))

const heroStyle = {
	backgroundImage: 'url("/landing.png")',
}
const logoUrl = "/logo_white.png"
const discordUrl = computed(() => {
	const siteLinks = page.props.site_links as { discord?: string | null } | undefined

	return siteLinks?.discord ?? null
})
const trustBadges = [
	{ key: "ffxiv", icon: "i-lucide-gamepad-2" },
	{ key: "raiders", icon: "i-lucide-shield-check" },
	{ key: "communities", icon: "i-lucide-users" },
]
const navItems = [
	{ key: "features", sectionId: "features" },
	{ key: "this_week", sectionId: "this-week" },
	{ key: "players", sectionId: "players" },
	{ key: "leaders", sectionId: "leaders" },
]

const goToHome = () => {
	router.get(route("home"))
}

const goToLogin = () => {
	router.get(route("login"))
}

const goToRegister = () => {
	router.get(route("register"))
}

const goToAccount = () => {
	router.get(route("dashboard"))
}

const startFirstRun = () => {
	goToRegister()
}

const closeMobileMenu = () => {
	mobileMenuOpen.value = false
}

const scrollSectionIntoView = (sectionId: string) => {
	document.getElementById(sectionId)?.scrollIntoView({
		behavior: "smooth",
		block: "start",
	})
}

const scrollToSection = (sectionId: string) => {
	if (mobileMenuOpen.value) {
		closeMobileMenu()
		window.setTimeout(() => scrollSectionIntoView(sectionId), 250)

		return
	}

	scrollSectionIntoView(sectionId)
}

const openDiscord = () => {
	if (!discordUrl.value) {
		return
	}

	closeMobileMenu()
	window.open(discordUrl.value, "_blank", "noopener,noreferrer")
}

const goToLoginFromMenu = () => {
	closeMobileMenu()
	goToLogin()
}

const goToRegisterFromMenu = () => {
	closeMobileMenu()
	goToRegister()
}

const goToAccountFromMenu = () => {
	closeMobileMenu()
	goToAccount()
}

const updateHasScrolled = () => {
	hasScrolled.value = window.scrollY > 12
}

onMounted(() => {
	updateHasScrolled()
	window.addEventListener("scroll", updateHasScrolled, { passive: true })
})

onUnmounted(() => {
	window.removeEventListener("scroll", updateHasScrolled)
})
</script>

<template>
	<section
		class="relative flex min-h-screen bg-cover bg-center bg-no-repeat"
		:style="heroStyle"
	>
		<div class="absolute inset-0 bg-neutral-950/55" />
		<div class="absolute inset-0 bg-linear-to-r from-neutral-950/90 via-neutral-950/45 to-neutral-950/20" />
		<div class="absolute inset-x-0 bottom-0 h-48 bg-linear-to-t from-neutral-950 to-transparent" />

		<header
			class="fixed inset-x-0 top-0 z-20 flex items-center justify-between gap-4 px-4 py-4 transition-colors duration-200 sm:px-6 lg:absolute lg:grid lg:grid-cols-[1fr_auto_1fr] lg:bg-transparent lg:px-10 lg:py-5 lg:backdrop-blur-none"
			:class="hasScrolled ? 'bg-neutral-950/70 backdrop-blur' : 'bg-transparent backdrop-blur-none'"
		>
			<div class="flex min-w-0 items-center justify-start">
				<button
					type="button"
					class="flex shrink-0 items-center"
					:aria-label="t('landing.nav.home')"
					@click="goToHome"
				>
					<img
						:src="logoUrl"
						alt="FullParty"
						class="h-12 w-auto lg:h-14"
					>
				</button>
			</div>

			<nav class="hidden items-center justify-center gap-5 text-md font-medium text-neutral-200 lg:flex">
				<a
					v-for="item in navItems"
					:key="item.key"
					:href="`#${item.sectionId}`"
					class="transition hover:text-white"
					@click.prevent="scrollToSection(item.sectionId)"
				>
					{{ t(`landing.nav.${item.key}`) }}
				</a>
				<a
					:href="discordUrl ?? '#'"
					target="_blank"
					rel="noopener noreferrer"
					class="transition hover:text-white"
					:class="{ 'pointer-events-none opacity-60': !discordUrl }"
				>
					{{ t("landing.nav.discord") }}
				</a>
			</nav>

			<div class="hidden shrink-0 items-center justify-end gap-1 sm:gap-2 lg:flex">
				<AppLocaleSelect variant="ghost" />
				<UButton
					v-if="isAuthenticated"
					color="neutral"
					variant="solid"
					icon="i-lucide-user-round"
					:label="t('landing.nav.my_account')"
					@click="goToAccount"
				/>
				<template v-else>
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('auth.login')"
						class="text-neutral-100 hover:text-white"
						@click="goToLogin"
					/>
					<UButton
						color="neutral"
						variant="solid"
						:label="t('auth.register')"
						@click="goToRegister"
					/>
				</template>
			</div>

			<UButton
				color="neutral"
				variant="ghost"
				icon="i-lucide-menu"
				size="xl"
				class="text-white hover:bg-white/10 lg:hidden"
				:aria-label="t('landing.nav.menu')"
				@click="mobileMenuOpen = true"
			/>
		</header>

		<USlideover
			v-model:open="mobileMenuOpen"
			side="right"
			:title="t('landing.nav.menu')"
			:ui="{ content: 'max-w-sm bg-neutral-950/98 ring-white/10', header: 'border-b border-white/10', body: 'p-0 sm:p-0' }"
		>
			<template #body>
				<div class="flex min-h-full flex-col px-5 py-5">
					<nav class="grid gap-2 text-base font-semibold text-neutral-100">
						<button
							v-for="item in navItems"
							:key="item.key"
							type="button"
							class="flex items-center justify-between border border-white/10 bg-white/[0.03] px-4 py-3 text-left transition hover:border-violet-300/40 hover:bg-violet-500/10"
							@click="scrollToSection(item.sectionId)"
						>
							<span>{{ t(`landing.nav.${item.key}`) }}</span>
							<UIcon name="i-lucide-arrow-down" class="size-4 text-violet-300" />
						</button>
						<button
							type="button"
							class="flex items-center justify-between border border-white/10 bg-white/[0.03] px-4 py-3 text-left transition hover:border-violet-300/40 hover:bg-violet-500/10 disabled:pointer-events-none disabled:opacity-60"
							:disabled="!discordUrl"
							@click="openDiscord"
						>
							<span>{{ t("landing.nav.discord") }}</span>
							<UIcon name="i-lucide-external-link" class="size-4 text-violet-300" />
						</button>
					</nav>

					<div class="mt-8 border-t border-white/10 pt-6">
						<AppLocaleSelect variant="ghost" />
						<div class="mt-5 grid gap-3">
							<UButton
								v-if="isAuthenticated"
								color="primary"
								block
								icon="i-lucide-user-round"
								:label="t('landing.nav.my_account')"
								@click="goToAccountFromMenu"
							/>
							<template v-else>
								<UButton
									color="neutral"
									variant="outline"
									block
									:label="t('auth.login')"
									class="border-white/20 text-white hover:bg-white/10"
									@click="goToLoginFromMenu"
								/>
								<UButton
									color="primary"
									block
									:label="t('auth.register')"
									@click="goToRegisterFromMenu"
								/>
							</template>
						</div>
					</div>
				</div>
			</template>
		</USlideover>

		<div class="relative z-10 flex w-full items-end px-6 pb-28 pt-32 lg:px-10 lg:pb-36">
			<div class="max-w-4xl">
				<UBadge
					color="primary"
					variant="soft"
					icon="i-lucide-sparkles"
					:label="t('landing.hero.badge')"
					class="bg-violet-500/20 px-4 py-2 text-sm font-semibold text-violet-100 ring-violet-300/25"
				/>

				<h1 class="landing-display-font mt-6 max-w-4xl text-5xl font-semibold leading-tight text-white sm:text-6xl lg:text-7xl">
					{{ t("landing.hero.title_prefix") }}
					<span class="text-violet-300">{{ t("landing.hero.title_accent") }}</span>
				</h1>
				<p class="mt-6 max-w-2xl text-base leading-8 text-neutral-200 sm:text-lg">
					{{ t("landing.hero.subtitle") }}
				</p>
				<div class="mt-8 flex flex-col gap-3 sm:flex-row">
					<UButton
						size="xl"
						color="primary"
						icon="i-lucide-calendar-plus"
						:label="t('landing.hero.primary_action')"
						@click="startFirstRun"
					/>
					<UButton
						size="xl"
						color="neutral"
						variant="outline"
						icon="i-lucide-search"
						:label="t('landing.hero.secondary_action')"
						class="border-white/25 bg-white/5 text-white hover:bg-white/10"
						@click="scrollToSection('features')"
					/>
				</div>

				<div class="mt-7 flex flex-wrap gap-2">
					<UBadge
						v-for="badge in trustBadges"
						:key="badge.key"
						color="neutral"
						variant="soft"
						:icon="badge.icon"
						:label="t(`landing.hero.trust_badges.${badge.key}`)"
						class="bg-white/10 text-neutral-100 ring-white/15"
					/>
				</div>
			</div>
		</div>

		<div class="absolute inset-x-0 bottom-8 z-10 px-6 lg:px-10">
			<div class="mx-auto flex max-w-4xl items-center gap-3 text-center text-sm font-semibold text-violet-200 sm:gap-5">
				<div class="h-px flex-1 bg-linear-to-r from-transparent via-violet-400/60 to-violet-400/20" />
				<UIcon name="i-lucide-sparkle" class="h-4 w-4 shrink-0 text-violet-300" />
				<p class="landing-display-font max-w-[14rem] text-sm leading-6 tracking-[0.12em] sm:max-w-none sm:shrink-0 sm:text-base">
					{{ t("landing.hero.separator") }}
				</p>
				<UIcon name="i-lucide-sparkle" class="h-4 w-4 shrink-0 text-violet-300" />
				<div class="h-px flex-1 bg-linear-to-l from-transparent via-violet-400/60 to-violet-400/20" />
			</div>
		</div>
	</section>
</template>

<style>
@import url("https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap");

.landing-display-font {
	font-family: "Sora", ui-sans-serif, system-ui, sans-serif;
}
</style>
