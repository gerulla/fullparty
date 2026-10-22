import { sanitizeSingleLineText } from '@/utils/textInputSanitizer'

export const groupExtraTagKey = (tag: string) => sanitizeSingleLineText(tag).toLowerCase().replace(/[^\p{L}\p{N}]/gu, '')
