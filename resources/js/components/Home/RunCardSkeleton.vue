<script setup lang="ts">
</script>

<template>
    <div aria-hidden="true" class="flex w-72 max-w-full flex-col gap-1 rounded-2xl p-2">
        <div class="relative h-36 w-full overflow-hidden rounded-2xl">
            <USkeleton class="h-full w-full rounded-2xl bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
            <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2">
                <USkeleton class="h-6 w-24 rounded-md bg-elevation-5 dark:bg-elevation-7 motion-reduce:animate-none" />
                <USkeleton class="h-6 w-16 rounded-md bg-elevation-5 dark:bg-elevation-7 motion-reduce:animate-none" />
            </div>
            <USkeleton class="absolute bottom-3 left-1/2 h-3 w-2/3 -translate-x-1/2 rounded bg-elevation-5 dark:bg-elevation-7 motion-reduce:animate-none" />
        </div>
        <div class="flex h-6 items-center">
            <USkeleton class="h-4 w-4/5 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
        </div>
        <div class="flex min-h-10 flex-col justify-center gap-1.5">
            <USkeleton class="h-3.5 w-11/12 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
            <USkeleton class="h-3.5 w-1/3 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
        </div>
        <div class="flex h-5 items-center justify-between gap-2">
            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                <USkeleton class="size-4 shrink-0 rounded-full bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
                <USkeleton class="h-3 w-20 max-w-full rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <div class="flex -space-x-1">
                    <USkeleton v-for="avatar in 3" :key="avatar" class="size-4 rounded-full bg-elevation-3 ring-1 ring-elevation-2 dark:bg-elevation-5 dark:ring-elevation-1 motion-reduce:animate-none" />
                </div>
                <USkeleton class="h-3 w-16 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
            </div>
        </div>
    </div>
</template>
