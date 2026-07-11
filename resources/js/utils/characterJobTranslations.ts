type Translator = (key: string) => string;

const normalizeTranslationKey = (value: unknown): string => String(value ?? "")
	.trim()
	.toLowerCase()
	.replace(/^phantom\s+/, "")
	.replace(/&/g, "and")
	.replace(/[^a-z0-9]+/g, "_")
	.replace(/^_+|_+$/g, "");

const characterClassNameKeys: Record<string, string> = {
	astrologian: "ast",
	bard: "brd",
	black_mage: "blm",
	blue_mage: "blu",
	dancer: "dnc",
	dark_knight: "drk",
	dragoon: "drg",
	gunbreaker: "gnb",
	machinist: "mch",
	monk: "mnk",
	ninja: "nin",
	paladin: "pld",
	pictomancer: "pct",
	reaper: "rpr",
	red_mage: "rdm",
	sage: "sge",
	samurai: "sam",
	scholar: "sch",
	summoner: "smn",
	viper: "vpr",
	warrior: "war",
	white_mage: "whm",
};

const raidPositionNameKeys: Record<string, string> = {
	d1: "m1",
	d2: "m2",
	d3: "r1",
	d4: "r2",
	dps_1_melee_1: "m1",
	dps_2_melee_2: "m2",
	dps_3_phys_ranged: "r1",
	dps_3_physical_ranged: "r1",
	dps_4_magic_ranged: "r2",
	dps_4_magical_ranged: "r2",
	healer_1: "h1",
	healer_2: "h2",
	main_tank: "mt",
	off_tank: "ot",
};

export const characterClassTranslationKey = (characterClass: { shorthand?: string | null, name?: string | null } | null | undefined): string | null => {
	const shorthandKey = normalizeTranslationKey(characterClass?.shorthand);
	const nameKey = normalizeTranslationKey(characterClass?.name);
	const key = shorthandKey || characterClassNameKeys[nameKey] || nameKey;

	return key === "" ? null : `characters.jobs.classes.${key}`;
};

export const phantomJobTranslationKey = (phantomJob: { name?: string | null } | null | undefined): string | null => {
	const key = normalizeTranslationKey(phantomJob?.name);

	return key === "" ? null : `characters.jobs.phantom.${key}`;
};

export const raidPositionTranslationKey = (raidPosition: { key?: string | null, name?: string | null } | null | undefined): string | null => {
	const storedKey = normalizeTranslationKey(raidPosition?.key);
	const nameKey = normalizeTranslationKey(raidPosition?.name);
	const key = raidPositionNameKeys[storedKey] || raidPositionNameKeys[nameKey] || storedKey || nameKey;

	return key === "" ? null : `characters.jobs.raid_positions.${key}`;
};

export const translateJobName = (t: Translator, key: string | null, fallback: string | null | undefined): string => {
	if (!key) {
		return fallback ?? "";
	}

	const translated = t(key);

	return translated === key ? (fallback ?? "") : translated;
};

export const translateCharacterClassName = (
	t: Translator,
	characterClass: { shorthand?: string | null, name?: string | null } | null | undefined,
	fallback: string | null | undefined,
): string => translateJobName(t, characterClassTranslationKey(characterClass), fallback);

export const translatePhantomJobName = (
	t: Translator,
	phantomJob: { name?: string | null } | null | undefined,
	fallback: string | null | undefined,
): string => translateJobName(t, phantomJobTranslationKey(phantomJob), fallback);

export const translateRaidPositionName = (
	t: Translator,
	raidPosition: { key?: string | null, name?: string | null } | null | undefined,
	fallback: string | null | undefined,
): string => translateJobName(t, raidPositionTranslationKey(raidPosition), fallback);
