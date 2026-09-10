import type { ConfirmationModalInput } from '../Types/Shared';

export function isConfirmationInputValid(value: string, input?: ConfirmationModalInput): boolean {
    return input?.requiredValue === undefined || value === input.requiredValue;
}
