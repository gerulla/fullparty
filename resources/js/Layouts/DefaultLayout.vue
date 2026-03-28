<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";

defineProps({
	title: {
		type: String,
		default: "Title"
	}
});
</script>

<template>
	<div class="min-h-screen">
		<UDashboardGroup>
			<CSidebar />

			<UDashboardPanel>
				<CTopbar :title="title" />

				<slot />
			</UDashboardPanel>
		</UDashboardGroup>
	</div>
</template>

<style scoped>

</style>