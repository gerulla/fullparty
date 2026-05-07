<script setup>
import {usePage} from "@inertiajs/vue3";
import {computed} from "vue";
import {useI18n} from "vue-i18n";

const { t } = useI18n();
const page = usePage();

const siteLinks = computed(() => page.props.site_links ?? {
	github: null,
	discord: null,
});
</script>

<template>
	<div class="relative mt-6">
		<div class="relative rounded-2xl border border-white/10 bg-brand-900 p-4 text-sm text-white/80">
			<p class="font-semibold text-white">{{t('navigation.sidebar.notice.title')}}</p>
			<p class="mt-1 leading-5">
				{{t('navigation.sidebar.notice.description')}}
			</p>

			<div class="mt-3 flex gap-2">
				<a
					:href="siteLinks.github || '#'"
					target="_blank"
					rel="noopener noreferrer"
					class="inline-flex items-center rounded-lg bg-brand-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 transition"
				>
					{{t('navigation.sidebar.notice.github')}}
				</a>
				<a
					:href="siteLinks.discord || '#'"
					target="_blank"
					rel="noopener noreferrer"
					class="inline-flex items-center rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-white/90 hover:bg-white/5 transition"
				>
					{{t('navigation.sidebar.notice.discord')}}
				</a>
			</div>

			<div class="absolute -bottom-2 left-6 h-4 w-4 rotate-45 border-r border-b border-white/10 bg-brand-900"></div>
		</div>

		<div class="mt-4 pl-3">
			<UUser
				:ui="{
					name: 'text-white',
					description: 'text-white/60',
				}"
				name="Giki"
				:description="t('navigation.sidebar.notice.dev_tag')"
				:avatar="{
					src: 'https://img2.finalfantasyxiv.com/f/15cff6ad5af687333d4ae7545c7b4ec4_7206469080400ed57a5373d0a9c55c59fc0.jpg?1774692966',
					loading: 'lazy',
					icon: 'i-lucide-image'
				}"
				size="xl"
			/>
		</div>
	</div>
</template>

<style scoped>

</style>
