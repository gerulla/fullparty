const groupPalette = [
	'#38bdf8', '#fbbf24', '#f87171', '#34d399',
	'#fb7185', '#22d3ee', '#fb923c', '#e2e8f0',
	'#a3e635', '#60a5fa', '#f472b6', '#2dd4bf',
];

const fallbackGroupColor = (index: number): string => {
	const hue = (index * 137.508) % 270;
	// Skip indigo through purple so group colors stay distinct from the brand UI.
	return `hsl(${hue < 230 ? hue : hue + 90} 75% 68%)`;
};

export const buildGroupCalendarColors = (groupIds: number[]): Record<number, string> => {
	// Use the full group list, sorted by ID, so month changes and name sorting keep colors stable.
	return Object.fromEntries([...new Set(groupIds)].sort((a, b) => a - b).map((id, index) => [
		id,
		groupPalette[index] ?? fallbackGroupColor(index - groupPalette.length),
	]));
};
