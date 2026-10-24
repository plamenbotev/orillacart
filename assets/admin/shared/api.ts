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
 * The words a form shows for the fields the API names, so a refusal reads as the form does: `price` →
 * "Price". A list's items are "rates[].price"; "rates[]" names one of them, with %d for its number ("Rate %d").
 */
export type FieldLabels = Record< string, string >;

/**
 * What to tell the shop of a failure: the server's message and, when it refused fields, each of them with why
 * ("Some fields are not valid. Price: …"), for screens that do not show the refusals beside their fields.
 *
 * @param error  Whatever apiFetch rejected with.
 * @param labels The form's words for its fields; without one, the field's API name in words.
 */
export function errorMessage(
	error: unknown,
	labels: FieldLabels = {}
): string {
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
				: `${ fieldName( violation.field, labels ) }: ${
						violation.message
					}`
		),
	].join( ' ' );
}

/**
 * A field as the form calls it, or as the API names it, in words: price_decimals → "Price decimals",
 * rates[0].price → "Rate 1: Price" with labels for them, "Rates 1 price" without.
 *
 * @param field  The field's path.
 * @param labels The form's words for its fields.
 */
export function fieldName( field: string, labels: FieldLabels = {} ): string {
	const label = labels[ field.replace( /\[\d+\]/g, '[]' ) ];
	if ( label !== undefined ) {
		const item = /^([a-z_]+)\[(\d+)\]/.exec( field );
		const list = item ? labels[ `${ item[ 1 ] }[]` ] : undefined;
		return item && list !== undefined
			? `${ list.replace( '%d', String( Number( item[ 2 ] ) + 1 ) ) }: ${ label }`
			: label;
	}
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
