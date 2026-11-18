<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
    <div role="status" :aria-label="t('general.loading')" class="space-y-3 p-5">
        <USkeleton v-for="row in 3" :key="row" class="h-11 rounded-xl motion-reduce:animate-none" />
    </div>
</template>
