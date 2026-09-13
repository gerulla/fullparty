<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { ReportTarget } from '@/Types/Reports'
import { useReportModal } from '@/composables/useReportModal'

const props = withDefaults(defineProps<{ target: ReportTarget; entryUrl?: string; labelKey?: string }>(), { labelKey: 'reports.report' })
const page = usePage()
const { t } = useI18n()
const reports = useReportModal()
const authenticated = computed(() => Boolean((page.props.auth as { user?: { id: number } } | undefined)?.user?.id))
const guestSubmitUrl = computed(() => ['resource', 'holster', 'upload'].includes(props.target.type)
    ? (page.props.reporting as { guest_submit_url?: string } | undefined)?.guest_submit_url : undefined)
const href = computed(() => authenticated.value || guestSubmitUrl.value ? undefined : props.entryUrl ?? route('reports.create', { type: props.target.type, id: props.target.id }))
function open() {
    if (authenticated.value || guestSubmitUrl.value) void reports.open(props.target, { guestSubmitUrl: guestSubmitUrl.value })
}
</script>

<template>
    <UButton type="button" :href="href" color="neutral" variant="outline" size="sm" icon="i-lucide-flag"
        :label="t(labelKey)" class="shrink-0" @click.stop="open" />
</template>
