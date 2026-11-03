<script setup lang="ts">
import type { GroupSettingsGroup } from "@/Types/GroupSettings";
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{ group: GroupSettingsGroup }>();

const { t } = useI18n();
const toast = useToast();
const modalOpen = ref(false);
const isDeleting = ref(false);

const confirmDelete = () => {
    if (!props.group.permissions.can_manage_group) {
        return;
    }

    isDeleting.value = true;

    router.delete(route('groups.destroy', props.group.slug), {
        onSuccess: () => {
            toast.add({
                title: t('general.success'),
                description: t('groups.settings.danger_zone.toasts.deleted'),
                color: 'success',
                icon: 'i-lucide-trash-2',
            });
        },
        onFinish: () => {
            isDeleting.value = false;
            modalOpen.value = false;
        },
    });
};
</script>

<template>
    <UCard class="w-full border border-error/30 dark:bg-elevated/25">
        <template #header>
            <div class="flex flex-col gap-1">
                <p class="font-semibold text-md text-error">{{ t('groups.settings.danger_zone.title') }}</p>
                <p class="text-sm text-muted">{{ t('groups.settings.danger_zone.subtitle') }}</p>
            </div>
        </template>

        <div class="flex flex-col gap-6">
            <UAlert
                v-if="!group.permissions.can_manage_group"
                color="warning"
                variant="subtle"
                icon="i-lucide-shield-alert"
                :title="t('groups.settings.danger_zone.owner_only_notice')"
            />

            <div class="rounded-lg border border-error/40 bg-error/10 px-4 py-4">
                <p class="font-medium text-toned">{{ t('groups.settings.danger_zone.warning_title') }}</p>
                <p class="mt-1 text-sm text-muted">{{ t('groups.settings.danger_zone.warning_description') }}</p>
            </div>

            <section class="space-y-3">
                <h3 class="font-semibold text-highlighted">{{ t('groups.settings.danger_zone.effects_title') }}</h3>
                <dl class="divide-y divide-default">
                    <div v-for="item in ['group', 'runs', 'resources']" :key="item" class="py-4 first:pt-0 last:pb-0">
                        <dt class="text-sm font-semibold text-toned">{{ t(`groups.settings.danger_zone.effects.${item}.title`) }}</dt>
                        <dd class="mt-1 text-sm leading-6 text-muted">{{ t(`groups.settings.danger_zone.effects.${item}.description`) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="space-y-2 border-t border-default pt-6">
                <h3 class="font-semibold text-highlighted">{{ t('groups.settings.danger_zone.retained_title') }}</h3>
                <p class="text-sm leading-6 text-muted">{{ t('groups.settings.danger_zone.retained_description') }}</p>
            </section>

            <section class="space-y-2 border-t border-default pt-6">
                <h3 class="font-semibold text-highlighted">{{ t('groups.settings.danger_zone.before_title') }}</h3>
                <p class="text-sm leading-6 text-muted">{{ t('groups.settings.danger_zone.before_description') }}</p>
            </section>

            <div class="flex justify-end border-t border-default pt-6">
                <UModal v-model:open="modalOpen">
                    <UButton
                        color="error"
                        variant="outline"
                        icon="i-lucide-trash-2"
                        :label="t('groups.settings.danger_zone.delete_button')"
                        :disabled="!group.permissions.can_manage_group"
                    />

                    <template #header>
                        <div class="flex flex-col gap-1">
                            <p class="font-semibold">{{ t('groups.settings.danger_zone.confirm_modal.title') }}</p>
                            <p class="text-sm text-muted">
                                {{ t('groups.settings.danger_zone.confirm_modal.subtitle', { name: group.name }) }}
                            </p>
                        </div>
                    </template>

                    <template #body>
                        <div class="flex flex-col gap-4">
                            <div class="rounded-lg border border-error/40 bg-error/10 px-4 py-4">
                                <p class="text-sm text-toned">{{ t('groups.settings.danger_zone.confirm_modal.warning') }}</p>
                            </div>

                            <div class="flex justify-end gap-2 pt-2">
                                <UButton
                                    type="button"
                                    color="neutral"
                                    variant="ghost"
                                    :label="t('general.cancel')"
                                    @click="modalOpen = false"
                                />
                                <UButton
                                    type="button"
                                    color="error"
                                    :label="t('groups.settings.danger_zone.confirm_modal.confirm_button')"
                                    :loading="isDeleting"
                                    @click="confirmDelete"
                                />
                            </div>
                        </div>
                    </template>
                </UModal>
            </div>
        </div>
    </UCard>
</template>
