<script setup>
import {useI18n} from "vue-i18n";

const { t } = useI18n();
</script>

<template>
	<a
		href="/auth/google/redirect"
		class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
	>
		<svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
			<path
				fill="#4285F4"
				d="M21.805 10.023H12v3.955h5.617c-.242 1.273-.967 2.352-2.06 3.078v2.56h3.333c1.95-1.796 3.071-4.444 3.071-7.593c0-.674-.061-1.322-.156-2Z"
			/>
			<path
				fill="#34A853"
				d="M12 22c2.79 0 5.13-.925 6.84-2.384l-3.333-2.56c-.926.622-2.108.99-3.507.99c-2.697 0-4.98-1.82-5.797-4.268H2.76v2.64A9.997 9.997 0 0 0 12 22Z"
			/>
			<path
				fill="#FBBC05"
				d="M6.203 13.778A5.996 5.996 0 0 1 5.88 12c0-.617.112-1.214.323-1.778V7.582H2.76A9.997 9.997 0 0 0 2 12c0 1.61.386 3.13 1.06 4.418l3.143-2.64Z"
			/>
			<path
				fill="#EA4335"
				d="M12 5.955c1.517 0 2.88.522 3.952 1.548l2.964-2.964C17.125 2.88 14.785 2 12 2A9.997 9.997 0 0 0 2.76 7.582l3.443 2.64C7.02 7.775 9.303 5.955 12 5.955Z"
			/>
		</svg>

		<span>{{ t('pages.auth.continue_with')}} Google</span>
	</a>
</template>

<style scoped>

</style>