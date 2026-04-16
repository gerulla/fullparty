<script setup lang="ts">
import LandingLayout from "@/Layouts/LandingLayout.vue";
import { Head, router } from "@inertiajs/vue3";

const goToLogin = () => {
	router.get('/auth/login');
};
</script>

<template>
	<Head title="Under Construction -" />

	<div class="rounded-3xl border border-default/70 bg-white/85 p-8 shadow-2xl shadow-neutral-300/30 backdrop-blur xl:p-12 dark:bg-neutral-900/80 dark:shadow-black/30">
		<div class="flex flex-col gap-6">
			<div class="flex items-center gap-3">
				<UBadge color="warning" variant="subtle" label="Alpha" />
				<p class="text-sm font-medium uppercase tracking-[0.22em] text-muted">
					Website under construction
				</p>
			</div>

			<div class="flex flex-col gap-4">
				<h1 class="max-w-2xl text-4xl font-semibold tracking-tight text-highlighted xl:text-5xl">
					FullParty is still being built.
				</h1>
				<p class="max-w-2xl text-base leading-7 text-toned xl:text-lg">
					The public website is not ready yet. If you're one of the current testers, you can log in below and keep using the app.
				</p>
			</div>

			<div class="flex flex-col gap-3 pt-2 sm:flex-row">
				<UButton
					size="xl"
					color="neutral"
					icon="i-lucide-log-in"
					label="Tester Login"
					@click="goToLogin"
				>
				</UButton>
			</div>
		</div>
	</div>
</template>

<script lang="ts">
export default {
	layout: LandingLayout,
};
</script>
