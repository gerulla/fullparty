<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const page = usePage();

const currentYear = computed(() => new Date().getFullYear());

const siteLinks = computed(() => page.props.site_links ?? {
	discord: null,
});

const links = computed(() => [
	{ label: t('navigation.footer.cookies'), href: route('legal.cookies') },
	{ label: t('navigation.footer.privacy'), href: route('legal.privacy') },
	...(siteLinks.value.discord ? [{ label: t('navigation.footer.discord'), href: siteLinks.value.discord, external: true }] : []),
]);

defineProps<{
	hasBottomNavigation?: boolean
}>();
</script>

<template>
	<footer
		class="shrink-0 border-t border-default/70 px-3 pt-4 sm:px-4 xl:px-8"
		:class="hasBottomNavigation ? 'pb-24 lg:pb-4' : 'pb-4'"
	>
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
