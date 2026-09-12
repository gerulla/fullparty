import type { QueueApplicationAnswer, QueueFilterField } from '@/Types/ActivityQueue';

export function matchesQueueRoleFilter(
	answers: QueueApplicationAnswer[],
	fields: QueueFilterField[],
	roles: string[],
): boolean {
	if (roles.length === 0) return true;
	const classFields = fields.filter((field) => field.source === 'character_classes');
	if (classFields.length === 0) return true;

	return classFields.some((field) => {
		const answer = answers.find((entry) => entry.question_key === field.application_key);
		if (!answer) return false;
		const values = (Array.isArray(answer.raw_value) ? answer.raw_value : [answer.raw_value]).map(String);
		const options = field.filter_options?.length ? field.filter_options : field.options;
		const matchingClasses = options.filter((option) => option.meta?.role && roles.includes(option.meta.role));
		if (matchingClasses.length === 0) return false;

		return matchingClasses.some((option) => values.includes(option.key))
			|| (values.includes('any') && options.some((option) => option.key === 'any'));
	});
}

export function matchesQueuePartyLeadFilter(
	answers: QueueApplicationAnswer[],
	questionKey: string | null,
	partyLeadsOnly: boolean,
): boolean {
	if (!partyLeadsOnly || !questionKey) return true;
	const answer = answers.find((entry) => entry.question_key === questionKey);
	const value = answer?.raw_value;
	return value === true || value === 1 || value === '1' || value === 'true';
}
