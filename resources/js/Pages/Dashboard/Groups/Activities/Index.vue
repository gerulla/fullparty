<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ActivityMonthCalendar from '@/components/Groups/Activities/ActivityMonthCalendar.vue';
import type { ActivityIndexItem, GroupQuickCreateShortcut } from '@/Types/ActivityCore';
import { localizedValue } from '@/utils/localizedValue';

const props = defineProps<{
    group: {
        slug: string
        permissions: { can_manage_activities: boolean }
    }
    activities: ActivityIndexItem[]
    quickCreateShortcuts: GroupQuickCreateShortcut[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const search = ref('');
const selectedDateKey = ref<string | null>(null);
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const filteredActivities = computed(() => {
    const query = search.value.trim().toLocaleLowerCase(locale.value);
    if (!query) return props.activities;

    return props.activities.filter((activity) => [
        activity.title,
        localizedValue(activity.activity_type?.draft_name, locale.value, fallbackLocale.value),
        activity.activity_type?.slug,
    ].some((value) => value?.toLocaleLowerCase(locale.value).includes(query)));
});

const searchUi = {
    base: 'rounded-lg bg-elevation-1 text-default placeholder:text-muted ring-elevation-4 shadow-sm hover:ring-elevation-5 focus-visible:ring-2 focus-visible:ring-brand-700 dark:bg-elevation-3 dark:ring-elevation-7 dark:shadow-none dark:hover:ring-elevation-9 dark:focus-visible:ring-brand-400',
    leadingIcon: 'text-muted',
};
</script>

<template>
    <ActivityMonthCalendar
        class="w-full"
        :group-slug="group.slug"
        :activities="filteredActivities"
        :selected-date-key="selectedDateKey"
        :can-manage-activities="group.permissions.can_manage_activities"
        :quick-create-shortcuts="quickCreateShortcuts"
        @update-selected-date-key="selectedDateKey = $event"
    >
        <template #header-actions>
            <UInput
                v-model="search"
                icon="i-lucide-search"
                :placeholder="t('groups.activities.calendar.search_placeholder')"
                :aria-label="t('groups.activities.calendar.search_placeholder')"
                class="hidden w-72 min-w-0 shrink sm:inline-flex"
                :ui="searchUi"
            />
            <UPopover class="sm:hidden">
                <UButton
                    icon="i-lucide-search"
                    :color="search ? 'brand' : 'neutral'"
                    variant="ghost"
                    class="shrink-0 rounded-lg"
                    :aria-label="t('groups.activities.calendar.search_placeholder')"
                />
                <template #content>
                    <div class="p-3">
                        <UInput
                            v-model="search"
                            autofocus
                            icon="i-lucide-search"
                            :placeholder="t('groups.activities.calendar.search_placeholder')"
                            :aria-label="t('groups.activities.calendar.search_placeholder')"
                            class="w-64 max-w-[calc(100vw-4rem)]"
                            :ui="searchUi"
                        />
                    </div>
                </template>
            </UPopover>
        </template>
    </ActivityMonthCalendar>
</template>
