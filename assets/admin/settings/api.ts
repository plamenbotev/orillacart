import apiFetch from '@wordpress/api-fetch';

/** The shop settings as GET /orillacart/v1/settings returns them. */
export interface Settings {
	currency: string;
	price_decimals: number;
	weight_unit: string;
	dimension_unit: string;
	weight_units: string[];
	dimension_units: string[];
}

/** The fields PUT /orillacart/v1/settings accepts; every one is required. */
export type SettingsInput = Pick<
	Settings,
	'currency' | 'price_decimals' | 'weight_unit' | 'dimension_unit'
>;

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

const PATH = '/orillacart/v1/settings';

export function fetchSettings(): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH } );
}

export function saveSettings( input: SettingsInput ): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH, method: 'PUT', data: input } );
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
