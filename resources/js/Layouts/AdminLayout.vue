<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import V2Layout from './V2Layout.vue'
import AdminNavigation from '@/components/Admin/AdminNavigation.vue'
import WelcomeOnboardingModal from '@/components/Home/WelcomeOnboardingModal.vue'
import { adminUi, adminUiProps } from '@/components/Admin/adminTheme'
import { useXivAuthConflictToast } from '@/composables/useXivAuthConflictToast'
import { useAdminNavigation } from '@/composables/useAdminNavigation'

const page = usePage()
const { current } = useAdminNavigation()
const showNavigation = computed(() => page.props.auth?.user?.is_admin && page.component !== 'Admin/Index')
useXivAuthConflictToast()
</script>

<template>
    <UTheme :ui="adminUi" :props="adminUiProps">
        <V2Layout>
            <Head :title="current.label" />
            <div class="admin-theme mx-auto grid w-full max-w-[108rem] items-start gap-5 xl:gap-8"
                :class="{ 'xl:grid-cols-[14rem_minmax(0,1fr)]': showNavigation }">
                <AdminNavigation v-if="showNavigation" />
                <div class="min-w-0"><slot /></div>
            </div>
            <WelcomeOnboardingModal />
        </V2Layout>
    </UTheme>
</template>
