<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {ref} from 'vue';

const props = defineProps<{
	xivauth_connected: boolean
}>()

const { t } = useI18n()
const self_open = ref(false);

const emit = defineEmits<{ choice: [String] }>();
const open = () => {
	self_open.value = true;
}
const hide = () => {
	self_open.value = false;
}

const xivimport = () => {
	if(props.xivauth_connected)
		emit('choice', 'xivauth');
}
const manual = async () => {
	emit('choice', 'manual');
}
defineExpose({
	open, hide
})
</script>

<template>
	<UModal
		v-model:open="self_open"
		:title="t('characters.add.title')"
		:description="t('characters.add.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<UButton
			:label="t('characters.add.add_button')"
			color="neutral"
			class="w-full cursor-pointer rounded-none"
			icon="i-lucide-plus"
		/>

		<template #body>
			<div class="w-full h-full flex items-stretch gap-2" :class="xivauth_connected ? 'flex-col' : 'flex-col-reverse'">
				<div :class="xivauth_connected ? 'option-block' : 'option-disabled'" @click="xivimport()">
					<div class="w-full flex flex-row items-stretch gap-2">
						<p class="font-bold">{{t('characters.add.options.xivauth.title')}}</p>
						<UBadge
							:ui="{base:'rounded-sm'}"
							size="md"
							color="neutral"
							variant="soft"
						>
							{{t('general.recommended')}}
						</UBadge>
						<UIcon class="ml-auto" name="i-lucide-chevron-right" size="16" />
					</div>
					<p class="text-muted text-sm">{{t('characters.add.options.xivauth.subtitle')}}</p>
				</div>
				<div class="option-block" @click="manual">
					<div class="w-full flex flex-row items-stretch gap-2">
						<p class="font-bold">{{t('characters.add.options.manual.title')}}</p>
						<UIcon class="ml-auto" name="i-lucide-chevron-right" size="16" />
					</div>
					<p class="text-muted text-sm">{{t('characters.add.options.manual.subtitle')}}</p>
				</div>
			</div>
		</template>
	</UModal>
</template>


<style scoped>
@reference '../../../css/app.css';

.option-block {
	@apply w-full flex flex-col items-start border-2 border-neutral-200 cursor-pointer
		dark:border-neutral-700 hover:border-brand dark:hover:border-brand rounded-sm p-4 gap-2 transition
}

.option-disabled {
	@apply w-full flex flex-col items-start border-2 border-neutral-100
		dark:border-neutral-800  rounded-sm p-4 gap-2 transition text-muted cursor-not-allowed
}
</style>