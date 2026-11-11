<script setup lang="ts">
import type { MemberNote } from "@/Types/Groups";
import { memberNoteLimits } from "@/utils/memberNoteLimits";
import { memberNotePresentation } from "@/utils/memberNotePresentation";
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useMemberNotes } from "@/composables/useMemberNotes";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import ReportButton from '@/components/Shared/Reports/ReportButton.vue';

const props = defineProps<{
	notes: ReturnType<typeof useMemberNotes>
}>();

const { t } = useI18n();
const {
	isNotesModalOpen,
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
} = props.notes;
const isNoteFormOpen = ref(false);

watch(() => member.value?.id, () => {
	isNoteFormOpen.value = false;
});

const formatDate = (value: string | null) => {
	if (!value) {
		return t('groups.members.roster.not_available');
	}

	return createDateTimeFormatter(undefined, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(value));
};

const severityBadge = (severity: MemberNote['severity']) => ({
    ...memberNotePresentation(severity),
    label: t(severity === 'commendation' ? 'groups.members.notes.severities.commendation' : `general.severity_levels.${severity}`),
});

const severityBorderClass = (severity: MemberNote['severity']) => memberNotePresentation(severity).borderClass;

const noteCardUi = {
	root: 'rounded-xl bg-elevation-1 shadow-sm ring-elevation-3 dark:bg-elevation-3 dark:shadow-none dark:ring-elevation-6',
	body: 'p-4 sm:p-5',
};
const noteInputUi = {
	base: 'rounded-lg bg-elevation-1 text-default placeholder:text-muted ring-elevation-4 shadow-xs aria-invalid:ring-error dark:bg-elevation-4 dark:ring-elevation-7 dark:shadow-none dark:aria-invalid:ring-error',
};
const noteSelectUi = {
	...noteInputUi,
	content: 'rounded-lg bg-elevation-1 shadow-lg ring-elevation-3 dark:bg-elevation-4 dark:ring-elevation-7',
	item: 'rounded-md',
};
</script>

