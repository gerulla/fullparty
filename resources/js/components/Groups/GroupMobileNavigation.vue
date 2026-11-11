<script setup lang="ts">
import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { GroupNavigationContext } from '@/Types/GroupNavigation'
import GroupSwitcher from '@/components/Groups/GroupSwitcher.vue'
import { useGroupHomeNavigation } from '@/composables/useGroupHomeNavigation'

const props = defineProps<{ group: GroupNavigationContext }>()
const page = usePage()
const { t } = useI18n()
const open = ref(false)
const { navigation } = useGroupHomeNavigation(() => props.group)

watch([() => page.url, () => props.group.slug], () => { open.value = false })
</script>

<template>
    <USlideover v-model:open="open" side="left" :title="t('groups.index.navigation.menu')"
        :ui="{
            content: 'w-80 max-w-[calc(100vw-2rem)] bg-elevation-1 font-inter',
            header: 'min-h-16 px-4',
            body: 'space-y-4 p-4',
        }">
        <UButton color="neutral" variant="ghost" icon="i-lucide-menu"
            :aria-label="t('groups.index.navigation.menu')"
            class="size-11 shrink-0 justify-center rounded-md hover:bg-elevated" />

        <template #body>
            <div class="border-b border-default pb-4">
                <GroupSwitcher :group="group" expanded @navigate="open = false" />
            </div>
            <nav :aria-label="t('groups.index.navigation.menu')" class="space-y-2">
                <template v-for="item in navigation" :key="item.key">
                    <section v-if="item.links?.length" :aria-label="item.label" class="space-y-1 py-2">
                        <USeparator :label="item.label" :ui="{ label: 'text-xs font-semibold text-muted' }" class="mb-2" />
                        <UButton v-for="link in item.links" :key="link.key" :href="link.href"
                            :label="link.label" :icon="link.icon" color="neutral" variant="ghost"
                            :aria-current="link.active ? 'page' : undefined"
                            class="min-h-11 w-full justify-start gap-3 rounded-md px-3 text-sm hover:bg-elevation-3"
                            :class="link.active ? 'bg-brand/10 font-semibold text-brand' : 'text-default'"
                            @click="open = false" />
                    </section>
                    <UButton v-else :href="item.href" :label="item.label" :icon="item.icon" color="neutral" variant="ghost"
                        :aria-current="item.active ? 'page' : undefined"
                        class="min-h-11 w-full justify-start gap-3 rounded-md px-3 text-sm hover:bg-elevation-3"
                        :class="item.active ? 'bg-brand/10 font-semibold text-brand' : 'text-default'"
                        @click="open = false" />
                </template>
            </nav>
        </template>
    </USlideover>
</template>
