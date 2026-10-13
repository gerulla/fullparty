<script setup lang="ts">
import RunCardSkeleton from '@/components/Home/RunCardSkeleton.vue'
</script>

<template>
    <div aria-hidden="true" class="relative min-w-0">
        <div class="absolute -top-12 right-0 flex items-center gap-2">
            <USkeleton v-for="arrow in 2" :key="arrow" class="size-8 rounded-md bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
        </div>
        <div class="-m-1 overflow-hidden p-1">
            <div class="-ms-4 flex">
                <div v-for="card in 10" :key="card" class="min-w-0 max-w-full shrink-0 basis-[19rem] ps-4">
                    <RunCardSkeleton />
                </div>
            </div>
        </div>
    </div>
</template>