<template>
	<UModal
		:open="isNotesModalOpen"
		:title="t('groups.members.notes.modal.title', { name: member?.name ?? '' })"
		:description="t('groups.members.notes.modal.subtitle')"
		:ui="{
			content: 'max-w-6xl rounded-xl bg-elevation-1 shadow-lg ring-elevation-3 divide-elevation-3 dark:bg-elevation-2 dark:ring-elevation-6 dark:divide-elevation-6 dark:[--ui-primary:var(--color-brand-400)]',
			header: 'p-4 sm:p-6',
			body: 'bg-elevation-2/50 p-4 dark:bg-transparent sm:p-6',
			close: 'rounded-md',
		}"
		@update:open="handleNotesModalOpenChange"
	>
		<template #actions>
			<ReportButton v-if="member?.can_report_profile" :target="{ type: 'profile', id: member.id, label: member.name }" label-key="reports.report_profile" />
		</template>
		<template #body>
			<div v-if="isLoading" class="flex flex-col gap-6">
				<div class="flex flex-wrap items-center gap-2">
					<USkeleton class="h-6 w-32 rounded-lg" />
					<USkeleton class="h-6 w-32 rounded-lg" />
					<USkeleton class="h-6 w-32 rounded-lg" />
				</div>

				<div class="flex w-full flex-col items-start justify-start gap-6 xl:flex-row">
					<section class="flex w-full flex-col gap-4 xl:w-2/5">
						<UCard :ui="noteCardUi">
							<div class="flex flex-col gap-4">
								<USkeleton class="h-10 w-full rounded-lg" />
								<USkeleton class="h-10 w-full rounded-lg" />
								<USkeleton class="h-40 w-full rounded-lg" />
								<USkeleton class="h-10 w-full rounded-lg" />
							</div>
						</UCard>
					</section>

					<section class="flex w-full min-w-0 flex-col gap-4 xl:w-3/5">
						<USkeleton class="h-32 w-full rounded-lg" />
						<USkeleton class="h-32 w-full rounded-lg" />
						<USkeleton class="h-24 w-full rounded-lg" />
					</section>
				</div>
			</div>

			<UAlert
				class="rounded-lg"
				v-else-if="hasLoadError"
				color="error"
				variant="subtle"
				icon="i-lucide-triangle-alert"
				:title="t('groups.members.notes.load_error')"
			/>

			<div v-else-if="member?.notes.can_view" class="flex flex-col gap-6">
				<div class="flex flex-wrap items-center gap-2">
					<UBadge
						class="rounded-md bg-elevation-2 text-toned ring-0 dark:bg-elevation-4"
						color="neutral"
						variant="subtle"
						:label="t('groups.members.notes.count.total', { count: totalVisibleNoteCount })"
					/>
					<UBadge
						class="rounded-md"
						color="primary"
						variant="subtle"
						:label="t('groups.members.notes.count.current_group', { count: member.notes.current_group_count })"
					/>
					<UBadge
						class="rounded-md"
						color="secondary"
						variant="subtle"
						:label="t('groups.members.notes.count.shared', { count: member.notes.shared_count })"
					/>
				</div>

				<div class="flex flex-col gap-3">
					<div v-if="member.characters.length > 0" class="flex flex-wrap gap-2">
						<div
							v-for="character in member.characters"
							:key="`member-character-${character.id}`"
							class="min-w-0 max-w-full rounded-lg border border-elevation-3 bg-elevation-1 px-3 py-2 dark:border-elevation-6 dark:bg-elevation-3 sm:min-w-48"
						>
							<UUser
								:name="character.name"
								:description="character.datacenter ? `${character.world} - ${character.datacenter}` : character.world"
								:avatar="{
									src: character.avatar_url,
									alt: character.name,
									loading: 'lazy',
									icon: 'i-lucide-image',
								}"
							/>
						</div>
					</div>

					<p v-else class="text-sm text-muted">
						{{ t('groups.members.roster.no_characters') }}
					</p>
				</div>

				<div class="flex w-full flex-col items-stretch justify-start gap-6 xl:max-h-[75vh] xl:flex-row xl:items-start">
					<section class="flex w-full flex-col gap-4 xl:w-2/5">
						<UButton
							v-if="member.notes.can_add && !isNoteFormOpen"
							color="primary"
							variant="subtle"
							icon="i-lucide-notebook-pen"
							class="justify-center rounded-md xl:hidden"
							:label="t('groups.members.notes.create.title')"
							@click="isNoteFormOpen = true"
						/>

						<UCard
							v-if="member.notes.can_add"
							:class="isNoteFormOpen ? 'block' : 'hidden xl:block'"
							:ui="noteCardUi"
						>
							<div class="flex flex-col gap-4">
								<UFormField
									:label="t('general.severity')"
									:error="noteForm.errors.severity"
								>
									<USelect
										:ui="noteSelectUi"
										v-model="noteForm.severity"
										value-key="value"
										:items="severityOptions"
										class="w-full"
									/>
								</UFormField>

								<UFormField
									orientation="horizontal"
									:label="t('groups.members.notes.fields.shared.label')"
									:description="t('groups.members.notes.fields.shared.help')"
									class="items-center"
								>
									<USwitch v-model="noteForm.is_shared_with_groups" />
								</UFormField>

								<UFormField
									:label="t('groups.members.notes.fields.body.label')"
									:help="t('groups.members.notes.fields.body.help')"
									:error="noteForm.errors.body"
								>
									<UTextarea
										:ui="noteInputUi"
										v-model="noteForm.body"
										class="w-full"
										:rows="10"
										:maxlength="memberNoteLimits.body"
										:placeholder="t('groups.members.notes.fields.body.placeholder')"
									/>
								</UFormField>

								<div class="flex justify-stretch">
									<UButton
										color="primary"
										icon="i-lucide-notebook-pen"
										class="w-full justify-center rounded-md"
										:label="t('groups.members.notes.create.submit')"
										:loading="noteForm.processing"
										@click="submitNote"
									/>
								</div>
							</div>
						</UCard>
					</section>

					<section class="flex w-full min-w-0 flex-col gap-6 xl:max-h-[75vh] xl:w-3/5 xl:overflow-y-auto xl:pr-2">
						<div class="flex flex-col gap-3">
							<div>
								<p class="text-base font-semibold text-highlighted">{{ t('groups.members.notes.sections.current_group.title') }}</p>
								<p class="text-sm text-muted">{{ t('groups.members.notes.sections.current_group.subtitle') }}</p>
							</div>

							<div v-if="member.notes.current_group.length > 0" class="flex flex-col gap-3">
								<UCard
									v-for="note in member.notes.current_group"
									:key="`group-note-${note.id}`"
									:class="severityBorderClass(note.severity)"
									:ui="noteCardUi"
								>
									<div class="flex flex-col gap-3">
										<div class="flex flex-wrap items-start justify-between gap-3">
											<div class="flex min-w-0 items-center gap-3">
												<UAvatar
													:src="note.author?.avatar_url ?? undefined"
													:alt="note.author?.name ?? t('audit_log.defaults.system')"
													icon="i-lucide-user"
												/>
												<div class="min-w-0">
													<p class="truncate font-medium text-highlighted">{{ note.author?.name ?? t('audit_log.defaults.system') }}</p>
													<p class="text-xs text-muted">{{ formatDate(note.created_at) }}</p>
												</div>
											</div>
											<div class="flex flex-wrap items-center gap-2">
												<ReportButton :target="{ type: 'member_note', id: note.id, label: t('reports.types.member_note') + ' #' + note.id }" />
												<UBadge
													class="rounded-md"
													:label="severityBadge(note.severity).label"
													:color="severityBadge(note.severity).color"
													:icon="severityBadge(note.severity).icon"
													:class="severityBadge(note.severity).badgeClass"
													variant="subtle"
												/>
												<UBadge
													class="rounded-md"
													v-if="note.is_shared_with_groups"
													color="secondary"
													variant="soft"
													icon="i-lucide-globe"
													:label="t('general.shared')"
												/>
											</div>
										</div>

										<div v-if="editingNoteId === note.id" class="flex flex-col gap-4">
											<UFormField
												:label="t('general.severity')"
												:error="noteUpdateForm.errors.severity"
											>
												<USelect
													:ui="noteSelectUi"
													v-model="noteUpdateForm.severity"
													value-key="value"
													:items="severityOptions"
													class="w-full"
												/>
											</UFormField>

											<UFormField
												orientation="horizontal"
												:label="t('groups.members.notes.fields.shared.label')"
												:description="t('groups.members.notes.fields.shared.help')"
												class="items-center"
											>
												<USwitch v-model="noteUpdateForm.is_shared_with_groups" />
											</UFormField>

											<UFormField
												:label="t('groups.members.notes.fields.body.label')"
												:error="noteUpdateForm.errors.body"
											>
												<UTextarea
													:ui="noteInputUi"
													v-model="noteUpdateForm.body"
													class="w-full"
													:rows="6"
													:maxlength="memberNoteLimits.body"
												/>
											</UFormField>

											<div class="flex flex-wrap justify-end gap-2">
												<UButton
													class="rounded-md"
													color="neutral"
													variant="ghost"
													:label="t('general.cancel')"
													@click="cancelEditNote"
												/>
												<UButton
													class="rounded-md"
													color="primary"
													icon="i-lucide-save"
													:label="t('general.save')"
													:loading="noteUpdateForm.processing"
													@click="submitNoteUpdate(note)"
												/>
											</div>
										</div>

										<p v-else class="whitespace-pre-wrap break-words text-sm leading-relaxed text-toned [overflow-wrap:anywhere]">{{ note.body }}</p>

										<div class="flex flex-wrap items-center gap-2">
											<UButton
												class="rounded-md bg-elevation-2 hover:bg-elevation-3 dark:bg-elevation-4 dark:hover:bg-elevation-5"
												v-if="note.permissions.can_edit_body"
												color="neutral"
												variant="soft"
												size="sm"
												icon="i-lucide-pencil"
												:label="t('general.edit')"
												@click="openEditNote(note)"
											/>
											<UButton
												class="rounded-md"
												v-if="note.permissions.can_add_addendum"
												color="secondary"
												variant="soft"
												size="sm"
												icon="i-lucide-message-square-plus"
												:label="t('groups.members.notes.actions.add_context')"
												@click="openAddendum(note)"
											/>
											<UButton
												class="rounded-md"
												v-if="note.permissions.can_delete"
												color="error"
												variant="ghost"
												size="sm"
												icon="i-lucide-trash-2"
												:label="t('general.delete')"
												:loading="noteDeleteForm.processing && pendingDeleteNoteId === note.id"
												@click="removeNote(note)"
											/>
										</div>

										<div v-if="note.addenda.length > 0" class="flex flex-col gap-2 border-t border-elevation-3 pt-3 dark:border-elevation-6">
											<p class="text-xs font-semibold uppercase tracking-wide text-muted">
												{{ t('groups.members.notes.addenda.title') }}
											</p>
											<div
												v-for="addendum in note.addenda"
												:key="`note-${note.id}-addendum-${addendum.id}`"
												class="rounded-lg bg-elevation-2 px-3 py-2 dark:bg-elevation-4"
											>
												<p class="text-xs text-muted">
													{{ t('groups.members.notes.addenda.byline', {
														author: addendum.author?.name ?? t('audit_log.defaults.system'),
														date: formatDate(addendum.created_at),
													}) }}
												</p>

												<div v-if="editingAddendumId === addendum.id" class="mt-2 flex flex-col gap-3">
													<UFormField
														:label="t('groups.members.notes.addenda.form.label')"
														:error="addendumUpdateForm.errors.body"
													>
														<UTextarea
															:ui="noteInputUi"
															v-model="addendumUpdateForm.body"
															class="w-full"
															:rows="4"
															:maxlength="memberNoteLimits.addendum"
															:placeholder="t('groups.members.notes.addenda.form.placeholder')"
														/>
													</UFormField>

													<div class="flex flex-wrap justify-end gap-2">
														<UButton
															class="rounded-md"
															color="neutral"
															variant="ghost"
															size="sm"
															:label="t('general.cancel')"
															@click="cancelEditAddendum"
														/>
														<UButton
															class="rounded-md"
															color="primary"
															icon="i-lucide-save"
															size="sm"
															:label="t('general.save')"
															:loading="addendumUpdateForm.processing"
															@click="submitAddendumUpdate(addendum)"
														/>
													</div>
												</div>

												<template v-else>
													<p class="mt-1 whitespace-pre-wrap break-words text-sm leading-relaxed text-toned [overflow-wrap:anywhere]">{{ addendum.body }}</p>

													<div v-if="addendum.permissions.can_edit_body || addendum.permissions.can_delete" class="mt-2 flex flex-wrap items-center gap-2">
														<UButton
															class="rounded-md bg-elevation-2 hover:bg-elevation-3 dark:bg-elevation-4 dark:hover:bg-elevation-5"
															v-if="addendum.permissions.can_edit_body"
															color="neutral"
															variant="soft"
															size="sm"
															icon="i-lucide-pencil"
															:label="t('general.edit')"
															@click="openEditAddendum(addendum)"
														/>
														<UButton
															class="rounded-md"
															v-if="addendum.permissions.can_delete"
															color="error"
															variant="ghost"
															size="sm"
															icon="i-lucide-trash-2"
															:label="t('general.delete')"
															:loading="addendumDeleteForm.processing && pendingDeleteAddendumId === addendum.id"
															@click="removeAddendum(addendum)"
														/>
													</div>
												</template>
											</div>
										</div>

										<div v-if="addendumNoteId === note.id" class="flex flex-col gap-3 rounded-lg bg-elevation-2 p-3 dark:bg-elevation-4">
											<UFormField
												:label="t('groups.members.notes.addenda.form.label')"
												:error="addendumForm.errors.body"
											>
												<UTextarea
													:ui="noteInputUi"
													v-model="addendumForm.body"
													class="w-full"
													:rows="4"
													:maxlength="memberNoteLimits.addendum"
													:placeholder="t('groups.members.notes.addenda.form.placeholder')"
												/>
											</UFormField>

											<div class="flex flex-wrap justify-end gap-2">
												<UButton
													class="rounded-md"
													color="neutral"
													variant="ghost"
													:label="t('general.cancel')"
													@click="cancelAddendum"
												/>
												<UButton
													class="rounded-md"
													color="secondary"
													icon="i-lucide-message-square-plus"
													:label="t('groups.members.notes.actions.save_context')"
													:loading="addendumForm.processing"
													@click="submitAddendum(note)"
												/>
											</div>
										</div>
									</div>
								</UCard>
							</div>

							<UAlert
								class="rounded-lg bg-elevation-2 text-toned ring-0 dark:bg-elevation-4"
								v-else
								color="neutral"
								variant="subtle"
								icon="i-lucide-notebook"
								:title="t('groups.members.notes.sections.current_group.empty')"
							/>
						</div>

						<div class="flex flex-col gap-3">
							<div>
								<p class="text-base font-semibold text-highlighted">{{ t('groups.members.notes.sections.shared.title') }}</p>
								<p class="text-sm text-muted">{{ t('groups.members.notes.sections.shared.subtitle') }}</p>
							</div>

							<div v-if="member.notes.shared.length > 0" class="flex flex-col gap-3">
								<UCard
									v-for="note in member.notes.shared"
									:key="`shared-note-${note.id}`"
									:class="severityBorderClass(note.severity)"
									:ui="noteCardUi"
								>
									<div class="flex flex-col gap-3">
										<div class="flex flex-wrap items-start justify-between gap-3">
											<div class="min-w-0">
												<p class="truncate font-medium text-highlighted">{{ note.source_group?.name ?? t('groups.members.notes.unknown_group') }}</p>
												<p class="text-xs text-muted">
													{{ t('groups.members.notes.shared_from', { author: note.author?.name ?? t('audit_log.defaults.system'), date: formatDate(note.created_at) }) }}
												</p>
											</div>
											<ReportButton :target="{ type: 'member_note', id: note.id, label: t('reports.types.member_note') + ' #' + note.id }" />
											<UBadge
												class="rounded-md"
												:label="severityBadge(note.severity).label"
												:color="severityBadge(note.severity).color"
												:icon="severityBadge(note.severity).icon"
												:class="severityBadge(note.severity).badgeClass"
												variant="subtle"
											/>
										</div>

										<p class="whitespace-pre-wrap break-words text-sm leading-relaxed text-toned [overflow-wrap:anywhere]">{{ note.body }}</p>

										<div v-if="note.addenda.length > 0" class="flex flex-col gap-2 border-t border-elevation-3 pt-3 dark:border-elevation-6">
											<p class="text-xs font-semibold uppercase tracking-wide text-muted">
												{{ t('groups.members.notes.addenda.title') }}
											</p>
											<div
												v-for="addendum in note.addenda"
												:key="`shared-note-${note.id}-addendum-${addendum.id}`"
												class="rounded-lg bg-elevation-2 px-3 py-2 dark:bg-elevation-4"
											>
												<p class="text-xs text-muted">
													{{ t('groups.members.notes.addenda.byline', {
														author: addendum.author?.name ?? t('audit_log.defaults.system'),
														date: formatDate(addendum.created_at),
													}) }}
												</p>
												<p class="mt-1 whitespace-pre-wrap break-words text-sm leading-relaxed text-toned [overflow-wrap:anywhere]">{{ addendum.body }}</p>
											</div>
										</div>
									</div>
								</UCard>
							</div>

							<UAlert
								class="rounded-lg bg-elevation-2 text-toned ring-0 dark:bg-elevation-4"
								v-else
								color="neutral"
								variant="subtle"
								icon="i-lucide-globe"
								:title="t('groups.members.notes.sections.shared.empty')"
							/>
						</div>
					</section>
				</div>
			</div>

			<UAlert
				class="rounded-lg bg-elevation-2 text-toned ring-0 dark:bg-elevation-4"
				v-else
				color="neutral"
				variant="subtle"
				icon="i-lucide-eye-off"
				:title="t('groups.members.notes.hidden')"
			/>
		</template>
	</UModal>
</template>
