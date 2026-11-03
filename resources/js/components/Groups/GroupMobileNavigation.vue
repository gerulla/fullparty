<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
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
                    <section v-if="item.links?.length" :aria-label="item.label" class="py-2">
                        <h2 class="mb-1 flex items-center gap-2 px-3 text-xs font-semibold text-muted">
                            <UIcon :name="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                            {{ item.label }}
                        </h2>
                        <Link v-for="link in item.links" :key="link.key" :href="link.href"
                            :aria-current="link.active ? 'page' : undefined"
                            class="flex min-h-11 items-center gap-3 rounded-md px-3 py-2.5 transition-colors hover:bg-elevation-3 focus-visible:outline-2 focus-visible:outline-brand"
                            :class="link.active ? 'bg-brand/10 text-brand' : 'text-default'"
                            @click="open = false">
                            <UIcon :name="link.icon" class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {{ link.label }}
                                    <UBadge v-if="link.badge" :label="link.badge" color="neutral" variant="soft" size="xs" class="rounded-full" />
                                </span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted">{{ link.description }}</span>
                            </span>
                        </Link>
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
