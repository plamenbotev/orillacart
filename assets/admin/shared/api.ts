export interface Violation {
	field: string;
	message: string;
}

/** An error answer from the plugin's REST routes. */
export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number; violations?: Violation[] };
}

/**
 * @param error Whatever apiFetch rejected with.
 * @return The error as the plugin sends it, or a generic one for network failures.
 */
export function toApiError( error: unknown ): ApiError {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error as ApiError;
	}

	return { code: 'unknown', message: 'The request failed.' };
}

/**
 * @param violations What the server refused.
 * @param field      The field as the API names it, such as price_decimals.
 * @return The message for that field, if any.
 */
export function violationOf(
	violations: Violation[],
	field: string
): string | undefined {
	return violations.find( ( violation ) => violation.field === field )
		?.message;
}
