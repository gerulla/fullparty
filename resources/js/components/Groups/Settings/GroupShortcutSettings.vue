<script setup lang="ts">
import type { GroupSettingsGroup } from "@/Types/GroupSettings"
import { useForm } from "@inertiajs/vue3"
import { useToast } from "@nuxt/ui/composables"
import { computed } from "vue"
import { useI18n } from "vue-i18n"
import { route } from "ziggy-js"

const props = defineProps<{ group: Pick<GroupSettingsGroup, 'slug' | 'quick_create_shortcuts'> }>()

const { t } = useI18n()
const toast = useToast()
const maxShortcuts = 5

const form = useForm({
    shortcuts: props.group.quick_create_shortcuts.map((shortcut) => ({
        time: shortcut.time,
        time_mode: shortcut.time_mode,
    })),
})

const timeModeItems = computed(() => [
    {
        label: `${t("groups.shortcuts.quick_create.server")} - ${t("groups.shortcuts.quick_create.server_description")}`,
        value: "server",
    },
    {
        label: `${t("groups.shortcuts.quick_create.local")} - ${t("groups.shortcuts.quick_create.local_description")}`,
        value: "local",
    },
])

const canAddShortcut = computed(() => form.shortcuts.length < maxShortcuts)

const addShortcut = () => {
    if (!canAddShortcut.value) {
        return
    }

    form.shortcuts.push({
        time: "18:00",
        time_mode: "server",
    })
}

const removeShortcut = (index: number) => {
    if (form.shortcuts.length <= 1) {
        return
    }

    form.shortcuts.splice(index, 1)
    form.clearErrors("shortcuts")
}

const moveShortcut = (index: number, direction: -1 | 1) => {
    const targetIndex = index + direction

    if (targetIndex < 0 || targetIndex >= form.shortcuts.length) {
        return
    }

    const [shortcut] = form.shortcuts.splice(index, 1)
    form.shortcuts.splice(targetIndex, 0, shortcut)
}

const saveShortcuts = () => {
    form.put(route("groups.dashboard.shortcuts.update", props.group.slug), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                title: t("groups.shortcuts.quick_create.saved"),
                color: "success",
                icon: "i-lucide-check",
            })
        },
        onError: () => {
            toast.add({
                title: t("groups.shortcuts.quick_create.save_failed"),
                color: "error",
                icon: "i-lucide-triangle-alert",
            })
        },
    })
}
</script>

<template>
    <div class="@container w-full">
        <UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
            <template #header>
                <div class="flex flex-col gap-1">
                    <h2 class="font-semibold text-highlighted">
                        {{ t("groups.shortcuts.quick_create.title") }}
                    </h2>
                    <p class="text-sm text-muted">
                        {{ t("groups.shortcuts.quick_create.description") }}
                    </p>
                </div>
            </template>

            <UAlert
                color="info"
                variant="subtle"
                icon="i-lucide-clock-3"
                :description="t('groups.shortcuts.quick_create.timezone_note')"
                class="m-4"
            />

            <div class="divide-y divide-default border-y border-default">
                <div
                    v-for="(shortcut, index) in form.shortcuts"
                    :key="index"
                    class="grid gap-3 p-4 @xl:grid-cols-[minmax(0,0.7fr)_minmax(0,1fr)_auto] @xl:items-end"
                >
                    <UFormField
                        :label="t('groups.shortcuts.quick_create.time')"
                        :error="form.errors[`shortcuts.${index}.time`]"
                    >
                        <UInput
                            v-model="shortcut.time"
                            type="time"
                            step="900"
                            size="lg"
                            class="w-full"
                        />
                    </UFormField>

                    <UFormField
                        :label="t('groups.shortcuts.quick_create.time_mode')"
                        :error="form.errors[`shortcuts.${index}.time_mode`]"
                    >
                        <USelect
                            v-model="shortcut.time_mode"
                            :items="timeModeItems"
                            size="lg"
                            class="w-full"
                        />
                    </UFormField>

                    <div class="flex items-center justify-end gap-1">
                        <UButton
                            icon="i-lucide-arrow-up"
                            color="neutral"
                            variant="ghost"
                            :disabled="index === 0"
                            :title="t('groups.shortcuts.quick_create.move_up')"
                            @click="moveShortcut(index, -1)"
                        />
                        <UButton
                            icon="i-lucide-arrow-down"
                            color="neutral"
                            variant="ghost"
                            :disabled="index === form.shortcuts.length - 1"
                            :title="t('groups.shortcuts.quick_create.move_down')"
                            @click="moveShortcut(index, 1)"
                        />
                        <UButton
                            icon="i-lucide-trash-2"
                            color="error"
                            variant="ghost"
                            :disabled="form.shortcuts.length <= 1"
                            :title="t('groups.shortcuts.quick_create.remove')"
                            @click="removeShortcut(index)"
                        />
                    </div>
                </div>
            </div>

            <p v-if="form.errors.shortcuts" class="px-4 pt-3 text-sm text-error">
                {{ form.errors.shortcuts }}
            </p>

            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <UButton
                        icon="i-lucide-plus"
                        color="neutral"
                        variant="outline"
                        :label="t('groups.shortcuts.quick_create.add')"
                        :disabled="!canAddShortcut"
                        @click="addShortcut"
                    />
                    <span class="text-xs text-muted">
                        {{ t("groups.shortcuts.quick_create.limit", { count: maxShortcuts }) }}
                    </span>
                </div>

                <UButton
                    icon="i-lucide-save"
                    :label="t('groups.shortcuts.quick_create.save')"
                    :loading="form.processing"
                    :disabled="form.processing"
                    @click="saveShortcuts"
                />
            </div>
        </UCard>
    </div>
</template>
