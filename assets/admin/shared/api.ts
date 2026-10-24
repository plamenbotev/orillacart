import { __ } from '@wordpress/i18n';

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

	return {
		code: 'unknown',
		message: __(
			'The request failed. Check the connection and try again.',
			'orillacart'
		),
	};
}

/**
 * What to tell the shop of a failure: the server's message and, when it refused fields, each of them with why
 * ("Some fields are not valid. Price decimals: …"), for screens that do not show the refusals beside their fields.
 *
 * @param error Whatever apiFetch rejected with.
 */
export function errorMessage( error: unknown ): string {
	const { message, data } = toApiError( error );
	const violations = data?.violations ?? [];
	if ( violations.length === 0 ) {
		return message;
	}

	return [
		message,
		...violations.map( ( violation ) =>
			violation.field === ''
				? violation.message
				: `${ fieldName( violation.field ) }: ${ violation.message }`
		),
	].join( ' ' );
}

/**
 * A field as the API names it, in words: price_decimals → "Price decimals", rates[0].price → "Rates 1 price".
 *
 * @param field The field's path.
 */
export function fieldName( field: string ): string {
	const words = field
		.replace(
			/\[(\d+)\]/g,
			( _, index: string ) => ` ${ Number( index ) + 1 } `
		)
		.replace( /[._]+/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();

	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
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
