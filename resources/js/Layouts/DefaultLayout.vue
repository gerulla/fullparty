<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
</script>

<template>
	<div class="min-h-screen">
		<UDashboardGroup>
			<CSidebar />

			<UDashboardPanel>
				<CTopbar />

				<slot />
			</UDashboardPanel>
		</UDashboardGroup>
	</div>
</template>

<style scoped>

</style>