<script setup lang="ts">
import type { DropdownMenuItem } from "@nuxt/ui";
import { computed } from "vue";
import { usePersistentLocale } from "@/composables/usePersistentLocale";
import { flagByLocaleCode } from '@/utils/localeFlags';

const props = withDefaults(defineProps<{
	variant?: string
	size?: string
	compact?: boolean
    flagOnly?: boolean
}>(), {
	variant: "ghost",
	size: "md",
	compact: false,
});

const { currentLocale, localeOptions, updateLocale } = usePersistentLocale();

const items = computed(() => localeOptions.value.map((locale) => ({
	...locale,
	flagSrc: flagByLocaleCode[locale.code] ?? null,
    ui: {
        item: locale.code === currentLocale.value
            ? "bg-elevation-2 dark:bg-elevation-3 border-l-4 border-l-primary data-highlighted:bg-elevation-4 dark:data-highlighted:bg-elevation-4"
            : "bg-elevation-1",
    },
})));

const menuUi = {
    content: "min-w-44 max-w-[calc(100vw-1rem)] bg-elevation-1 text-default rounded-md ring-default",
    arrow: "fill-elevation-1 stroke-default",
    group: "p-0",
    item: "px-4 py-3 cursor-pointer text-default border-b border-muted last:border-b-0 before:hidden transition-colors data-highlighted:bg-elevation-3 data-highlighted:text-highlighted",
    itemLabel: "text-default",
    itemTrailingIcon: "text-default",
};

const selectedLocale = computed(() => (
	items.value.find((locale) => locale.code === currentLocale.value)
	?? items.value[0]
	?? null
));

const dropdownItems = computed<DropdownMenuItem[][]>(() => [
	items.value.map((locale) => ({
		label: locale.name,
		avatar: locale.flagSrc ? { src: locale.flagSrc, alt: locale.name } : undefined,
		ui: locale.ui,
		onSelect: () => updateLocale(locale.code),
	})),
]);
</script>

<template>
	<div class="inline-flex">
		<UDropdownMenu
			v-if="compact || flagOnly"
			:items="dropdownItems"
			arrow
            :content="{ side: 'bottom', align: 'center', collisionPadding: 8 }"
            :ui="menuUi"
		>
            <template #default="{ open }">
                <UButton
                    color="neutral"
                    :variant="variant"
                    :size="size"
                    :icon="flagOnly ? undefined : 'i-lucide-languages'"
                    :aria-label="selectedLocale?.name"
					class="hover:bg-elevated rounded-md"
                    :class="{ 'bg-elevation-1 rounded-md': open }"
                >
                    <img v-if="flagOnly && selectedLocale?.flagSrc" :src="selectedLocale.flagSrc" alt=""
                        class="h-4 w-5 rounded-[2px] border border-default object-cover" />
                </UButton>
            </template>

            <template #item-leading="{ item }">
                <img
                    v-if="item.avatar?.src"
                    :src="item.avatar.src"
                    :alt="item.label"
                    class="h-4 w-5 rounded-[2px] border border-default object-cover"
                >
            </template>
		</UDropdownMenu>

		<USelectMenu
			v-else
			:model-value="currentLocale"
			:items="items"
			value-key="code"
			label-key="name"
			:search-input="false"
			:variant="variant"
			:size="size"
			arrow
            :content="{ side: 'bottom', align: 'center', collisionPadding: 8 }"
            :ui="{
                ...menuUi,
                base: 'bg-transparent text-default rounded-md hover:bg-elevated active:bg-elevated focus:bg-transparent focus:hover:bg-elevated focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary data-[state=open]:bg-elevation-1',
            }"
			@update:model-value="updateLocale"
		>
			<template #leading>
				<img
					v-if="selectedLocale?.flagSrc"
					:src="selectedLocale.flagSrc"
					:alt="selectedLocale.name"
					class="h-4 w-5 rounded-[2px] border border-default object-cover"
				>
			</template>

			<template #item-leading="{ item }">
				<img
					v-if="item.flagSrc"
					:src="item.flagSrc"
					:alt="item.name"
					class="h-4 w-5 rounded-[2px] border border-default object-cover"
				>
			</template>
		</USelectMenu>
	</div>
</template>
