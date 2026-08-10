const numberedPartyKeys: Record<string, string> = {
	"party-d": "1",
	"party-e": "2",
	"party-f": "3",
};

export const numberedPartyValue = (groupKey: string): string | null => (
	numberedPartyKeys[groupKey.toLowerCase()] ?? null
);

export const displayActivityPartyLabel = (
	groupKey: string,
	label: string,
	numberedSecondaryParties: boolean,
	partyLabel: string,
): string => {
	const partyNumber = numberedSecondaryParties ? numberedPartyValue(groupKey) : null;

	return partyNumber ? `${partyLabel} ${partyNumber}` : label;
};

export const displayActivitySlotLabel = (
	groupKey: string,
	positionInGroup: number,
	label: string,
	numberedSecondaryParties: boolean,
	partyLabel: string,
): string => {
	const partyNumber = numberedSecondaryParties ? numberedPartyValue(groupKey) : null;

	return partyNumber ? `${partyLabel} ${partyNumber} ${positionInGroup}` : label;
};