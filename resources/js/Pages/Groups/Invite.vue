<script setup lang="ts">
import { computed } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	invite: {
		token: string
		is_system: boolean
		uses: number
		max_uses: number | null
		expires_at: string | null
		can_accept: boolean
	}
	group: {
		id: number
		name: string
		description: string | null
		profile_picture_url: string | null
		datacenter: string
		is_public: boolean
		is_visible: boolean
		slug: string
		member_count: number
		owner: {
			id: number | null
			name: string | null
			avatar_url: string | null
		}
		current_user_is_member: boolean
	}
}>();

defineOptions({
	layout: AuthLayout,
});

const { t } = useI18n();
const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const isAccepting = computed(() => router.processing);
const declineHref = computed(() => isAuthenticated.value ? route('groups.index') : '/');

const acceptInvite = () => {
	if (!isAuthenticated.value || !props.invite.can_accept || props.group.current_user_is_member) {
		return;
	}

	router.post(route('groups.invites.accept', props.invite.token), {}, {
		preserveScroll: true,
	});
};
</script>

<template>
	<Head :title="`${t('groups.invite.title')} -`" />

	<div class="w-full">
		<UCard class="invite-simple-card">
			<div class="flex flex-col gap-6">
				<div class="mx-auto flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-default bg-muted/20">
					<img
						v-if="group.profile_picture_url"
						:src="group.profile_picture_url"
						:alt="`${group.name} profile picture`"
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
					<h1 class="mt-2 text-3xl font-black text-toned">{{ group.name }}</h1>
					<p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-muted">
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
					:title="t('groups.invite.states.expired')"
				/>

				<div class="grid gap-3 sm:grid-cols-3">
					<div class="invite-info-block">
						<p class="invite-info-label">{{ t('groups.invite.labels.members') }}</p>
						<p class="invite-info-value">{{ group.member_count }}</p>
					</div>

					<div class="invite-info-block">
						<p class="invite-info-label">{{ t('groups.invite.labels.datacenter') }}</p>
						<p class="invite-info-value">{{ group.datacenter }}</p>
					</div>

					<div class="invite-info-block">
						<p class="invite-info-label">{{ group.is_public ? t('groups.invite.labels.public') : t('groups.invite.labels.private') }}</p>
						<p class="invite-info-value">
							{{ group.is_public ? t('groups.invite.labels.public') : t('groups.invite.labels.private') }}
						</p>
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
						@click="acceptInvite"
					/>

					<Link
						v-else-if="!isAuthenticated && invite.can_accept"
						:href="route('login')"
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

					<Link
						v-if="!group.current_user_is_member"
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
