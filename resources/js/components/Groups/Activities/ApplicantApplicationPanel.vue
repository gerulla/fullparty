<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage } from '@inertiajs/vue3';
import type { QueueApplication } from '@/Types/ActivityQueue';
import type { LocalizedText } from '@/Types/Common';
import { localizedValue } from '@/utils/localizedValue';
import { preferredClassChips } from '@/utils/preferredClassChips';
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from '@/utils/characterJobTranslations';

const props = defineProps<{ application: QueueApplication }>();
const { t, locale } = useI18n();
const page = usePage();
const localizedText = (value: LocalizedText, fallback: string) =>
    localizedValue(value, locale.value, String(page.props.locale?.fallback ?? 'en')) || fallback;
const answerBadgeColor = (source: string | null, value: string) => {
	const normalized = value.trim().toLowerCase();

	if (normalized === 'yes') {
		return 'success';
	}

	if (normalized === 'no') {
		return 'error';
	}

	if (source === 'phantom_jobs') {
		return 'secondary';
	}

	if (source === 'raid_positions' || source === 'static_options') {
		return 'warning';
	}

	return 'neutral';
};

const answerValueLabel = (source: string | null, questionKey: string, value: string): string => {
    if (value === 'Yes') return t('general.yes');
    if (value === 'No') return t('general.no');
	if (source === 'character_classes') {
		return translateCharacterClassName(t, { name: value }, value);
	}

	if (source === 'phantom_jobs') {
		return translatePhantomJobName(t, { name: value }, value);
	}

	if (source === 'raid_positions' || questionKey.toLowerCase().includes('position')) {
		return translateRaidPositionName(t, { name: value }, value);
	}

	return value;
};


const detailedAnswers = computed(() => (props.application?.answers ?? [])
	.filter((answer) => {
		if (answer.display_values.length === 0) {
			return false;
		}

		if (answer.source === 'character_classes' || answer.source === 'phantom_jobs') {
			return false;
		}

		if (answer.source === 'raid_positions' || answer.source === 'static_options') {
			return !answer.question_key.toLowerCase().includes('position');
		}

		return true;
	})
	.map((answer) => ({
		key: answer.question_key,
		label: localizedText(answer.question_label, answer.question_key),
		source: answer.source,
		displayValues: answer.display_values.map((value) => answerValueLabel(answer.source, answer.question_key, value)),
	})));

const classAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'character_classes') ?? null);
const phantomAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'phantom_jobs') ?? null);
const positionAnswer = computed(() => props.application?.answers.find((answer) => (
	(answer.source === 'raid_positions' || answer.source === 'static_options')
	&& answer.question_key.toLowerCase().includes('position')
)) ?? null);
const classDisplayItems = computed(() => preferredClassChips(classAnswer.value?.display_items ?? [], classAnswer.value?.complete_roles).map((item) => ({
	...item,
	label: item.omniKey ? t(`groups.activities.management.queue.modal.inspector.omni.${item.omniKey}`) : translateCharacterClassName(t, { name: item.label }, item.label),
    tooltip: item.omniKey ? item.classNames.map(name => translateCharacterClassName(t, { name }, name)).join(', ') : undefined,
})));
const phantomDisplayItems = computed(() => (phantomAnswer.value?.display_items ?? []).map((item) => ({
	...item,
	label: translatePhantomJobName(t, { name: item.label }, item.label),
})));

</script>

<template>
    <div class="space-y-5 text-sm">
        <section v-if="classAnswer" class="space-y-3">
            <h3 class="text-xs font-medium text-muted">{{ localizedText(classAnswer.question_label, classAnswer.question_key) }}</h3>
            <div class="flex flex-wrap gap-1.5">
                <UTooltip v-for="item in classDisplayItems" :key="item.key" :text="item.tooltip" :disabled="!item.tooltip">
                    <UBadge color="neutral" variant="outline" :class="item.border" class="max-w-full gap-1.5 px-2 py-1 font-normal whitespace-normal" :tabindex="item.omniKey ? 0 : undefined">
                        <img v-if="item.icon" :src="item.icon" alt="" class="size-4 shrink-0 object-contain">
                        <span class="[overflow-wrap:anywhere]">{{ item.label }}</span>
                    </UBadge>
                </UTooltip>
                <span v-if="!classDisplayItems.length" class="text-muted">{{ classAnswer.display_values.join(', ') || '-' }}</span>
            </div>
        </section>
        <UCollapsible v-if="phantomAnswer" :default-open="phantomDisplayItems.length > 0 || phantomAnswer.display_values.length > 0" class="border-y border-default">
            <UButton color="neutral" variant="ghost" class="group w-full justify-between px-0 py-3 text-left font-normal whitespace-normal">
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ localizedText(phantomAnswer.question_label, phantomAnswer.question_key) }}</span>
                <span class="flex shrink-0 items-center gap-2">
                    <UBadge :label="String(phantomDisplayItems.length || phantomAnswer.display_values.length)" color="neutral" variant="soft" size="xs" />
                    <UIcon name="i-lucide-chevron-down" class="size-4 transition-transform group-data-[state=open]:rotate-180" />
                </span>
            </UButton>
            <template #content>
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 pb-4 text-xs">
                    <div v-for="item in phantomDisplayItems" :key="item.label" class="flex min-w-0 items-center gap-2">
                        <img v-if="item.transparent_icon_url || item.icon_url" :src="item.transparent_icon_url || item.icon_url || undefined" alt="" class="size-4 shrink-0 object-contain">
                        <span class="[overflow-wrap:anywhere]">{{ item.label }}</span>
                    </div>
                    <p v-if="!phantomDisplayItems.length" class="col-span-2">{{ phantomAnswer.display_values.join(', ') || '-' }}</p>
                </div>
            </template>
        </UCollapsible>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-5">
            <div v-if="positionAnswer" class="min-w-0">
                <dt class="mb-1.5 text-xs text-muted">{{ localizedText(positionAnswer.question_label, positionAnswer.question_key) }}</dt>
                <dd class="[overflow-wrap:anywhere]">{{ positionAnswer.display_values.map(value => answerValueLabel(positionAnswer.source, positionAnswer.question_key, value)).join(', ') }}</dd>
            </div>
            <div v-for="answer in detailedAnswers" :key="answer.key" class="min-w-0">
                <dt class="mb-1.5 text-xs text-muted [overflow-wrap:anywhere]">{{ answer.label }}</dt>
                <dd class="flex flex-wrap gap-x-2 gap-y-1">
                    <span v-for="value in answer.displayValues" :key="value" class="[overflow-wrap:anywhere]" :class="{ 'text-success': answerBadgeColor(answer.source, value) === 'success' }">{{ value }}</span>
                </dd>
            </div>
        </dl>
        <p v-if="!detailedAnswers.length && !positionAnswer" class="text-muted">{{ t('groups.activities.management.queue.modal.no_answers') }}</p>
        <section class="space-y-2 border-t border-default pt-4">
            <h3 class="text-xs font-medium text-muted">{{ t('groups.activities.management.queue.modal.inspector.application_note') }}</h3>
            <p class="whitespace-pre-line leading-relaxed text-toned [overflow-wrap:anywhere]">{{ application.notes || t('groups.activities.management.queue.modal.no_notes') }}</p>
        </section>
    </div>
</template>
