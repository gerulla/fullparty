<script setup lang="ts">
type LegalSection = {
	title: string
	paragraphs?: string[]
	bullets?: string[]
}

defineProps<{
	title: string
	intro: string[]
	lastUpdated: string
	sections: LegalSection[]
}>()
</script>

<template>
	<div class="rounded-3xl border border-default/70 bg-white/90 p-8 shadow-2xl shadow-neutral-300/20 backdrop-blur xl:p-12 dark:bg-neutral-900/85 dark:shadow-black/30">
		<div class="space-y-8">
			<div class="space-y-3">
				<p class="text-sm font-medium uppercase tracking-[0.18em] text-muted">
					Last updated {{ lastUpdated }}
				</p>
				<h1 class="text-4xl font-semibold tracking-tight text-highlighted xl:text-5xl">
					{{ title }}
				</h1>
				<div class="space-y-3 text-base leading-7 text-toned">
					<p v-for="paragraph in intro" :key="paragraph">
						{{ paragraph }}
					</p>
				</div>
			</div>

			<div class="space-y-8">
				<section
					v-for="section in sections"
					:key="section.title"
					class="space-y-3"
				>
					<h2 class="text-xl font-semibold text-highlighted">
						{{ section.title }}
					</h2>

					<div
						v-if="section.paragraphs && section.paragraphs.length > 0"
						class="space-y-3 text-sm leading-7 text-toned sm:text-base"
					>
						<p v-for="paragraph in section.paragraphs" :key="paragraph">
							{{ paragraph }}
						</p>
					</div>

					<ul
						v-if="section.bullets && section.bullets.length > 0"
						class="list-disc space-y-2 pl-6 text-sm leading-7 text-toned sm:text-base"
					>
						<li v-for="bullet in section.bullets" :key="bullet">
							{{ bullet }}
						</li>
					</ul>
				</section>
			</div>
		</div>
	</div>
</template>
