<script setup lang="ts">
import { useTimeoutFn } from '@vueuse/core';
import { useToast } from '@nuxt/ui/composables';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const toast = useToast();
const repositoryUrl = 'https://raw.githubusercontent.com/gerulla/fullparty-plugin/master/repo.json';
const copied = ref(false);
const { start: resetCopied } = useTimeoutFn(() => { copied.value = false; }, 1500, { immediate: false });
const steps = ['repository', 'install', 'connect'] as const;

async function copyRepository() {
    try {
        await navigator.clipboard.writeText(repositoryUrl);
        copied.value = true;
        resetCopied();
    } catch {
        toast.add({
            title: t('settings.ffxiv_plugin.copy_failed'),
            description: t('settings.ffxiv_plugin.copy_manually'),
            color: 'error',
            icon: 'i-lucide-copy',
        });
    }
}
</script>

<template>
    <UCard class="w-full min-w-0 dark:bg-elevated/25">
        <template #header>
            <div class="flex items-start gap-3">
                <UIcon name="i-lucide-gamepad-2" class="mt-0.5 size-5 shrink-0 text-brand" />
                <div class="min-w-0">
                    <h3 class="font-semibold text-highlighted">{{ t('settings.ffxiv_plugin.title') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.ffxiv_plugin.description') }}</p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <UAlert color="brand" variant="soft" icon="i-lucide-users"
                :title="t('settings.ffxiv_plugin.audience_title')"
                :description="t('settings.ffxiv_plugin.audience_description')" />

            <section aria-labelledby="plugin-install-title">
                <h3 id="plugin-install-title" class="font-semibold text-highlighted">{{ t('settings.ffxiv_plugin.install_title') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.ffxiv_plugin.requirement') }}</p>

                <ol class="mt-5 space-y-5">
                    <li v-for="(step, index) in steps" :key="step" class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-semibold text-brand" aria-hidden="true">{{ index + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <h4 class="pt-0.5 text-sm font-semibold text-toned">{{ t(`settings.ffxiv_plugin.steps.${step}.title`) }}</h4>
                            <p class="mt-1 text-sm leading-relaxed text-muted">{{ t(`settings.ffxiv_plugin.steps.${step}.description`) }}</p>

                            <div v-if="step === 'repository'" class="mt-3 flex min-w-0 flex-col gap-3 rounded-lg bg-elevation-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                                <code class="min-w-0 select-all break-all text-xs leading-relaxed text-highlighted sm:text-sm">{{ repositoryUrl }}</code>
                                <UButton color="brand" variant="soft"
                                    :icon="copied ? 'i-lucide-copy-check' : 'i-lucide-copy'"
                                    :label="t(copied ? 'settings.ffxiv_plugin.copied' : 'settings.ffxiv_plugin.copy_repository')"
                                    class="w-full shrink-0 justify-center sm:w-auto" @click="copyRepository" />
                            </div>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
    </UCard>
</template>
