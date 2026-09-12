<script setup lang="ts">
import { useClipboard } from '@vueuse/core'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceReaderCommand } from '@/Types/GroupResources'

defineProps<{ commands: ResourceReaderCommand[] }>()
const { t } = useI18n()
const { copy, copied, text, isSupported } = useClipboard({ copiedDuring: 2000 })
const failed = ref(false)
async function copyCommand(name: string) {
    failed.value = false
    try { await copy(`/info ${name}`) } catch { failed.value = true }
}
</script>

<template>
    <section v-if="commands.length" id="resource-discord-commands" tabindex="-1" class="mt-8 border-t border-default pt-6" :aria-label="t('groups.resources.reader.discord_commands')">
        <h2 class="text-base font-semibold text-highlighted">{{ t('groups.resources.reader.discord_commands') }}</h2>
        <p class="mt-2 text-sm text-muted">{{ t('groups.resources.reader.discord_commands_description') }}</p>
        <ul class="mt-4 divide-y divide-default border-y border-default">
            <li v-for="command in commands" :key="command.name" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                <div class="min-w-0 flex-1"><code class="break-all text-sm text-highlighted">/info {{ command.name }}</code><p v-if="command.title" class="mt-1 break-words text-xs text-muted">{{ command.title }}</p></div>
                <UButton v-if="isSupported" :icon="copied && text === `/info ${command.name}` ? 'i-lucide-check' : 'i-lucide-copy'" :label="t(`groups.resources.reader.${copied && text === `/info ${command.name}` ? 'copied' : 'copy_command'}`)" color="neutral" variant="ghost" size="xs" @click="copyCommand(command.name)" />
            </li>
        </ul>
        <p v-if="failed" role="alert" class="mt-3 text-xs text-error">{{ t('groups.resources.reader.copy_failed') }}</p>
    </section>
</template>
