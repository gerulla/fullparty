<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import GroupDashboardPreviewControls from '@/components/Groups/GroupDashboardPreviewControls.vue'
import GroupHomeDashboard from '@/components/Groups/Dashboard/GroupHomeDashboard.vue'
import type { GroupDashboardGroup, GroupDashboardView } from '@/Types/Groups'

const props = defineProps<{ group: GroupDashboardGroup }>()
const page = usePage()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const defaultView = computed<GroupDashboardView>(() =>
    ['owner', 'admin', 'moderator'].includes(props.group.current_user_role ?? '') ? 'staff' : 'user',
)
const previewView = ref<GroupDashboardView | null>(null)
const view = computed<GroupDashboardView>({
    get: () => canPreview.value ? previewView.value ?? defaultView.value : defaultView.value,
    set: value => { if (canPreview.value) previewView.value = value },
})

watch(() => props.group.slug, () => { previewView.value = null })
</script>

<template>
    <GroupDashboardPreviewControls v-if="canPreview" v-model:view="view" :active="view !== defaultView" />
    <div class="w-full" :data-dashboard-view="view">
        <GroupHomeDashboard :key="group.slug" :group="group" :view="view" />
    </div>
</template>
