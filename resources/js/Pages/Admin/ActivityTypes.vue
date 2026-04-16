<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { watch } from "vue";

defineProps<{
	activityTypes: Array<any>
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) {
			return;
		}

		if (success.includes('activity_type_created')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('activity_type_updated')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}
	},
	{ immediate: true }
);

const destroyActivityType = (activityTypeId: number) => {
	if (!window.confirm(t('admin.activity_types.delete_confirm'))) {
		return;
	}

	router.delete(`/admin/activity-types/${activityTypeId}`);
};

const goToCreatePage = () => {
	router.get('/admin/activity-types/create');
};

const goToEditPage = (activityTypeId: number) => {
	router.get(`/admin/activity-types/${activityTypeId}/edit`);
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.activity_types.title')"
			:subtitle="t('admin.activity_types.subtitle')"
		>
			<UButton
				color="neutral"
				class="w-full cursor-pointer rounded-none"
				icon="i-lucide-plus"
				:label="t('admin.activity_types.create')"
				@click.stop="goToCreatePage"
			/>
		</PageHeader>

		<div class="mt-6 flex flex-col gap-4">
			<UCard
				v-for="activityType in activityTypes"
				:key="activityType.id"
				class="dark:bg-elevated/25"
			>
				<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
					<div class="flex flex-col gap-2">
						<div class="flex flex-wrap items-center gap-3">
							<h2 class="text-lg font-semibold text-highlighted">
								{{ localizedValue(activityType.draft_name, locale) || activityType.slug }}
							</h2>
							<UBadge
								:color="activityType.is_active ? 'success' : 'neutral'"
								variant="subtle"
								:label="activityType.is_active ? t('admin.activity_types.status_active') : t('admin.activity_types.status_inactive')"
							/>
							<UBadge
								v-if="activityType.current_published_version"
								color="primary"
								variant="subtle"
								:label="t('admin.activity_types.version_badge', { version: activityType.current_published_version.version })"
							/>
						</div>

						<p class="text-sm text-muted">
							{{ activityType.slug }}
						</p>

						<p class="text-sm text-toned">
							{{ localizedValue(activityType.draft_description, locale) || t('admin.activity_types.no_description') }}
						</p>
					</div>

					<div class="flex items-center gap-2">
						<UButton
							color="neutral"
							variant="soft"
							icon="i-lucide-pencil"
							:label="t('general.edit')"
							@click="goToEditPage(activityType.id)"
						/>

						<UButton
							color="error"
							variant="soft"
							icon="i-lucide-trash-2"
							:label="t('general.delete')"
							@click="destroyActivityType(activityType.id)"
						/>
					</div>
				</div>
			</UCard>

			<UCard v-if="activityTypes.length === 0" class="dark:bg-elevated/25">
				<div class="py-8 text-center text-sm text-muted">
					{{ t('admin.activity_types.empty') }}
				</div>
			</UCard>
		</div>
	</div>
</template>
