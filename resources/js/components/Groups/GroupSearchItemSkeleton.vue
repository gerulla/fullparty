<template>
	<UCard
		aria-hidden="true"
		class="flex h-full w-full min-w-0 max-w-128 flex-col divide-y-0 overflow-hidden rounded-xl border border-neutral-200 bg-elevation-1 shadow-sm ring-0 dark:border-default dark:bg-elevation-2 dark:shadow-none"
		:ui="{
			header: 'relative h-32 shrink-0 p-0 sm:p-0',
			body: 'flex flex-1 flex-col gap-5 px-5 pt-12 pb-5 sm:px-6 sm:pt-12 sm:pb-5',
			footer: 'px-5 pt-0 pb-5 sm:px-6 sm:pt-0 sm:pb-6',
		}"
	>
		<template #header>
			<USkeleton class="absolute inset-0 rounded-none" />
			<USkeleton class="absolute top-4 right-4 h-6 w-24 rounded-md" />
			<USkeleton class="absolute -bottom-8 left-5 z-10 size-18 rounded-lg ring-4 ring-elevation-1 sm:left-6 dark:ring-elevation-2" />
		</template>
		<div class="space-y-3">
			<USkeleton class="h-6 w-40 max-w-full rounded" />
			<USkeleton class="h-3 w-24 rounded" />
		</div>
		<div class="min-h-12 space-y-3">
			<USkeleton class="h-3 w-full rounded" />
			<USkeleton class="h-3 w-3/4 rounded" />
		</div>
		<div class="space-y-2 rounded-lg bg-elevation-2 p-3 dark:bg-elevation-3">
			<USkeleton class="h-3 w-20 rounded" />
			<div class="flex items-center gap-2">
				<USkeleton class="size-7 shrink-0 rounded-full" />
				<div class="min-w-0 flex-1 space-y-1">
					<USkeleton class="h-4 w-28 max-w-full rounded" />
					<USkeleton class="h-3 w-14 rounded" />
				</div>
			</div>
		</div>
		<div class="mt-auto flex flex-wrap gap-1.5">
			<USkeleton class="h-5 w-20 rounded-md" />
			<USkeleton class="h-5 w-16 rounded-md" />
			<USkeleton class="h-5 w-20 rounded-md" />
		</div>
		<template #footer>
			<div class="grid grid-cols-[1fr_1fr_1.2fr] gap-3 border-t border-neutral-200 pt-5 dark:border-default">
				<div v-for="index in 3" :key="index" class="flex flex-col items-center gap-2">
					<USkeleton class="h-2 w-12 max-w-full rounded" />
					<USkeleton class="h-4 w-10 max-w-full rounded" />
				</div>
			</div>
		</template>
	</UCard>
</template>
