import axios from 'axios';
import { onScopeDispose, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import type { MemberNotePayload } from '@/Types/Groups';

export function useApplicantNotes(context: () => {
    groupSlug: string
    activityId: number
    applicationId: number | null
    enabled: boolean
}) {
    const notes = ref<MemberNotePayload | null>(null);
    const isLoading = ref(false);
    const hasError = ref(false);
    let request: AbortController | null = null;

    const load = async () => {
        const current = context();
        if (!current.enabled || !current.applicationId) return;
        request?.abort();
        const controller = new AbortController();
        request = controller;
        isLoading.value = true;
        hasError.value = false;
        try {
            const response = await axios.get<{ notes: MemberNotePayload }>(route(
                'groups.dashboard.activities.applicant-queue.application-notes',
                { group: current.groupSlug, activity: current.activityId, application: current.applicationId },
            ), { signal: controller.signal });
            if (request === controller) notes.value = response.data.notes;
        } catch (error) {
            if (request === controller && !axios.isCancel(error)) hasError.value = true;
        } finally {
            if (request === controller) isLoading.value = false;
        }
    };

    watch(() => {
        const value = context();
        return [value.groupSlug, value.activityId, value.applicationId, value.enabled] as const;
    }, () => {
        request?.abort();
        request = null;
        notes.value = null;
        isLoading.value = false;
        hasError.value = false;
        void load();
    }, { immediate: true });
    onScopeDispose(() => request?.abort());

    return { notes, isLoading, hasError, reload: load };
}
