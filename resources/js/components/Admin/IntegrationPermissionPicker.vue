<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { IntegrationPermissionGroup } from '@/Types/IntegrationPermissions';
import { permissionGroupState, togglePermissionGroup } from '@/utils/integrationPermissions';

defineProps<{ groups: IntegrationPermissionGroup[]; modelValue: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();
const { t } = useI18n();
</script>

<template>
    <div class="space-y-3">
        <UCheckbox
            v-for="group in groups"
            :key="group.key"
            :model-value="permissionGroupState(group, modelValue)"
            :label="t(`admin.integrations.permission_groups.${group.key}.label`)"
            :description="t(`admin.integrations.permission_groups.${group.key}.description`)"
            @update:model-value="value => emit('update:modelValue', togglePermissionGroup(group, modelValue, value === true))"
        />
        <p v-if="groups.some(group => permissionGroupState(group, modelValue) === 'indeterminate')" class="text-xs text-muted">
            {{ t('admin.integrations.permission_groups.partial') }}
        </p>
    </div>
</template>
