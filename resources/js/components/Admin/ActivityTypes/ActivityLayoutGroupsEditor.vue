<script setup lang="ts">
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

type LayoutGroup = {
	key: string
	label: Record<string, string>
	size: number
}

const props = defineProps<{
	modelValue: LayoutGroup[]
	locales: string[]
}>();

const emit = defineEmits<{
	'update:modelValue': [value: LayoutGroup[]]
}>();

const { t } = useI18n();

const totalSlots = computed(() => props.modelValue.reduce((total, group) => total + Number(group.size || 0), 0));

const createGroup = (): LayoutGroup => ({
	key: '',
	label: Object.fromEntries(props.locales.map((locale) => [locale, ''])),
	size: 8,
});

const addGroup = () => {
	emit('update:modelValue', [...props.modelValue, createGroup()]);
};

const updateGroup = (index: number, updates: Partial<LayoutGroup>) => {
	emit('update:modelValue', props.modelValue.map((group, groupIndex) => (
		groupIndex === index ? { ...group, ...updates } : group
	)));
};

const removeGroup = (index: number) => {
	emit('update:modelValue', props.modelValue.filter((_, groupIndex) => groupIndex !== index));
};

const updateGroupLabel = (index: number, label: Record<string, string>) => {
	const currentGroup = props.modelValue[index];
	const fallbackLocale = props.locales[0] ?? 'en';
	const previousPrimaryLabel = currentGroup?.label?.[fallbackLocale] ?? '';
	const nextPrimaryLabel = label?.[fallbackLocale] ?? '';
	const previousGeneratedKey = slugify(previousPrimaryLabel);
	const nextGeneratedKey = slugify(nextPrimaryLabel);

	updateGroup(index, {
		label,
		key: !currentGroup?.key || currentGroup.key === previousGeneratedKey
			? nextGeneratedKey
			: currentGroup.key,
	});
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
				<div>
					<h2 class="text-lg font-semibold">{{ t('admin.activity_types.layout.title') }}</h2>
					<p class="text-sm text-muted">{{ t('admin.activity_types.layout.subtitle') }}</p>
				</div>

				<div class="flex items-center gap-2">
					<UBadge color="primary" variant="subtle" :label="t('admin.activity_types.layout.groups_count', { count: modelValue.length })" />
					<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.layout.total_slots', { count: totalSlots })" />
					<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.layout.add_group')" @click="addGroup" />
				</div>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<UCard
				v-for="(group, index) in modelValue"
				:key="`group-${index}`"
				class="border border-default"
			>
				<div class="flex flex-col gap-4">
					<div class="flex items-center justify-between">
						<div>
							<h3 class="font-semibold">{{ t('admin.activity_types.layout.group_title', { index: index + 1 }) }}</h3>
							<p class="text-sm text-muted">{{ t('admin.activity_types.layout.group_hint') }}</p>
						</div>

						<UButton
							color="error"
							variant="ghost"
							icon="i-lucide-trash-2"
							:label="t('general.remove')"
							@click="removeGroup(index)"
						/>
					</div>

					<div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_180px]">
						<UFormField :label="t('admin.activity_types.layout.group_key')" required>
							<UInput
								:model-value="group.key"
								class="w-full"
								:placeholder="t('admin.activity_types.layout.group_key_placeholder')"
								@update:model-value="(value) => updateGroup(index, { key: value })"
							/>
						</UFormField>

						<UFormField :label="t('admin.activity_types.layout.group_size')" required>
							<UInput
								:model-value="String(group.size ?? 8)"
								type="number"
								min="1"
								class="w-full"
								@update:model-value="(value) => updateGroup(index, { size: Number(value) || 1 })"
							/>
						</UFormField>
					</div>

					<LocalizedTextFields
						:model-value="group.label"
						:locales="locales"
						:label="t('admin.activity_types.layout.group_label')"
						:description="t('admin.activity_types.layout.group_label_help')"
						:placeholder-prefix="t('admin.activity_types.layout.group_label_placeholder')"
						@update:model-value="(value) => updateGroupLabel(index, value)"
					/>
				</div>
			</UCard>
		</div>
	</UCard>
</template>
