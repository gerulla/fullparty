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
const contactLine = controllerName
	? `If you have questions about how this FullParty service uses cookies or similar technologies, contact ${controllerName} at ${contactEmail}.`
	: `If you have questions about how this FullParty service uses cookies or similar technologies, contact the operator of this instance at ${contactEmail}.`;

const intro = [
	"This cookie policy applies only to the specific FullParty site or service where it is published. Self-hosted or forked copies of FullParty should replace or adapt this page so it accurately describes their own cookie and storage usage.",
	"This cookie policy explains how this FullParty service uses cookies and similar technologies on the site.",
	"At the moment, FullParty primarily uses cookies and related storage for essential security, sign-in, session handling, and user-selected preferences.",
];

const sections = [
	{
		title: "What cookies are",
		paragraphs: [
			"Cookies are small text files placed on your device when you visit a website. They help the site remember information about your visit, such as whether you are signed in or which preferences you selected.",
		],
	},
	{
		title: "Cookies and similar technologies we use",
		bullets: [
			"Session and authentication cookies, which help keep you signed in, maintain secure sessions, and protect account access.",
			"Security and request-validation cookies, including cookies used to support CSRF protection and secure form submissions.",
			"Preference cookies, such as the cookie used to remember your selected interface language.",
		],
	},
	{
		title: "Why we use them",
		bullets: [
			"To let you log in and stay signed in while using FullParty.",
			"To secure requests and reduce abuse or misuse of the service.",
			"To remember settings you explicitly choose, such as your locale or language preference.",
			"To keep the service stable and usable during normal operation.",
		],
	},
	{
		title: "Cookies we currently rely on",
		bullets: [
			"A session cookie used by the Laravel application to maintain your session.",
			"A CSRF-related cookie used to help validate requests.",
			"A locale cookie used to remember your selected language.",
		],
	},
	{
		title: "Consent",
		paragraphs: [
			"Based on the current setup of FullParty, these cookies are used for essential service functionality and user-requested preferences rather than advertising or optional behavioural tracking.",
			"If FullParty later adds non-essential analytics, marketing, or similar tracking technologies that require consent, we will update this policy and implement an appropriate consent flow.",
		],
	},
	{
		title: "Managing cookies",
		paragraphs: [
			"You can control or delete cookies through your browser settings. However, if you block essential cookies, parts of FullParty may stop working properly, including sign-in, security features, and saved preferences.",
		],
	},
	{
		title: "Contact",
		paragraphs: [
			contactLine,
		],
	},
];
</script>

<template>
	<Head title="Cookies Policy -" />

	<LegalDocument
		title="Cookies Policy"
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
