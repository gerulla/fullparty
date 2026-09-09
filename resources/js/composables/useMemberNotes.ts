// @ts-ignore
import type { MemberNote, MemberNoteAddendum, MemberNotesTarget } from "@/Types/Groups";
import axios from "axios";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, toValue, type MaybeRefOrGetter } from "vue";
import { useI18n } from "vue-i18n";
// @ts-ignore
import { route } from "ziggy-js";
import { memberNotePresentation } from "@/utils/memberNotePresentation";

type UseMemberNotesOptions = {
	groupSlug: MaybeRefOrGetter<string>
	onLoaded?: (member: MemberNotesTarget) => void
};

export const useMemberNotes = (options: UseMemberNotesOptions) => {
	const { t } = useI18n();
	const toast = useToast();
	const isNotesModalOpen = ref(false);
	const activeNotesUserId = ref<number | null>(null);
	const member = ref<MemberNotesTarget | null>(null);
	const isLoading = ref(false);
	const hasLoadError = ref(false);
	const activeRequestId = ref(0);
	const editingNoteId = ref<number | null>(null);
	const addendumNoteId = ref<number | null>(null);
	const editingAddendumId = ref<number | null>(null);
	const pendingDeleteNoteId = ref<number | null>(null);
	const pendingDeleteAddendumId = ref<number | null>(null);

	const noteForm = useForm({
		severity: 'info',
		body: '',
		is_shared_with_groups: false,
	});
	const noteUpdateForm = useForm({
		severity: 'info' as MemberNote['severity'],
		body: '',
		is_shared_with_groups: false,
	});
	const addendumForm = useForm({
		body: '',
	});
	const addendumUpdateForm = useForm({
		body: '',
	});
	const noteDeleteForm = useForm({});
	const addendumDeleteForm = useForm({});

	const severityOptions = computed(() => [
		{ label: t('groups.members.notes.severities.commendation'), value: 'commendation', icon: memberNotePresentation('commendation').icon },
		{ label: t('general.severity_levels.info'), value: 'info', icon: memberNotePresentation('info').icon },
		{ label: t('general.severity_levels.warning'), value: 'warning', icon: memberNotePresentation('warning').icon },
		{ label: t('general.severity_levels.critical'), value: 'critical', icon: memberNotePresentation('critical').icon },
	]);

	const totalVisibleNoteCount = computed(() => {
		if (!member.value?.notes) {
			return 0;
		}

		return member.value.notes.current_group_count + member.value.notes.shared_count;
	});

	const showSuccessToast = (description: string) => {
		toast.add({
			title: t('general.success'),
			description,
			color: 'success',
			icon: 'i-lucide-check',
		});
	};

	const resetNoteForm = () => {
		noteForm.reset();
		noteForm.severity = 'info';
		noteForm.is_shared_with_groups = false;
		noteForm.clearErrors();
	};

	const cancelEditNote = () => {
		editingNoteId.value = null;
		noteUpdateForm.reset();
		noteUpdateForm.severity = 'info';
		noteUpdateForm.is_shared_with_groups = false;
		noteUpdateForm.clearErrors();
	};

	const cancelAddendum = () => {
		addendumNoteId.value = null;
		addendumForm.reset();
		addendumForm.clearErrors();
	};

	const cancelEditAddendum = () => {
		editingAddendumId.value = null;
		addendumUpdateForm.reset();
		addendumUpdateForm.clearErrors();
	};

	const resetTransientState = () => {
		resetNoteForm();
		cancelEditNote();
		cancelAddendum();
		cancelEditAddendum();
		pendingDeleteNoteId.value = null;
		pendingDeleteAddendumId.value = null;
	};

	const resetLoadedState = () => {
		activeRequestId.value += 1;
		isLoading.value = false;
		hasLoadError.value = false;
		member.value = null;
	};

	const loadMemberNotes = async (userId: number) => {
		const requestId = activeRequestId.value + 1;
		activeRequestId.value = requestId;
		resetTransientState();
		member.value = null;
		hasLoadError.value = false;
		isLoading.value = true;

		try {
			const response = await axios.get(route('groups.members.notes.show', [toValue(options.groupSlug), userId]));

			if (activeRequestId.value !== requestId) {
				return;
			}

			member.value = response.data?.member ?? null;
			if (member.value) options.onLoaded?.(member.value);
		} catch {
			if (activeRequestId.value !== requestId) {
				return;
			}

			member.value = null;
			hasLoadError.value = true;
		} finally {
			if (activeRequestId.value === requestId) {
				isLoading.value = false;
			}
		}
	};

	const reloadMemberNotes = async () => {
		if (activeNotesUserId.value === null) {
			return;
		}

		await loadMemberNotes(activeNotesUserId.value);
	};

	const openMemberNotes = (userId: number) => {
		activeNotesUserId.value = userId;
		isNotesModalOpen.value = true;
		void loadMemberNotes(userId);
	};

	const closeMemberNotes = () => {
		isNotesModalOpen.value = false;
		activeNotesUserId.value = null;
		resetLoadedState();
		resetTransientState();
	};

	const handleNotesModalOpenChange = (open: boolean) => {
		if (!open) {
			closeMemberNotes();
		}
	};

	const openEditNote = (note: MemberNote) => {
		editingNoteId.value = note.id;
		addendumNoteId.value = null;
		cancelEditAddendum();
		noteUpdateForm.severity = note.severity;
		noteUpdateForm.body = note.body;
		noteUpdateForm.is_shared_with_groups = note.is_shared_with_groups;
		noteUpdateForm.clearErrors();
	};

	const submitNoteUpdate = (note: MemberNote) => {
		noteUpdateForm.put(route('groups.members.notes.update', [toValue(options.groupSlug), note.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_updated'));
				cancelEditNote();
				void reloadMemberNotes();
			},
		});
	};

	const openAddendum = (note: MemberNote) => {
		addendumNoteId.value = note.id;
		editingNoteId.value = null;
		cancelEditAddendum();
		addendumForm.body = '';
		addendumForm.clearErrors();
	};

	const openEditAddendum = (addendum: MemberNoteAddendum) => {
		editingAddendumId.value = addendum.id;
		addendumNoteId.value = null;
		editingNoteId.value = null;
		addendumUpdateForm.body = addendum.body;
		addendumUpdateForm.clearErrors();
	};

	const submitAddendum = (note: MemberNote) => {
		addendumForm.post(route('groups.members.notes.addenda.store', [toValue(options.groupSlug), note.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_addendum_added'));
				cancelAddendum();
				void reloadMemberNotes();
			},
		});
	};

	const submitAddendumUpdate = (addendum: MemberNoteAddendum) => {
		addendumUpdateForm.put(route('groups.members.notes.addenda.update', [toValue(options.groupSlug), addendum.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_addendum_updated'));
				cancelEditAddendum();
				void reloadMemberNotes();
			},
		});
	};

	const removeAddendum = (addendum: MemberNoteAddendum) => {
		pendingDeleteAddendumId.value = addendum.id;

		addendumDeleteForm.delete(route('groups.members.notes.addenda.destroy', [toValue(options.groupSlug), addendum.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_addendum_deleted'));

				if (editingAddendumId.value === addendum.id) {
					cancelEditAddendum();
				}

				void reloadMemberNotes();
			},
			onFinish: () => {
				pendingDeleteAddendumId.value = null;
			},
		});
	};

	const removeNote = (note: MemberNote) => {
		pendingDeleteNoteId.value = note.id;

		noteDeleteForm.delete(route('groups.members.notes.destroy', [toValue(options.groupSlug), note.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_deleted'));

				if (editingNoteId.value === note.id) {
					cancelEditNote();
				}

				if (addendumNoteId.value === note.id) {
					cancelAddendum();
				}

				void reloadMemberNotes();
			},
			onFinish: () => {
				pendingDeleteNoteId.value = null;
			},
		});
	};

	const submitNote = () => {
		if (!member.value) {
			return;
		}

		noteForm.post(route('groups.members.notes.store', [toValue(options.groupSlug), member.value.id]), {
			onSuccess: () => {
				showSuccessToast(t('groups.members.toasts.note_added'));
				resetNoteForm();
				void reloadMemberNotes();
			},
		});
	};

	return {
		isNotesModalOpen,
		activeNotesUserId,
		member,
		isLoading,
		hasLoadError,
		totalVisibleNoteCount,
		severityOptions,
		noteForm,
		noteUpdateForm,
		addendumForm,
		addendumUpdateForm,
		noteDeleteForm,
		addendumDeleteForm,
		editingNoteId,
		addendumNoteId,
		editingAddendumId,
		pendingDeleteNoteId,
		pendingDeleteAddendumId,
		openMemberNotes,
		closeMemberNotes,
		handleNotesModalOpenChange,
		openEditNote,
		cancelEditNote,
		submitNoteUpdate,
		openAddendum,
		cancelAddendum,
		submitAddendum,
		openEditAddendum,
		cancelEditAddendum,
		submitAddendumUpdate,
		removeAddendum,
		removeNote,
		submitNote,
	};
};
