<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupNavigationContext, GroupNavigationSharedData } from '@/Types/GroupNavigation'
import type { GroupRole } from '@/Types/Groups'

const props = defineProps<{ group: GroupNavigationContext }>()
const emit = defineEmits<{ navigate: [] }>()
const page = usePage()
const { t, locale } = useI18n()
const open = ref(false)
const currentLocale = computed(() => String(page.props.locale?.current ?? locale.value))
const roleOrder: Record<GroupRole, number> = { owner: 0, admin: 1, moderator: 2, member: 3 }
const groups = computed(() => {
    const navigation = page.props.navigation as GroupNavigationSharedData | undefined
    const links = navigation?.group_quick_links
    const unique = new Map([...(links?.my ?? []), ...(links?.joined ?? [])].map(group => [group.slug, group]))
    return [...unique.values()].sort((left, right) =>
        roleOrder[left.role ?? 'member'] - roleOrder[right.role ?? 'member']
        || left.name.localeCompare(right.name, locale.value),
    )
})
const selectedGroup = computed(() => groups.value.find(group => group.slug === props.group.slug))
const name = computed(() => selectedGroup.value?.name ?? props.group.name ?? props.group.slug)
const avatar = computed(() => selectedGroup.value?.profile_picture_url ?? props.group.profile_picture_url ?? undefined)
const role = computed(() => selectedGroup.value?.role ?? props.group.current_user_role)
const roleIcon = (role?: string | null) => role === 'owner'
    ? 'i-lucide-crown'
    : role === 'admin' || role === 'moderator' ? 'i-lucide-shield' : undefined

function navigate(href: string) {
    open.value = false
    emit('navigate')
    router.visit(href)
}

const items = computed<DropdownMenuItem[][]>(() => [
    groups.value.map(group => ({
        label: group.name,
        avatar: { src: group.profile_picture_url ?? undefined, alt: group.name, icon: 'i-lucide-users' },
        role: group.role,
        roleIcon: roleIcon(group.role),
        slot: 'group',
        class: group.slug === props.group.slug ? 'bg-elevation-3 font-semibold' : undefined,
        onSelect: () => navigate(route('groups.dashboard', { group: group.slug, locale: currentLocale.value })),
    })),
    [
        { label: t('groups.index.navigation.create_group'), icon: 'i-lucide-circle-plus',
            onSelect: () => navigate(route('groups.create', { locale: currentLocale.value })) },
        { label: t('groups.index.navigation.find_groups'), icon: 'i-lucide-search',
            onSelect: () => navigate(route('groups.index', { locale: currentLocale.value })) },
    ],
].filter(group => group.length > 0))

watch(() => page.url, () => { open.value = false })
</script>

<template>
    <UDropdownMenu v-model:open="open" :items="items"
        :content="{ side: 'bottom', align: 'start', collisionPadding: 12 }"
        :ui="{
            content: 'w-(--reka-dropdown-menu-trigger-width) min-w-56 max-w-[calc(100vw-1.5rem)] rounded-lg bg-elevation-1 text-default ring-default font-inter',
            item: 'min-h-11 gap-3 rounded-md px-3 py-2 text-default before:hidden cursor-pointer data-highlighted:bg-elevation-3 data-highlighted:text-highlighted',
            itemLabel: 'truncate text-inherit',
            itemLeadingIcon: 'size-5 text-inherit',
            itemLeadingAvatar: 'shrink-0 rounded-lg',
            itemLeadingAvatarSize: 'lg',
        }">
        <UButton color="neutral" variant="ghost" block
            :aria-label="t('groups.index.navigation.switch_group', { group: name })"
            class="min-w-0 justify-start gap-3 rounded-lg p-2 text-highlighted hover:bg-elevation-3 data-[state=open]:bg-elevation-3">
            <UAvatar :src="avatar" :alt="name" icon="i-lucide-users" size="lg" :ui="{ root: 'shrink-0 rounded-lg' }" />
            <span class="min-w-0 flex-1 truncate text-left text-sm font-semibold">{{ name }}</span>
            <UTooltip v-if="roleIcon(role)" :text="t(`groups.common.roles.${role}`)" :delay-duration="0">
                <span class="inline-flex shrink-0 text-inherit" role="img" :aria-label="t(`groups.common.roles.${role}`)">
                    <UIcon :name="roleIcon(role)" class="size-4" aria-hidden="true" />
                </span>
            </UTooltip>
            <UIcon name="i-lucide-chevrons-up-down" class="size-4 shrink-0 text-muted" aria-hidden="true" />
        </UButton>

        <template #group-trailing="{ item }">
            <UTooltip v-if="item.roleIcon" :text="t(`groups.common.roles.${item.role}`)" :delay-duration="0">
                <span class="inline-flex shrink-0 text-inherit" role="img" :aria-label="t(`groups.common.roles.${item.role}`)">
                    <UIcon :name="item.roleIcon" class="size-4" aria-hidden="true" />
                </span>
            </UTooltip>
        </template>
    </UDropdownMenu>
</template>
