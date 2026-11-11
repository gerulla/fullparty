<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import GroupDashboardHeader from '@/components/Groups/Dashboard/GroupDashboardHeader.vue'
import type { GroupDashboardHeaderGroup } from '@/Types/GroupNavigation'
import { groupDashboardPageTitleKey } from '@/utils/groupDashboardPageTitle'

const page = usePage()
const { t } = useI18n()
const group = computed(() => page.props.group_dashboard as GroupDashboardHeaderGroup | null)
const pageTitle = computed(() => t(groupDashboardPageTitleKey(page.component)))
</script>

<template>
    <V2Layout>
        <template #group-header>
            <GroupDashboardHeader v-if="group" :key="group.id" :group="group" :page-title="pageTitle" />
        </template>
        <slot />
    </V2Layout>
</template>
