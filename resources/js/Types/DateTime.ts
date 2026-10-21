/** An absolute timestamp; numeric values are milliseconds since the Unix epoch. */
export type DateTimeValue = Date | string | number | null | undefined

/** The display timezone always comes from the user's LT/ST preference. */
export type DisplayDateTimeOptions = Omit<Intl.DateTimeFormatOptions, 'timeZone'>
