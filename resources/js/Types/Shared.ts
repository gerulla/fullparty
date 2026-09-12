export type ConfirmationModalSeverity = 'error' | 'warning' | 'info' | 'success' | 'neutral'

export type ConfirmationModalInput = {
	label: string
	help?: string
	placeholder?: string
	error?: string
	initialValue?: string
	rows?: number
	maxlength?: number
	type?: 'text' | 'textarea'
	requiredValue?: string
}

export type ConfirmationModalConfirmContext = {
	inputValue: string
	patch: (props: Partial<ConfirmationModalRequest>) => void
	close: (value?: boolean) => void
}

export type ConfirmationModalRequest = {
	title: string
	description: string
	severity?: ConfirmationModalSeverity
	warningText: string
	confirmLabel: string
	confirmIcon?: string
	confirmLoading?: boolean
	input?: ConfirmationModalInput
	onConfirm?: (context: ConfirmationModalConfirmContext) => boolean | void | Promise<boolean | void>
}
