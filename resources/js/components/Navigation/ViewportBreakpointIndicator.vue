<template>
	<div
		aria-hidden="true"
		class="pointer-events-none fixed bottom-3 left-3 z-[9999] flex h-8 items-center gap-2 rounded-full border border-brand-400/45 bg-neutral-950/92 px-3 font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-200 shadow-lg shadow-black/35 backdrop-blur"
	>
		<UIcon name="i-lucide-monitor" class="size-3.5 text-brand-400" />
		<span class="sm:hidden">Base &lt;640</span>
		<span class="hidden sm:inline md:hidden">SM 640-767</span>
		<span class="hidden md:inline lg:hidden">MD 768-1023</span>
		<span class="hidden lg:inline xl:hidden">LG 1024-1279</span>
		<span class="hidden xl:inline 2xl:hidden">XL 1280-1535</span>
		<span class="hidden 2xl:inline 3xl:hidden">2XL 1536-1919</span>
		<span class="hidden 3xl:inline 4xl:hidden">3XL 1920-2559</span>
		<span class="hidden 4xl:inline">4XL 2560+</span>
	</div>
</template>
