<script setup lang="ts">
import LandingLayout from "@/Layouts/LandingLayout.vue";
import LegalDocument from "@/components/LegalDocument.vue";
import { Head, usePage } from "@inertiajs/vue3";

const page = usePage<{
	legal: {
		controller_name: string | null;
		contact_email: string | null;
	};
}>();

const controllerName = page.props.legal.controller_name;
const contactEmail = page.props.legal.contact_email
	?? "the contact address configured by this self-hosted FullParty instance";
const operatorSentence = controllerName
	? `${controllerName} operates this FullParty service. This privacy policy explains what personal information we collect, how we use it, who we share it with, and the choices you have.`
	: "This privacy policy explains what personal information is collected by the operator of this FullParty service, how it is used, who it is shared with, and the choices available to you.";
const operatorDetails = controllerName
	? `This FullParty service is operated by ${controllerName}.`
	: "This FullParty service is operated by the person or organisation responsible for this instance.";

const intro = [
	"This privacy policy applies only to the specific FullParty site or service where it is published. If you are using a self-hosted or forked copy of FullParty, the operator of that instance must replace or adapt this policy so it accurately reflects their own data practices.",
	operatorSentence,
	"FullParty is a platform for managing groups, applications, rosters, characters, notifications, and related activity for online gaming communities.",
];

const sections = [
	{
		title: "Who we are",
		paragraphs: [
			operatorDetails,
			`If you have questions about this privacy policy or want to exercise your data protection rights, you can contact us at ${contactEmail}.`,
		],
	},
	{
		title: "What information we collect",
		bullets: [
			"Account information such as your email address, username, password hash if you set one, avatar, and account preferences.",
			"Social login and linked account information you choose to connect, such as provider account IDs, display names, avatars, and email addresses returned by the provider.",
			"Character information such as Lodestone or game-related identifiers, claimed characters, primary character selection, classes, jobs, and profile data imported or refreshed at your request.",
			"Group and activity information such as group memberships, moderation roles, applications, roster assignments, check-ins, notes, bans, and audit log entries.",
			"Notification and communication data such as your notification preferences, inbox state, delivery records, and system notice read state.",
			"Technical and security information such as IP address, browser or device user agent, session records, request metadata, and security or abuse-prevention logs.",
		],
	},
	{
		title: "Where we get personal information from",
		bullets: [
			"Directly from you when you create an account, edit your profile, connect accounts, submit applications, join groups, or otherwise use FullParty.",
			"From social authentication providers when you sign in or link an account through services such as Google, Discord, or XIVAuth.",
			"From raid log or character data providers when you ask FullParty to import or refresh information, including services such as FF Logs or Lodestone-related sources.",
			"From other users when they manage groups, rosters, moderation notes, invitations, or other shared features that involve your account or characters.",
		],
	},
	{
		title: "Why we use your information",
		bullets: [
			"To create and manage your account and let you sign in securely.",
			"To provide core product features such as groups, applications, rosters, notifications, and character management.",
			"To operate moderation, safety, anti-abuse, and audit logging features.",
			"To send service-related emails and other communications, including important operational or security notices.",
			"To troubleshoot, secure, maintain, and improve FullParty.",
			"To comply with legal obligations where applicable.",
		],
	},
	{
		title: "Our lawful bases",
		paragraphs: [
			"We usually process your personal information because it is necessary to provide the service you ask us to provide, including account access, group participation, applications, rosters, and notifications.",
			"We also process information where it is in our legitimate interests to run, secure, moderate, and improve FullParty, provided those interests are not overridden by your rights and freedoms.",
			"Where we rely on consent, such as for certain optional communications or optional account connections you choose to enable, you can withdraw that consent at any time.",
		],
	},
	{
		title: "Who we share information with",
		paragraphs: [
			"We share personal information only where needed to run FullParty lawfully and effectively.",
		],
		bullets: [
			"Hosting, infrastructure, and storage providers.",
			"Email delivery and related communication providers.",
			"Social authentication providers you choose to use.",
			"Character, identity, or raid log data providers you ask FullParty to query or import from.",
			"Professional advisers, law enforcement, regulators, or other parties where disclosure is required or reasonably necessary to protect rights, safety, or the service.",
		],
	},
	{
		title: "International transfers",
		paragraphs: [
			"Some of the providers we use may process personal information outside your country, including outside the UK or EEA. Where that happens, we aim to use providers and arrangements that offer an appropriate level of protection under applicable data protection law.",
		],
	},
	{
		title: "How long we keep information",
		bullets: [
			"We keep account and profile information for as long as your account remains active and for a reasonable period afterwards where needed for security, dispute handling, legal compliance, or record integrity.",
			"We keep group, application, roster, audit, and moderation records for as long as needed to preserve operational history, accountability, and service integrity.",
			"We keep notification and delivery records for as long as reasonably necessary to operate communications, troubleshoot issues, and maintain auditability.",
			"We may delete or anonymise information sooner where it is no longer needed, and we may keep information longer where required by law or where necessary to establish, exercise, or defend legal claims.",
		],
	},
	{
		title: "Your rights",
		paragraphs: [
			"Depending on where you are located and the law that applies, you may have rights to access, correct, delete, restrict, object to, or move some of your personal information.",
			"You may also have the right to withdraw consent where consent is the basis for processing.",
			`To make a request, contact ${contactEmail}. We may need to verify your identity before acting on your request.`,
		],
	},
	{
		title: "How to complain",
		paragraphs: [
			`If you have concerns about how we use your personal information, please contact us first at ${contactEmail}.`,
			"If you are in the UK, you can also complain to the Information Commissioner's Office (ICO): https://ico.org.uk/make-a-complaint/.",
		],
	},
	{
		title: "Changes to this policy",
		paragraphs: [
			"We may update this privacy policy from time to time. When we do, we will update the 'Last updated' date on this page.",
		],
	},
];
</script>

<template>
	<Head title="Privacy Policy -" />

	<LegalDocument
		title="Privacy Policy"
		last-updated="7 May 2026"
		:intro="intro"
		:sections="sections"
	/>
</template>

<script lang="ts">
export default {
	layout: LandingLayout,
};
</script>
