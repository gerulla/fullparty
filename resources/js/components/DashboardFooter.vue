<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const page = usePage();

const currentYear = computed(() => new Date().getFullYear());

const siteLinks = computed(() => page.props.site_links ?? {
	discord: null,
	github: null,
});

const links = computed(() => [
	{ label: t('navigation.footer.cookies'), href: route('legal.cookies') },
	{ label: t('navigation.footer.privacy'), href: route('legal.privacy') },
	{ label: t('navigation.footer.github'), href: siteLinks.value.github ?? '#' , external: true },
	{ label: t('navigation.footer.discord'), href: siteLinks.value.discord ?? '#', external: true },
]);
</script>

<template>
	<footer class="border-t border-default/70 px-6 py-4 sm:px-8">
		<div class="flex flex-col gap-3 text-sm text-muted md:flex-row md:items-center md:justify-between">
			<nav class="flex flex-wrap items-center gap-x-5 gap-y-2">
				<a
					v-for="link in links"
					:key="link.label"
					:href="link.href"
					:target="link.external ? '_blank' : undefined"
					:rel="link.external ? 'noopener noreferrer' : undefined"
					class="inline-flex items-center gap-1.5 transition hover:text-highlighted"
				>
					{{ link.label }}
					<UIcon
						v-if="link.external"
						name="i-lucide-arrow-up-right"
						class="h-3.5 w-3.5 shrink-0"
					/>
				</a>
			</nav>

			<p class="text-sm text-muted">
				{{ t('navigation.footer.copyright', { year: currentYear }) }}
			</p>
		</div>
	</footer>
</template>
