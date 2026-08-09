import type { ActivitySlot, ActivitySlotCompositionHint } from "@/Types/ActivityRoster";

export type CompositionRoleKey = "tank" | "healer" | "dps";

export type CompositionHintTone = {
	slot: string
}

export const compositionRoleIconUrls: Record<CompositionRoleKey, string> = {
	tank: "/role-icons/tank.png",
	healer: "/role-icons/healer.png",
	dps: "/role-icons/dps.png",
};

export type CompositionPreset = {
	key: string
	shorthand: string
	partySize: 4 | 8
	roles: CompositionRoleKey[]
}

export const compositionPresets: CompositionPreset[] = [
	{
		key: "thdd",
		shorthand: "THDD",
		partySize: 4,
		roles: ["tank", "healer", "dps", "dps"],
	},
	{
		key: "tddd",
		shorthand: "TDDD",
		partySize: 4,
		roles: ["tank", "dps", "dps", "dps"],
	},
	{
		key: "hddd",
		shorthand: "HDDD",
		partySize: 4,
		roles: ["healer", "dps", "dps", "dps"],
	},
	{
		key: "dddd",
		shorthand: "DDDD",
		partySize: 4,
		roles: ["dps", "dps", "dps", "dps"],
	},
	{
		key: "tthhdddd",
		shorthand: "TTHHDDDD",
		partySize: 8,
		roles: ["tank", "tank", "healer", "healer", "dps", "dps", "dps", "dps"],
	},
	{
		key: "tthddddd",
		shorthand: "TTHDDDDD",
		partySize: 8,
		roles: ["tank", "tank", "healer", "dps", "dps", "dps", "dps", "dps"],
	},
	{
		key: "thhddddd",
		shorthand: "THHDDDDD",
		partySize: 8,
		roles: ["tank", "healer", "healer", "dps", "dps", "dps", "dps", "dps"],
	},
	{
		key: "thdddddd",
		shorthand: "THDDDDDD",
		partySize: 8,
		roles: ["tank", "healer", "dps", "dps", "dps", "dps", "dps", "dps"],
	},
	{
		key: "hhdddddd",
		shorthand: "HHDDDDDD",
		partySize: 8,
		roles: ["healer", "healer", "dps", "dps", "dps", "dps", "dps", "dps"],
	},
	{
		key: "ttdddddd",
		shorthand: "TTDDDDDD",
		partySize: 8,
		roles: ["tank", "tank", "dps", "dps", "dps", "dps", "dps", "dps"],
	},
	{
		key: "ttttdddd",
		shorthand: "TTTTDDDD",
		partySize: 8,
		roles: ["tank", "tank", "tank", "tank", "dps", "dps", "dps", "dps"],
	},
];

export const compositionRoleTones: Record<CompositionRoleKey, CompositionHintTone> = {
	tank: {
		slot: "border-dashed border-blue-500/30 bg-blue-500/[0.045] hover:border-blue-400/55",
	},
	healer: {
		slot: "border-dashed border-emerald-500/30 bg-emerald-500/[0.045] hover:border-emerald-400/55",
	},
	dps: {
		slot: "border-dashed border-red-500/30 bg-red-500/[0.045] hover:border-red-400/55",
	},
};

const compositionRoleOrder: CompositionRoleKey[] = ["tank", "healer", "dps"];

const mixedCompositionRoleTones: Record<string, CompositionHintTone> = {
	"tank|healer": {
		slot: "border-dashed border-default bg-[linear-gradient(135deg,rgba(59,130,246,0.07)_0_50%,rgba(16,185,129,0.07)_50%_100%)] hover:border-primary/55",
	},
	"tank|dps": {
		slot: "border-dashed border-default bg-[linear-gradient(135deg,rgba(59,130,246,0.07)_0_50%,rgba(239,68,68,0.07)_50%_100%)] hover:border-primary/55",
	},
	"healer|dps": {
		slot: "border-dashed border-default bg-[linear-gradient(135deg,rgba(16,185,129,0.07)_0_50%,rgba(239,68,68,0.07)_50%_100%)] hover:border-primary/55",
	},
	"tank|healer|dps": {
		slot: "border-dashed border-default bg-[linear-gradient(135deg,rgba(59,130,246,0.07)_0_33.333%,rgba(16,185,129,0.07)_33.333%_66.666%,rgba(239,68,68,0.07)_66.666%_100%)] hover:border-primary/55",
	},
};

export const isCompositionRoleKey = (value: unknown): value is CompositionRoleKey => (
	value === "tank" || value === "healer" || value === "dps"
);

export const compositionRoleIconUrl = (role: CompositionRoleKey): string => compositionRoleIconUrls[role];

export const sortedCompositionHints = (slot: Pick<ActivitySlot, "composition_hints">): ActivitySlotCompositionHint[] => (
	[...(slot.composition_hints ?? [])].sort((left, right) => {
		const sortDelta = Number(left.sort_order ?? 0) - Number(right.sort_order ?? 0);

		return sortDelta !== 0 ? sortDelta : left.id - right.id;
	})
);

export const primaryCompositionHintRole = (slot: Pick<ActivitySlot, "composition_hints">): CompositionRoleKey | null => {
	const hint = sortedCompositionHints(slot).find((compositionHint) => isCompositionRoleKey(compositionHint.role_key));

	return hint?.role_key ?? null;
};

export const compositionHintRoles = (slot: Pick<ActivitySlot, "composition_hints">): CompositionRoleKey[] => {
	const roles = new Set(sortedCompositionHints(slot)
		.map((compositionHint) => compositionHint.role_key)
		.filter(isCompositionRoleKey));

	return compositionRoleOrder.filter((role) => roles.has(role));
};

export const emptyCompositionSlotToneClass = (slot: Pick<ActivitySlot, "composition_hints">): string | null => {
	const roles = compositionHintRoles(slot);

	if (roles.length === 0) {
		return null;
	}

	if (roles.length === 1) {
		return compositionRoleTones[roles[0]].slot;
	}

	return mixedCompositionRoleTones[roles.join("|")]?.slot ?? null;
};

export const compositionPresetsForPartySize = (partySize: number): CompositionPreset[] => (
	compositionPresets.filter((preset) => preset.partySize === partySize)
);

export const compositionPresetKeyForSlots = (slots: ActivitySlot[]): string | null => {
	const sortedSlots = [...slots].sort((left, right) => left.position_in_group - right.position_in_group);
	const roles = sortedSlots.map((slot) => primaryCompositionHintRole(slot));

	if (roles.some((role) => role === null)) {
		return null;
	}

	return compositionPresetsForPartySize(sortedSlots.length)
		.find((preset) => preset.roles.every((role, index) => role === roles[index]))
		?.key ?? null;
};
