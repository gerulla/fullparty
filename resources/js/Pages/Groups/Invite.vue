<script setup lang="ts">
import { computed, ref } from "vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import type { GroupInvitePageProps } from "../../Types/GroupInvitations";
import { useDateTimeFormat } from "@/composables/useDateTimeFormat";

const props = defineProps<GroupInvitePageProps>();

defineOptions({
	layout: AuthLayout,
});

const { t } = useI18n();
const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const isAccepting = ref(false);
const isDeclining = ref(false);
const { formatDateTime } = useDateTimeFormat();
const unavailableMessage = computed(() => {
    if (props.group.current_user_is_banned) return t('group_invitations.banned');
    if (props.invite.is_personal && props.invite.status !== 'pending') {
        return t(`group_invitations.states.${props.invite.status}`);
    }
    return t('groups.invite.states.expired');
});
const declineHref = computed(() => isAuthenticated.value ? route('groups.index') : '/');

const acceptInvite = () => {
	if (!isAuthenticated.value || !props.invite.can_accept || props.group.current_user_is_member || isAccepting.value || isDeclining.value) {
		return;
	}

	isAccepting.value = true;
	router.post(route('groups.invites.accept', props.invite.token), {}, {
        onFinish: () => { isAccepting.value = false; },
	});
};
const declineInvite = () => {
    if (!props.invite.is_personal || !props.invite.can_accept || isDeclining.value || isAccepting.value) return;
    isDeclining.value = true;
    router.post(route('groups.invites.decline', props.invite.token), {}, {
        onFinish: () => { isDeclining.value = false; },
    });
};
</script>

<template>
	<SeoHead
		:title="t('groups.invite.title')"
		:description="t('meta.seo.invite.description')"
		noindex
	/>

	<div class="w-full">
		<UCard class="invite-simple-card">
			<div class="flex flex-col gap-6">
				<div class="mx-auto flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-default bg-muted/20">
					<img
						v-if="group.profile_picture_url"
						:src="group.profile_picture_url"
						:alt="t('general.image_alt.profile', { name: group.name })"
						class="h-full w-full object-cover"
					>
					<UIcon
						v-else
						name="i-lucide-swords"
						class="h-10 w-10 text-muted"
					/>
				</div>

				<div class="text-center">
					<p class="text-xs font-semibold uppercase tracking-[0.22em] text-muted">{{ t('groups.invite.subtitle') }}</p>
					<h1 class="mt-2 break-words [overflow-wrap:anywhere] text-3xl font-black text-toned">{{ group.name }}</h1>
					<p class="mx-auto mt-3 max-w-xl break-words [overflow-wrap:anywhere] text-sm leading-7 text-muted">
						{{ group.description || t('groups.index.table.no_description') }}
					</p>
				</div>

				<UAlert
					v-if="group.current_user_is_member"
					color="primary"
					variant="subtle"
					icon="i-lucide-check-circle-2"
					:title="t('groups.invite.messages.already_member')"
				/>

				<UAlert
					v-else-if="!invite.can_accept"
					color="error"
					variant="subtle"
					icon="i-lucide-octagon-x"
					:title="unavailableMessage"
				/>

                <div v-if="invite.is_personal" class="space-y-2 text-center text-sm text-muted">
                    <p>{{ t('group_invitations.personal_notice') }}</p>
                    <p v-if="invite.expires_at && invite.status === 'pending'">
                        {{ t('group_invitations.expires', { date: formatDateTime(invite.expires_at) }) }}
                    </p>
                </div>

                <UAlert v-if="page.props.errors?.invite || page.props.errors?.quota" color="error" variant="subtle"
                    :description="String(page.props.errors.invite || page.props.errors.quota)" />

				<div class="grid gap-3 sm:grid-cols-2">
					<div class="invite-info-block">
						<p class="invite-info-label">{{ t('general.members') }}</p>
						<p class="invite-info-value">{{ group.member_count }}</p>
					</div>

					<div class="invite-info-block">
						<p class="invite-info-label">{{ t('general.datacenter') }}</p>
						<p class="invite-info-value">{{ group.datacenter }}</p>
					</div>
				</div>

				<p v-if="!isAuthenticated && invite.can_accept" class="text-center text-sm text-muted">
					{{ t('groups.invite.messages.guest') }}
				</p>

				<div class="flex flex-col gap-3 sm:flex-row">
					<UButton
						v-if="isAuthenticated && !group.current_user_is_member && invite.can_accept"
						color="brand"
						size="xl"
						class="flex-1 justify-center"
						icon="i-lucide-check"
						:label="isAccepting ? t('groups.invite.messages.accepting') : t('groups.invite.actions.accept')"
						:loading="isAccepting"
                        :disabled="isDeclining"
						@click="acceptInvite"
					/>

					<Link
						v-else-if="!isAuthenticated && invite.can_accept"
						:href="route('login', { invite: invite.token })"
						class="invite-action-link invite-action-link-primary"
					>
						<UIcon name="i-lucide-log-in" class="h-4 w-4" />
						<span>{{ t('groups.invite.actions.sign_in') }}</span>
					</Link>

					<Link
						v-else-if="group.current_user_is_member"
						:href="route('groups.dashboard', group.slug)"
						class="invite-action-link invite-action-link-primary"
					>
						<UIcon name="i-lucide-arrow-right" class="h-4 w-4" />
						<span>{{ t('groups.invite.actions.open_dashboard') }}</span>
					</Link>

					<UButton
						v-else
						color="neutral"
						size="xl"
						class="flex-1 justify-center"
						icon="i-lucide-ban"
						:label="t('groups.invite.actions.unavailable')"
						disabled
					/>

                    <UButton
                        v-if="invite.is_personal && invite.can_accept && !group.current_user_is_member"
                        color="neutral" variant="outline" size="xl" class="justify-center"
                        icon="i-lucide-x" :label="t('groups.invite.actions.decline')"
                        :loading="isDeclining" :disabled="isAccepting" @click="declineInvite"
                    />
                    <Link
                        v-else-if="!invite.is_personal && !group.current_user_is_member"
						:href="declineHref"
						class="invite-action-link invite-action-link-secondary"
					>
						<UIcon name="i-lucide-x" class="h-4 w-4" />
						<span>{{ t('groups.invite.actions.decline') }}</span>
					</Link>
				</div>
			</div>
		</UCard>
	</div>
</template>

<style scoped>
@reference "../../../css/app.css";

.invite-simple-card {
	@apply mx-auto w-full max-w-3xl rounded-sm bg-elevated/25;
}

.invite-info-block {
	@apply rounded-sm border border-default bg-muted/20 px-4 py-4 text-center;
}

.invite-info-label {
	@apply text-xs font-semibold uppercase tracking-[0.18em] text-muted;
}

.invite-info-value {
	@apply mt-2 text-lg font-semibold text-toned;
}

.invite-action-link {
	@apply inline-flex items-center justify-center gap-2 rounded-sm px-5 py-3 text-sm font-medium transition;
}

.invite-action-link-primary {
	@apply flex-1 bg-brand text-white hover:brightness-110;
}

.invite-action-link-secondary {
	@apply border border-default bg-transparent text-toned hover:bg-muted;
}
</style>
