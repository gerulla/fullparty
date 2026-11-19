<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'

const page = usePage()
</script>

<template>
    <Teleport v-if="page.props.theme_preview_enabled === true" to="body">
        <div aria-hidden="true" class="pointer-events-none fixed bottom-3 left-3 z-[9999] rounded-full bg-neutral-950/90 px-2.5 py-1 font-mono text-xs font-semibold text-white shadow-md select-none print:hidden">
            <span class="sm:hidden">base · &lt;640</span>
            <span class="hidden sm:inline md:hidden">sm · 640–767</span>
            <span class="hidden md:inline lg:hidden">md · 768–1023</span>
            <span class="hidden lg:inline xl:hidden">lg · 1024–1279</span>
            <span class="hidden xl:inline 2xl:hidden">xl · 1280–1535</span>
            <span class="hidden 2xl:inline 3xl:hidden">2xl · 1536–1919</span>
            <span class="hidden 3xl:inline 4xl:hidden">3xl · 1920–2559</span>
            <span class="hidden 4xl:inline">4xl · 2560+</span>
        </div>
    </Teleport>
</template>
