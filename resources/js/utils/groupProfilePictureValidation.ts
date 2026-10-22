const allowedGroupProfilePictureMimeTypes = new Set([
	"image/jpeg",
	"image/png",
	"image/webp",
]);

const allowedGroupProfilePictureExtensions = new Set([
	"jpg",
	"jpeg",
	"png",
	"webp",
]);

export const groupProfilePictureAccept = ".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp";
export const groupImageMaxBytes = 5 * 1024 * 1024;

export const validateGroupProfilePictureFile = (file: File | null) => {
	if (!file) {
		return {
			isValid: true,
			errorKey: null,
		};
	}

	const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
	const mimeType = file.type.toLowerCase();
	const hasAllowedExtension = allowedGroupProfilePictureExtensions.has(extension);
	const hasAllowedMimeType = mimeType === "" || allowedGroupProfilePictureMimeTypes.has(mimeType);

	if (!hasAllowedExtension || !hasAllowedMimeType) {
		return {
			isValid: false,
			errorKey: "invalid_format",
		} as const;
	}

	return {
		isValid: true,
		errorKey: null,
	} as const;
};
